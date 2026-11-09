<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Analysis;

use Bitrix\Crm\ActivityBindingTable;
use Bitrix\Crm\ActivityTable;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Shef\ToolsAiCrm\Chat\DialogSource;
use Shef\ToolsAi\Chat\Model\ChatAssessmentTable;
use Shef\ToolsAiCrm\Deal\CallInsights;
use Shef\ToolsAi\Email\Model\EmailTable;

/**
 * Касания записи для единой истории (1.45.0, #34) — штатными API и из
 * сохранённых результатов модуля. Только по одной записи; права проверяет
 * вызывающий (RecordHistory — пользователь, анализ — служебный агент).
 *
 *   дела записи               -> ActivityBindingTable + ActivityTable (звонки, письма, чаты, прочие дела)
 *   длительность звонка       -> voximplant StatisticTable (CRM_ACTIVITY_ID); нет модуля — без длительности
 *   резюме и оценка звонка    -> b_crm_ai_queue и b_crm_ai_quality_assessment (как ContextBuilder, CallInsights)
 *   суть и оценка письма      -> EmailTable модуля (SUMMARY — входящее, REVIEW — исходящее)
 *   суть и оценка чата        -> ChatAssessmentTable модуля
 *   комментарии ленты         -> TimelineTable (TYPE_ID = комментарий) + TimelineBindingTable
 *   смены стадий              -> DealStageHistoryTable / LeadStatusHistoryTable / EntityStageHistoryTable
 *
 * Сбой одного источника — без него, история не рвётся.
 */
final class TimelineSource
{
	/** Сколько последних дел и комментариев разбирать. */
	public const ACTIVITIES_LIMIT = 100;
	public const COMMENTS_LIMIT = 30;
	public const STAGES_LIMIT = 30;
	/** Сколько символов описания прочего дела брать. */
	private const NOTE_LENGTH = 600;

	/**
	 * @param int $ignoreAuthorId дела этого автора (служебный пользователь агента) — не касания клиента
	 * @return list<array> сырые касания для Timeline::render()
	 */
	public static function collect(EntityType $type, int $id, int $ignoreAuthorId = 0): array
	{
		if($id <= 0 || !Loader::includeModule('crm'))
		{
			return [];
		}

		$touches = [];
		foreach([
			static fn(): array => static::activities($type->entityTypeId, $id, $ignoreAuthorId),
			static fn(): array => static::comments($type->entityTypeId, $id),
			static fn(): array => static::stages($type, $id),
		] as $source)
		{
			try
			{
				array_push($touches, ...$source());
			}
			catch(\Throwable)
			{
				// Источник недоступен (нет таблицы, другая версия crm) — история без него
			}
		}

		return $touches;
	}

	/** @return list<array> */
	private static function activities(int $entityTypeId, int $id, int $ignoreAuthorId): array
	{
		$ids = array_map('intval', array_column(ActivityBindingTable::getList([
			'select' => ['ACTIVITY_ID'],
			'filter' => ['=OWNER_TYPE_ID' => $entityTypeId, '=OWNER_ID' => $id],
			'order' => ['ACTIVITY_ID' => 'DESC'],
			'limit' => self::ACTIVITIES_LIMIT,
		])->fetchAll(), 'ACTIVITY_ID'));
		if($ids === [])
		{
			return [];
		}
		$rows = ActivityTable::getList([
			'select' => ['ID', 'CREATED', 'TYPE_ID', 'DIRECTION', 'SUBJECT', 'DESCRIPTION', 'PROVIDER_ID', 'AUTHOR_ID'],
			'filter' => ['@ID' => $ids],
		])->fetchAll();

		$calls = $emails = $chats = [];
		foreach($rows as $row)
		{
			match(true)
			{
				(int)$row['TYPE_ID'] === \CCrmActivityType::Call => $calls[] = (int)$row['ID'],
				(int)$row['TYPE_ID'] === \CCrmActivityType::Email => $emails[] = (int)$row['ID'],
				(string)$row['PROVIDER_ID'] === DialogSource::PROVIDER_ID => $chats[] = (int)$row['ID'],
				default => null,
			};
		}
		$callInfo = static::safe(static fn(): array => static::callInfo($calls));
		$durations = static::safe(static fn(): array => static::durations($calls));
		$emailInfo = static::safe(static fn(): array => static::emailInfo($emails));
		$chatInfo = static::safe(static fn(): array => static::chatInfo($chats));

		$touches = [];
		foreach($rows as $row)
		{
			$activityId = (int)$row['ID'];
			$at = $row['CREATED'] instanceof \Bitrix\Main\Type\DateTime ? $row['CREATED']->getTimestamp() : 0;
			$direction = match((int)$row['DIRECTION'])
			{
				\CCrmActivityDirection::Incoming => Timeline::DIRECTION_IN,
				\CCrmActivityDirection::Outgoing => Timeline::DIRECTION_OUT,
				default => '',
			};
			if(in_array($activityId, $calls, true))
			{
				$touches[] = ['kind' => Timeline::KIND_CALL, 'at' => $at, 'direction' => $direction, 'duration' => $durations[$activityId] ?? null] + ($callInfo[$activityId] ?? []);
			}
			elseif(in_array($activityId, $emails, true))
			{
				if(($emailInfo[$activityId]['client'] ?? true) === false)
				{
					continue; // спам, автоответ — не касание клиента
				}
				$touches[] = ['kind' => Timeline::KIND_EMAIL, 'at' => $at, 'direction' => $direction, 'subject' => (string)$row['SUBJECT']] + ($emailInfo[$activityId] ?? []);
			}
			elseif(in_array($activityId, $chats, true))
			{
				$touches[] = ['kind' => Timeline::KIND_CHAT, 'at' => $at, 'subject' => (string)$row['SUBJECT']] + ($chatInfo[$activityId] ?? []);
			}
			elseif($ignoreAuthorId <= 0 || (int)$row['AUTHOR_ID'] !== $ignoreAuthorId)
			{
				$touches[] = ['kind' => Timeline::KIND_ACTIVITY, 'at' => $at, 'subject' => (string)$row['SUBJECT'], 'note' => mb_substr(trim(strip_tags((string)$row['DESCRIPTION'])), 0, self::NOTE_LENGTH)];
			}
		}

		return $touches;
	}

	/**
	 * Резюме, оценка и невыполненные пункты звонков (как ContextBuilder::getCallNotes()).
	 *
	 * @param int[] $ids
	 * @return array<int, array{summary?: string, score?: int, failed?: list<string>}>
	 */
	private static function callInfo(array $ids): array
	{
		if($ids === [])
		{
			return [];
		}
		$list = implode(',', array_map('intval', $ids));
		$connection = Application::getConnection();
		$info = [];
		// От старых к новым: последнее успешное задание перекрывает
		$result = $connection->query('
			SELECT ENTITY_ID, TYPE_ID, RESULT FROM b_crm_ai_queue
			WHERE ENTITY_TYPE_ID = '.\CCrmOwnerType::Activity.' AND ENTITY_ID IN ('.$list.')
				AND TYPE_ID IN (1, 2, 4) AND EXECUTION_STATUS = \'SUCCESS\'
			ORDER BY ID ASC
		');
		while($row = $result->fetch())
		{
			$id = (int)$row['ENTITY_ID'];
			match((int)$row['TYPE_ID'])
			{
				1 => $info[$id]['transcript'] = CallInsights::parseTranscript($row['RESULT']),
				2 => $info[$id]['summary'] = CallInsights::parseSummary($row['RESULT']),
				default => $info[$id]['failed'] = CallInsights::parseFailed($row['RESULT']),
			};
		}
		$result = $connection->query('SELECT ACTIVITY_ID, ASSESSMENT FROM b_crm_ai_quality_assessment WHERE ACTIVITY_ID IN ('.$list.') ORDER BY ID ASC');
		while($row = $result->fetch())
		{
			$info[(int)$row['ACTIVITY_ID']]['score'] = (int)$row['ASSESSMENT'];
		}

		return $info;
	}

	/** @return array<int, int> ID дела звонка => секунд разговора */
	private static function durations(array $ids): array
	{
		if($ids === [] || !Loader::includeModule('voximplant'))
		{
			return [];
		}
		$durations = [];
		foreach(\Bitrix\Voximplant\StatisticTable::getList([
			'select' => ['CRM_ACTIVITY_ID', 'CALL_DURATION'],
			'filter' => ['@CRM_ACTIVITY_ID' => $ids],
		])->fetchAll() as $row)
		{
			$durations[(int)$row['CRM_ACTIVITY_ID']] = (int)$row['CALL_DURATION'];
		}

		return $durations;
	}

	/** @return array<int, array{summary?: string, score?: int, client?: bool}> */
	private static function emailInfo(array $ids): array
	{
		if($ids === [] || !Application::getConnection()->isTableExists(EmailTable::getTableName()))
		{
			return [];
		}
		$info = [];
		foreach(EmailTable::getList([
			'select' => ['ACTIVITY_ID', 'MODE', 'STATUS', 'IS_CLIENT', 'SUMMARY', 'SCORE'],
			'filter' => ['@ACTIVITY_ID' => $ids],
			'order' => ['ID' => 'ASC'],
		])->fetchAll() as $row)
		{
			$id = (int)$row['ACTIVITY_ID'];
			if($row['IS_CLIENT'] === 'N')
			{
				$info[$id]['client'] = false;
			}
			if($row['STATUS'] !== EmailTable::STATUS_DONE)
			{
				continue;
			}
			if((string)$row['SUMMARY'] !== '')
			{
				$info[$id]['summary'] = (string)$row['SUMMARY'];
			}
			if($row['MODE'] === EmailTable::MODE_REVIEW && $row['SCORE'] !== null)
			{
				$info[$id]['score'] = (int)$row['SCORE'];
			}
		}

		return $info;
	}

	/** @return array<int, array{summary?: string, score?: int}> */
	private static function chatInfo(array $ids): array
	{
		if($ids === [] || !Application::getConnection()->isTableExists(ChatAssessmentTable::getTableName()))
		{
			return [];
		}
		$info = [];
		foreach(ChatAssessmentTable::getList([
			'select' => ['ACTIVITY_ID', 'SUMMARY', 'SCORE'],
			'filter' => ['@ACTIVITY_ID' => $ids, '=STATUS' => ChatAssessmentTable::STATUS_DONE],
			'order' => ['ID' => 'ASC'],
		])->fetchAll() as $row)
		{
			$info[(int)$row['ACTIVITY_ID']] = array_filter(['summary' => (string)$row['SUMMARY'], 'score' => $row['SCORE'] !== null ? (int)$row['SCORE'] : null], static fn($v): bool => $v !== null && $v !== '');
		}

		return $info;
	}

	/** Комментарии ленты записи (TimelineType::COMMENT). @return list<array> */
	private static function comments(int $entityTypeId, int $id): array
	{
		$ids = array_map('intval', array_column(\Bitrix\Crm\Timeline\Entity\TimelineBindingTable::getList([
			'select' => ['OWNER_ID'],
			'filter' => ['=ENTITY_TYPE_ID' => $entityTypeId, '=ENTITY_ID' => $id],
			'order' => ['OWNER_ID' => 'DESC'],
			'limit' => 300,
		])->fetchAll(), 'OWNER_ID'));
		if($ids === [])
		{
			return [];
		}
		$touches = [];
		foreach(\Bitrix\Crm\Timeline\Entity\TimelineTable::getList([
			'select' => ['CREATED', 'COMMENT'],
			'filter' => ['@ID' => $ids, '=TYPE_ID' => \Bitrix\Crm\Timeline\TimelineType::COMMENT],
			'order' => ['ID' => 'DESC'],
			'limit' => self::COMMENTS_LIMIT,
		])->fetchAll() as $row)
		{
			$text = trim(strip_tags(preg_replace('/\[[^\]]+\]/u', '', (string)$row['COMMENT'])));
			if($text !== '')
			{
				$touches[] = ['kind' => Timeline::KIND_COMMENT, 'at' => $row['CREATED'] instanceof \Bitrix\Main\Type\DateTime ? $row['CREATED']->getTimestamp() : 0, 'note' => mb_substr($text, 0, self::NOTE_LENGTH)];
			}
		}

		return $touches;
	}

	/** Смены стадий: у сделки, лида и элемента смарт-процесса — своя таблица истории ядра. @return list<array> */
	private static function stages(EntityType $type, int $id): array
	{
		[$table, $filter, $field] = match(true)
		{
			$type->entityTypeId === \CCrmOwnerType::Deal => [\Bitrix\Crm\History\Entity\DealStageHistoryTable::class, ['=OWNER_ID' => $id], 'STAGE_ID'],
			$type->entityTypeId === \CCrmOwnerType::Lead => [\Bitrix\Crm\History\Entity\LeadStatusHistoryTable::class, ['=OWNER_ID' => $id], 'STATUS_ID'],
			default => [\Bitrix\Crm\History\Entity\EntityStageHistoryTable::class, ['=OWNER_TYPE_ID' => $type->entityTypeId, '=OWNER_ID' => $id], 'STAGE_ID'],
		};
		if(!class_exists($table))
		{
			return [];
		}
		$rows = $table::getList([
			'select' => ['CREATED_TIME', $field],
			'filter' => $filter,
			'order' => ['ID' => 'DESC'],
			'limit' => self::STAGES_LIMIT,
		])->fetchAll();
		$names = static::stageNames($type->entityTypeId);

		return array_map(static fn(array $row): array => [
			'kind' => Timeline::KIND_STAGE,
			'at' => $row['CREATED_TIME'] instanceof \Bitrix\Main\Type\DateTime ? $row['CREATED_TIME']->getTimestamp() : 0,
			'subject' => $names[(string)$row[$field]] ?? (string)$row[$field],
		], $rows);
	}

	/** @return array<string, string> код стадии => название, по всем воронкам типа */
	private static function stageNames(int $entityTypeId): array
	{
		$factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory($entityTypeId);
		if($factory === null)
		{
			return [];
		}
		$names = [];
		$categories = $factory->isCategoriesSupported() ? array_map(static fn($c): int => (int)$c->getId(), $factory->getCategories()) : [0];
		foreach($categories as $categoryId)
		{
			foreach($factory->getStages($categoryId) as $stage)
			{
				$names[(string)$stage->getStatusId()] = (string)$stage->getName();
			}
		}

		return $names;
	}

	private static function safe(callable $read): array
	{
		try
		{
			return $read();
		}
		catch(\Throwable)
		{
			return [];
		}
	}
}
