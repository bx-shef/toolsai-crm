<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Analysis;

use Bitrix\Crm\ActivityBindingTable;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\Item;
use Bitrix\Crm\PhaseSemantics;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Type\DateTime;
use Shef\ToolsAiCrm\Analysis\Model\EntityCheckTable;
use Shef\ToolsAiCrm\Deal\Profile;
use Shef\ToolsAiCrm\Deal\ProfilePicker;

/**
 * Кандидаты на анализ записи любого типа — сделки, лида, смарт-процесса.
 * С 1.9.1 и сделки выбираются здесь (до этого — свой
 * Agent\DealHealthAgent::getCandidates() с тем же смыслом).
 *
 * Через фабрику типа (Service\Container::getFactory()): таблица —
 * Factory::getDataClass(), имена полей — Factory::getEntityFieldNameByMap()
 * (у лида семантика стадии — STATUS_SEMANTIC_ID, дата создания — DATE_CREATE;
 * у сделки дата создания — DATE_CREATE; у смарт-процесса — STAGE_SEMANTIC_ID
 * и CREATED_TIME; crm/lib/Service/Factory.php:158).
 *
 *   - не финальная стадия (семантика не S/F); закрытые с итогом S/F — только
 *     в воронке с профилем на этот итог (#408): перешли в стадию за последние
 *     CLOSED_WINDOW_DAYS дней и после перехода не проверялись (getClosedSql());
 *   - у типа с флагом CLOSED (EntityType::$closedFlag, сделка) — CLOSED = 'N';
 *   - воронка с включённым профилем (у типа без воронок — одна, 0);
 *   - не проверялась или проверялась раньше REANALYZE_DAYS (самый короткий
 *     среди профилей воронки; LEFT JOIN shef_toolsai_entity_check по типу и ID);
 *   - жива: создана позже ACTIVE_DAYS (самый мягкий срок воронки) или есть
 *     дело не от служебного пользователя позже этой границы; точная
 *     проверка по профилю — DealFacts::isAlive();
 *   - сначала непроверенные, потом самые давние.
 */
final class Candidates
{
	/**
	 * @param Profile[] $profiles включённые профили этого типа
	 * @return int[]
	 */
	public static function get(EntityType $type, array $profiles, int $limit, int $ignoreAuthorId): array
	{
		$days = ProfilePicker::getCategoryReanalyzeDays($profiles);
		$outcomes = ProfilePicker::getCategoryOutcomes($profiles);
		if(($days === [] && $outcomes === []) || $limit <= 0)
		{
			return [];
		}

		$factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory($type->entityTypeId);
		if($factory === null)
		{
			return [];
		}
		$field = static fn(string $common): string => $factory->getEntityFieldNameByMap($common);
		$activeDays = ProfilePicker::getCategoryActiveDays($profiles);

		$outcomes = $factory->isStagesEnabled() ? $outcomes : [];
		$dataClass = $factory->getDataClass();
		$byCategory = ['LOGIC' => 'OR'];
		foreach($outcomes as $categoryId => $list)
		{
			// Закрытые с итогом профиля (#408) — один раз после закрытия, недавние.
			$byCategory[] = ['@ID' => new SqlExpression(static::getClosedSql($type, $dataClass::getTableName(), $field, $type->hasCategories ? $categoryId : null, $list))];
		}
		// Открытые — по профилям не на итог (getCategoryReanalyzeDays() их не считает)
		foreach($days as $categoryId => $reanalyzeDays)
		{
			$condition = [
				'LOGIC' => 'AND',
				[
					'LOGIC' => 'OR',
					['=CHECK.ID' => null],
					['<CHECK.CHECKED_AT' => DateTime::createFromTimestamp(time() - $reanalyzeDays * 86400)],
				],
			];
			if($type->hasCategories)
			{
				$condition['='.$field(Item::FIELD_NAME_CATEGORY_ID)] = $categoryId;
			}
			$alive = (int)($activeDays[$categoryId] ?? 0);
			if($alive > 0)
			{
				$border = DateTime::createFromTimestamp(time() - $alive * 86400);
				$condition[] = [
					'LOGIC' => 'OR',
					['>='.$field(Item::FIELD_NAME_CREATED_TIME) => $border],
					['@ID' => new SqlExpression(static::getAliveSql($type->entityTypeId, $border, $ignoreAuthorId))],
				];
			}
			// Открытые: не финальная стадия; у сделки ещё CLOSED = 'N'.
			if($type->closedFlag)
			{
				$condition['='.$field(Item::FIELD_NAME_CLOSED)] = 'N';
			}
			if($factory->isStagesEnabled())
			{
				$semantic = $field(Item::FIELD_NAME_STAGE_SEMANTIC_ID);
				$condition[] = [
					'LOGIC' => 'OR',
					['='.$semantic => null],
					['!@'.$semantic => [PhaseSemantics::SUCCESS, PhaseSemantics::FAILURE]],
				];
			}
			$byCategory[] = $condition;
		}

		if(count($byCategory) === 1)
		{
			return [];
		}
		$filter = [$byCategory];
		$rows = $dataClass::getList([
			'select' => ['ID'],
			'filter' => $filter,
			'runtime' => [
				new Reference(
					'CHECK',
					EntityCheckTable::class,
					Join::on('this.ID', 'ref.ENTITY_ID')->where('ref.ENTITY_TYPE_ID', $type->entityTypeId),
					['join_type' => 'LEFT']
				),
			],
			'order' => ['CHECK.CHECKED_AT' => 'ASC', 'ID' => 'ASC'],
			'limit' => $limit,
		])->fetchAll();

		return array_map('intval', array_column($rows, 'ID'));
	}

	/** Закрытые записи берутся не старше стольких дней от перехода в стадию итога (#408). */
	public const CLOSED_WINDOW_DAYS = 4;

	/**
	 * Закрытые записи воронки с итогом из $outcomes (#408): перешли в стадию
	 * не раньше CLOSED_WINDOW_DAYS и не проверялись после перехода.
	 *
	 * @param callable(string): string $field имя поля типа по общему
	 * @param string[] $outcomes 'S' / 'F'
	 */
	private static function getClosedSql(EntityType $type, string $table, callable $field, ?int $categoryId, array $outcomes): string
	{
		$helper = Application::getConnection()->getSqlHelper();
		$moved = $helper->quote($field(Item::FIELD_NAME_MOVED_TIME));

		return sprintf(
			"SELECT E.ID FROM %s E LEFT JOIN %s C ON C.ENTITY_TYPE_ID = %d AND C.ENTITY_ID = E.ID WHERE E.%s IN ('%s') AND E.%s >= %s AND (C.ID IS NULL OR C.CHECKED_AT < E.%s)%s",
			$helper->quote($table),
			$helper->quote(EntityCheckTable::getTableName()),
			$type->entityTypeId,
			$helper->quote($field(Item::FIELD_NAME_STAGE_SEMANTIC_ID)),
			implode("','", array_map(static fn(string $s): string => $helper->forSql($s), $outcomes)),
			$moved,
			$helper->convertToDbDateTime(DateTime::createFromTimestamp(time() - static::CLOSED_WINDOW_DAYS * 86400)),
			$moved,
			$categoryId !== null ? ' AND E.'.$helper->quote($field(Item::FIELD_NAME_CATEGORY_ID)).' = '.$categoryId : ''
		);
	}

	/** Записи типа с делом не раньше $border не от служебного пользователя. */
	private static function getAliveSql(int $entityTypeId, DateTime $border, int $ignoreAuthorId): string
	{
		$helper = Application::getConnection()->getSqlHelper();

		return sprintf(
			'SELECT B.OWNER_ID FROM %s B INNER JOIN %s A ON A.ID = B.ACTIVITY_ID WHERE B.OWNER_TYPE_ID = %d AND A.CREATED >= %s%s',
			ActivityBindingTable::getTableName(),
			ActivityTable::getTableName(),
			$entityTypeId,
			$helper->convertToDbDateTime($border),
			$ignoreAuthorId > 0 ? ' AND A.AUTHOR_ID <> '.$ignoreAuthorId : ''
		);
	}

	/**
	 * Типы с включёнными профилями (сделки, лиды, смарт-процессы):
	 * entityTypeId => профили. Чистая функция.
	 *
	 * @param Profile[] $profiles
	 * @return array<int, Profile[]>
	 */
	public static function groupByType(array $profiles): array
	{
		$result = [];
		foreach($profiles as $profile)
		{
			if($profile->enabled)
			{
				$result[$profile->entityTypeId][] = $profile;
			}
		}
		ksort($result);

		return $result;
	}
}
