<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Deal;

use Bitrix\Crm\ActivityBindingTable;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\History\Entity\DealStageHistoryTable;
use Bitrix\Crm\Service\Container as CrmContainer;
use Bitrix\Main\Loader;
use Shef\ToolsAiCrm\Analysis\EntityType;
use Shef\ToolsAiCrm\Analysis\Timeline;
use Shef\ToolsAiCrm\Analysis\TimelineSource;
use Shef\ToolsAiCrm\Email\EmailSource;
use Shef\ToolsAiCrm\Email\ReplyClock;

/**
 * Сбор фактов о сделке для промпта — из CRM.
 *
 * Данные разбросаны по таблицам:
 *
 *  - карточка              -> фабрика сделок, Service\Container
 *  - дела и звонки         -> ActivityBindingTable + ActivityTable
 *  - откаты по стадиям     -> DealStageHistoryTable, порядок — сортировка стадий
 *
 * Резюме разговоров из BitrixGPT здесь нет сознательно: где ядро их хранит,
 * зависит от версии crm, и опора на это — ещё одна точка хрупкости при
 * обновлениях. Вместо них — описания последних дел: то, что менеджер
 * написал сам, и транскрипт, если распознавание его туда положило.
 *
 * С 1.9.0 — сборка сводки общего блока анализа: тот же код собирает и лид,
 * и элемент смарт-процесса (EntityType). Отличия — возможности типа, а не
 * ветка «если сделка»: откаты по стадиям — EntityType::$stageRollbacks
 * (история стадий есть у сделки, DealStageHistoryTable); поля, которых у
 * записи нет (воронка, сумма, клиент — Item::hasField()), — пустые. Тип
 * клиента — у любой записи с компанией и контактом.
 */
final class ContextBuilder implements FactsSourceInterface
{
	/** Сколько последних дел класть в промпт. */
	private const NOTES_LIMIT = 5;
	private const NOTE_LENGTH = 300;
	/** Сколько последних дел разбирать вообще. */
	private const ACTIVITIES_LIMIT = 200;
	/** Сколько последних звонков описывать резюме и оценкой. */
	private const CALLS_LIMIT = 3;
	/** Сколько резюме последних входящих писем класть в сводку (1.7.0). */
	private const EMAILS_LIMIT = 2;

	/**
	 * @param int $ignoreAuthorId дела этого автора не считаются активностью —
	 *     служебный пользователь, от которого агент сам ставит дела. Иначе
	 *     дело менеджеру «оживляло» бы мёртвую сделку на ACTIVE_DAYS.
	 *     В счёт запланированных/просроченных они идут как обычно.
	 */
	private readonly EntityType $type;

	public function __construct(private readonly int $ignoreAuthorId = 0, ?EntityType $type = null)
	{
		$this->type = $type ?? EntityType::deal();
	}

	/** Дело создал AI: SETTINGS ядра CRM (массив или сериализованная строка) с IS_AI_CREATED. */
	public static function isAiCreated(mixed $settings): bool
	{
		if(is_string($settings) && $settings !== '')
		{
			$settings = @unserialize($settings, ['allowed_classes' => false]);
		}

		return is_array($settings) && ($settings['IS_AI_CREATED'] ?? false) === true;
	}

	/**
	 * Может ли незавершённое дело считаться запланированной работой (1.69.1).
	 * Нет: письмо (входящее ждёт ответа — это «Клиент ждёт ответа», ReplyClock;
	 * исходящее CRM пишет завершённым), входящий звонок (ядро оставляет его
	 * незавершённым до обработки) и чат открытой линии. Иначе необработанное
	 * обращение клиента выглядело для шкалы как «у менеджера есть дело», и дело
	 * менеджеру не ставилось (стенд 2026-10-27: риск 95, «есть запланированные дела»).
	 * Запланированный исходящий звонок, встреча, задача, «дело» — да.
	 * Только запланированные: просроченным необработанное обращение остаётся
	 * намеренно — срок прошёл, а клиенту не ответили, это сигнал для шкалы.
	 */
	public static function isPlannable(int $typeId, int $direction, string $providerId): bool
	{
		if($typeId === \CCrmActivityType::Email || $providerId === \Shef\ToolsAiCrm\Chat\DialogSource::PROVIDER_ID)
		{
			return false;
		}

		return !($typeId === \CCrmActivityType::Call && $direction === \CCrmActivityDirection::Incoming);
	}

	public function build(int $dealId): DealFacts
	{
		if(!Loader::includeModule('crm'))
		{
			throw new \RuntimeException('Модуль crm не установлен');
		}

		$factory = CrmContainer::getInstance()->getFactory($this->type->entityTypeId);
		$item = $factory?->getItem($dealId);

		if($item === null)
		{
			throw new \RuntimeException($this->type->notFoundText($dealId));
		}
		// Поля, которых у типа нет (сумма, клиент, воронка), — пустые.
		$has = static fn(string $field): bool => $item->hasField($field);
		// Воронка записи — для названия стадии; воронка профиля — 0, если у типа воронок нет:
		// у смарт-процесса без воронок запись всё равно несёт воронку по умолчанию (например 7),
		// а профиль сохранён с 0 — по ней ProfilePicker профиль не нашёл бы (как и Candidates).
		$itemCategoryId = $has('CATEGORY_ID') ? (int)$item->getCategoryId() : 0;
		$categoryId = static::profileCategoryId($this->type, $itemCategoryId);

		$now = time();
		$created = $item->getCreatedTime();
		$activities = $this->getActivities($dealId);

		$lastActivity = 0;
		$callsTotal = 0;
		$callsIncoming = 0;
		$outgoingWithoutAnswer = 0;
		$seenIncoming = false;
		$openActivities = 0;
		$aiPlanned = 0;
		$overdueActivities = 0;
		$oldestOverdueAt = null;
		$nearestPlannedAt = null;
		$notes = [];
		$calls = [];
		$timeline = [];
		$incomingEmails = [];

		// От свежего к старому.
		foreach($activities as $activity)
		{
			$time = $activity['CREATED'] instanceof \Bitrix\Main\Type\DateTime ? $activity['CREATED']->getTimestamp() : 0;
			if($this->ignoreAuthorId <= 0 || (int)($activity['AUTHOR_ID'] ?? 0) !== $this->ignoreAuthorId)
			{
				$lastActivity = max($lastActivity, $time);
			}
			$timeline[(int)$activity['ID']] = ['type' => (int)$activity['TYPE_ID'], 'direction' => (int)$activity['DIRECTION'], 'at' => $time];
			if((int)$activity['TYPE_ID'] === \CCrmActivityType::Email && (int)$activity['DIRECTION'] === \CCrmActivityDirection::Incoming)
			{
				$incomingEmails[] = (int)$activity['ID'];
			}
			$deadline = $activity['DEADLINE'] ?? null;
			$deadline = $deadline instanceof \Bitrix\Main\Type\DateTime ? $deadline->getTimestamp() : null;
			switch(DealFacts::activityState(($activity['COMPLETED'] ?? 'Y') !== 'N', $deadline, $now))
			{
				case DealFacts::ACTIVITY_PLANNED:
					// Входящий звонок, письмо и чат открытой линии ядро держит незавершёнными,
					// пока их не обработали, — это не запланированная работа менеджера (1.69.1)
					if(!static::isPlannable((int)$activity['TYPE_ID'], (int)$activity['DIRECTION'], (string)($activity['PROVIDER_ID'] ?? '')))
					{
						break;
					}
					$openActivities++;
					// Дело поставил AI (BitrixGPT) — помечено ядром в SETTINGS (#271)
					if(static::isAiCreated($activity['SETTINGS'] ?? null))
					{
						$aiPlanned++;
					}
					// «Без срока» ядро хранит 9999 годом — такой срок не ближайший.
					if($deadline !== null && $deadline > 0 && (int)date('Y', $deadline) < 9999)
					{
						$nearestPlannedAt = min($nearestPlannedAt ?? PHP_INT_MAX, $deadline);
					}
					break;
				case DealFacts::ACTIVITY_OVERDUE:
					$overdueActivities++;
					$oldestOverdueAt = min($oldestOverdueAt ?? PHP_INT_MAX, (int)$deadline);
					break;
			}

			if((int)$activity['TYPE_ID'] === \CCrmActivityType::Call)
			{
				$callsTotal++;
				if(count($calls) < self::CALLS_LIMIT)
				{
					$calls[(int)$activity['ID']] = [$time, (int)$activity['DIRECTION'] === \CCrmActivityDirection::Incoming ? 'входящий' : 'исходящий'];
				}
				if((int)$activity['DIRECTION'] === \CCrmActivityDirection::Incoming)
				{
					$callsIncoming++;
					$seenIncoming = true;
				}
				elseif(!$seenIncoming)
				{
					$outgoingWithoutAnswer++;
				}
			}

			$note = trim(strip_tags((string)($activity['DESCRIPTION'] ?? '')));
			if($note !== '' && count($notes) < self::NOTES_LIMIT)
			{
				$notes[] = sprintf(
					'%s, %s: %s',
					$time > 0 ? date('d.m.Y', $time) : '?',
					(string)$activity['SUBJECT'],
					mb_substr(preg_replace('/\s+/u', ' ', $note), 0, self::NOTE_LENGTH)
				);
			}
		}

		$createdAt = $created instanceof \Bitrix\Main\Type\DateTime ? $created->getTimestamp() : $now;
		[$emailNotes, $emailWaitingHours] = $this->getEmailFacts($incomingEmails, $timeline, $now);

		[$timeline, $timelineNoMessaging] = $this->getTimeline($dealId);

		return new DealFacts(
			dealId: $dealId,
			title: (string)$item->getTitle(),
			stage: $has('STAGE_ID') ? $this->getStageName($factory, (string)$item->getStageId(), $itemCategoryId) : '',
			opportunity: $has('OPPORTUNITY') ? (float)$item->getOpportunity() : 0.0,
			currency: $has('CURRENCY_ID') ? (string)$item->getCurrencyId() : '',
			daysSinceCreated: intdiv(max(0, $now - $createdAt), 86400),
			daysSinceLastActivity: intdiv(max(0, $now - ($lastActivity ?: $createdAt)), 86400),
			stageRollbacks: $this->type->stageRollbacks ? $this->countRollbacks($factory, $dealId, $categoryId) : 0,
			callsTotal: $callsTotal,
			callsIncoming: $callsIncoming,
			outgoingWithoutAnswer: $outgoingWithoutAnswer,
			recentNotes: $notes,
			categoryId: $categoryId,
			assignedById: (int)$item->getAssignedById(),
			openActivities: $openActivities,
			clientType: $has('COMPANY_ID') && $has('CONTACT_ID') ? static::getClientType($item) : null,
			overdueActivities: $overdueActivities,
			oldestOverdueAt: $oldestOverdueAt,
			callNotes: $this->getCallNotes($calls),
			emailNotes: $emailNotes,
			emailWaitingHours: $emailWaitingHours,
			noun: $this->type->noun,
			leadClient: $this->type->isLead() ? static::getLeadClientType($item) : null,
			nearestPlannedAt: $nearestPlannedAt,
			lastActivityAt: $lastActivity ?: $createdAt,
			timeline: $timeline,
			timelineNoMessaging: $timelineNoMessaging,
			stageId: $has('STAGE_ID') ? (string)$item->getStageId() : '',
			stageSemantic: $has('STAGE_ID') ? $this->getStageSemantic($factory, (string)$item->getStageId(), $this->type->hasCategories ? $itemCategoryId : null) : '',
			stageMovedAt: $has('MOVED_TIME') && $item->get('MOVED_TIME') instanceof \Bitrix\Main\Type\DateTime ? $item->get('MOVED_TIME')->getTimestamp() : null,
			aiPlannedActivities: $aiPlanned,
			conversation: $this->getConversation($dealId),
		);
	}

	/**
	 * Единая история записи (1.45.0, #34): звонки, письма, чаты, дела,
	 * комментарии и смены стадий одной лентой (TimelineSource, Timeline).
	 * Сбой — пусто: сводка тогда прежняя, блоками.
	 */
	/** @return array{0: string, 1: string} лента и лента без содержания писем и чатов (1.67.3) */
	private function getTimeline(int $id): array
	{
		try
		{
			$touches = TimelineSource::collect($this->type, $id, $this->ignoreAuthorId);

			return [Timeline::render($touches), Timeline::render(Timeline::withoutMessaging($touches))];
		}
		catch(\Throwable)
		{
			return ['', ''];
		}
	}

	/**
	 * Тексты последних писем и чатов (1.67.0, ConversationSource): вместо
	 * отдельных агентов «Письма» и «Чаты». Сбой — пусто, анализ не рвём.
	 */
	private function getConversation(int $id): string
	{
		try
		{
			return ConversationSource::collect($this->type->entityTypeId, $id);
		}
		catch(\Throwable)
		{
			return '';
		}
	}

	/**
	 * Тип клиента лида (1.11.0): компания, контакт и множественные контакты
	 * лида (Item::FIELD_NAME_CONTACT_IDS, crm/lib/Service/Factory/Lead.php —
	 * setMultipleIds), «Название компании» (Item\Lead::FIELD_NAME_COMPANY_TITLE).
	 * Правила — LeadClientType::resolve().
	 */
	public static function getLeadClientType(\Bitrix\Crm\Item $item): LeadClientType
	{
		$get = static fn(string $field): mixed => $item->hasField($field) ? $item->get($field) : null;
		$contactIds = $get('CONTACT_IDS');

		return LeadClientType::resolve(
			(int)$get('COMPANY_ID'),
			(int)$get('CONTACT_ID'),
			is_array($contactIds) ? $contactIds : [],
			is_string($get('COMPANY_TITLE')) ? $get('COMPANY_TITLE') : '',
		);
	}

	/**
	 * Резюме, оценка и «не клиент» по последним звонкам — из заданий
	 * BitrixGPT (b_crm_ai_queue) и оценок (b_crm_ai_quality_assessment).
	 * Разбор и форма — CallInsights. Сбой — без звонков, анализ не рвём.
	 *
	 * @param array<int, array{0: int, 1: string}> $calls ID дела => [время, направление]
	 * @return string[]
	 */
	private function getCallNotes(array $calls): array
	{
		if($calls === [])
		{
			return [];
		}

		$ids = implode(',', array_map('intval', array_keys($calls)));
		$summary = $failed = $notClient = $score = [];
		try
		{
			$connection = \Bitrix\Main\Application::getConnection();
			// От старых к новым: последнее успешное задание перекрывает.
			$result = $connection->query('
				SELECT ENTITY_ID, TYPE_ID, RESULT
				FROM b_crm_ai_queue
				WHERE ENTITY_TYPE_ID = '.\CCrmOwnerType::Activity.' AND ENTITY_ID IN ('.$ids.')
					AND TYPE_ID IN (2, 4, 9) AND EXECUTION_STATUS = \'SUCCESS\'
				ORDER BY ID ASC
			');
			while($row = $result->fetch())
			{
				$id = (int)$row['ENTITY_ID'];
				match((int)$row['TYPE_ID'])
				{
					2 => $summary[$id] = CallInsights::parseSummary($row['RESULT']),
					4 => $failed[$id] = CallInsights::parseFailed($row['RESULT']),
					9 => $notClient[$id] = CallInsights::parseNotClient($row['RESULT']),
					default => null,
				};
			}
			$result = $connection->query('
				SELECT ACTIVITY_ID, ASSESSMENT
				FROM b_crm_ai_quality_assessment
				WHERE ACTIVITY_ID IN ('.$ids.')
				ORDER BY ID ASC
			');
			while($row = $result->fetch())
			{
				$score[(int)$row['ACTIVITY_ID']] = (int)$row['ASSESSMENT'];
			}
		}
		catch(\Throwable)
		{
			return [];
		}

		$notes = [];
		foreach($calls as $id => [$time, $direction])
		{
			$note = CallInsights::formatCall($time, $direction, $summary[$id] ?? '', $score[$id] ?? null, $failed[$id] ?? [], $notClient[$id] ?? null);
			if($note !== '')
			{
				$notes[] = $note;
			}
		}

		return $notes;
	}

	/**
	 * Письма в сводке (1.7.0): резюме последних входящих, которые разобрал
	 * модуль (Email\EmailSource::getDealNotes()), и сколько часов клиент
	 * ждёт ответа (Email\ReplyClock::waitingSince()). Письма, которые
	 * модуль признал не клиентскими (спам, автоответ), ответа не ждут. Сбой
	 * таблицы писем — без резюме, анализ не рвём.
	 *
	 * @param int[] $incomingIds входящие письма сделки, от свежего к старому
	 * @param array<int, array{type: int, direction: int, at: int}> $timeline дела сделки по ID
	 * @return array{0: string[], 1: ?int}
	 */
	private function getEmailFacts(array $incomingIds, array $timeline, int $now): array
	{
		$notes = [];
		try
		{
			$ids = array_slice($incomingIds, 0, 50);
			$notes = EmailSource::getDealNotes($ids, self::EMAILS_LIMIT);
			foreach(EmailSource::getNotClientIds($ids) as $id)
			{
				unset($timeline[$id]);
			}
		}
		catch(\Throwable)
		{
		}

		$since = ReplyClock::waitingSince(array_values($timeline));

		return [$notes, $since !== null ? ReplyClock::hours($since, $now) : null];
	}

	/**
	 * @return array[] от свежего к старому
	 */
	private function getActivities(int $dealId): array
	{
		$ids = array_column(ActivityBindingTable::getList([
			'select' => ['ACTIVITY_ID'],
			'filter' => [
				'=OWNER_TYPE_ID' => $this->type->entityTypeId,
				'=OWNER_ID' => $dealId,
			],
			'order' => ['ACTIVITY_ID' => 'DESC'],
			'limit' => self::ACTIVITIES_LIMIT,
		])->fetchAll(), 'ACTIVITY_ID');

		if($ids === [])
		{
			return [];
		}

		return ActivityTable::getList([
			'select' => ['ID', 'CREATED', 'TYPE_ID', 'DIRECTION', 'PROVIDER_ID', 'SUBJECT', 'DESCRIPTION', 'COMPLETED', 'DEADLINE', 'AUTHOR_ID', 'SETTINGS'],
			'filter' => ['@ID' => $ids],
			'order' => ['CREATED' => 'DESC', 'ID' => 'DESC'],
		])->fetchAll();
	}

	/**
	 * Тип клиента сделки — штатно: ClientTypeResolver ядра по компании, нет
	 * компании — по контакту (решение владельца, 1.2.0; выбор —
	 * ClientType::pickClient()). Перевод в коды — как у речевой аналитики
	 * (AssessmentClientTypeResolver::resolveByIdentifier()), см. ClientType.
	 * Сбой или нет клиента — null: подойдёт только профиль «любой».
	 * Им же подбирают профиль (и старшего) письма — Email\EmailSource.
	 */
	public static function getClientType(\Bitrix\Crm\Item $item): ?int
	{
		if(!class_exists(\Bitrix\Crm\Client\ClientTypeResolver::class))
		{
			return null;
		}

		$client = ClientType::pickClient((int)$item->getCompanyId(), (int)$item->getContactId());
		$identifier = match($client[0] ?? null)
		{
			ClientType::CLIENT_COMPANY => new \Bitrix\Crm\ItemIdentifier(\CCrmOwnerType::Company, $client[1]),
			ClientType::CLIENT_CONTACT => new \Bitrix\Crm\ItemIdentifier(\CCrmOwnerType::Contact, $client[1]),
			default => null,
		};
		if($identifier === null)
		{
			return null;
		}

		try
		{
			$type = (new \Bitrix\Crm\Client\ClientTypeResolver())->getType($identifier);
		}
		catch(\Throwable)
		{
			return null;
		}

		return ClientType::fromCoreName($type->name);
	}

	/**
	 * Воронка, по которой подбирается профиль: у типа без воронок (лид, смарт-процесс с
	 * выключенными воронками) — всегда 0, как у профиля и у выборки кандидатов.
	 */
	public static function profileCategoryId(EntityType $type, int $itemCategoryId): int
	{
		return $type->hasCategories ? $itemCategoryId : 0;
	}

	private function getStageName(\Bitrix\Crm\Service\Factory $factory, string $stageId, int $categoryId): string
	{
		foreach($factory->getStages($categoryId) as $stage)
		{
			if($stage->getStatusId() === $stageId)
			{
				return (string)$stage->getName();
			}
		}

		return $stageId;
	}

	/** Семантика стадии (#408): 'S', 'F' или '' — по стадиям воронки фабрики. */
	private function getStageSemantic(\Bitrix\Crm\Service\Factory $factory, string $stageId, ?int $categoryId): string
	{
		foreach($factory->getStages($categoryId) as $stage)
		{
			if($stage->getStatusId() === $stageId)
			{
				return \Shef\ToolsAiCrm\Deal\Profile::parseOutcome((string)$stage->getSemantics());
			}
		}

		return '';
	}

	/**
	 * Сколько раз сделка шла по стадиям назад: переход в стадию с меньшей
	 * сортировкой, чем у предыдущей.
	 */
	private function countRollbacks(\Bitrix\Crm\Service\Factory $factory, int $dealId, int $categoryId): int
	{
		$sort = [];
		foreach($factory->getStages($categoryId) as $stage)
		{
			$sort[$stage->getStatusId()] = (int)$stage->getSort();
		}

		$history = DealStageHistoryTable::getList([
			'select' => ['STAGE_ID'],
			'filter' => ['=OWNER_ID' => $dealId],
			'order' => ['CREATED_TIME' => 'ASC', 'ID' => 'ASC'],
		])->fetchAll();

		// Стадия не из текущего направления (сделку переносили) сравнивать
		// не с чем — она пропускается, а не считается сортировкой 0.
		return static::countBackward(array_values(array_filter(
			array_map(static fn(array $row): ?int => $sort[$row['STAGE_ID']] ?? null, $history),
			static fn(?int $value): bool => $value !== null
		)));
	}

	/**
	 * @param int[] $sorts сортировки стадий в порядке переходов
	 */
	public static function countBackward(array $sorts): int
	{
		$count = 0;
		$previous = null;
		foreach($sorts as $sort)
		{
			if($previous !== null && $sort < $previous)
			{
				$count++;
			}
			$previous = $sort;
		}

		return $count;
	}
}
