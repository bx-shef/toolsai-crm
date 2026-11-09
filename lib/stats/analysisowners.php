<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Stats;

use Shef\ToolsAiCrm\Analysis\EntityType;
use Shef\ToolsAiCrm\Analysis\EntityTypes;

/**
 * Типы записей «Статистики» анализа (1.69.1): сделки, лиды и смарт-процессы
 * с профилем. До 1.69.1 блоки «Анализ сделок» и «Оценка работы менеджеров»
 * считали только сделки (ENTITY_TYPE_ID = 2, join b_crm_deal) — анализ лидов
 * и смарт-процессов в «Статистику» не попадал (стенд 2026-10-27).
 *
 * Ответственный записи — из её таблицы: LEFT JOIN по каждому типу с условием
 * на тип и первичный ключ (индекс), COALESCE по ним. Чистые функции — кроме
 * tables(); analysismatrix_test.php.
 */
final class AnalysisOwners
{
	/** Таблица записи — только своя CRM: имя уходит в SQL как есть. */
	private const TABLE_PATTERN = '/^b_crm_[a-z0-9_]+$/';

	/**
	 * Типы с таблицами: сделка, лид и смарт-процессы из $entityTypeIds
	 * (типы профилей анализа). Сбой фабрики — тип без таблицы не попадает.
	 *
	 * @param int[] $entityTypeIds
	 * @return array<int, string> entityTypeId => таблица
	 */
	public static function tables(array $entityTypeIds): array
	{
		$tables = [EntityType::DEAL => 'b_crm_deal', EntityType::LEAD => 'b_crm_lead'];
		$dynamic = EntityTypes::getDynamic();
		foreach(array_unique(array_map('intval', $entityTypeIds)) as $entityTypeId)
		{
			if(!isset($dynamic[$entityTypeId]))
			{
				continue;
			}
			try
			{
				$dataClass = \Bitrix\Crm\Service\Container::getInstance()->getFactory($entityTypeId)?->getDataClass();
				$table = $dataClass !== null ? (string)$dataClass::getTableName() : '';
			}
			catch(\Throwable)
			{
				$table = '';
			}
			if($table !== '')
			{
				$tables[$entityTypeId] = $table;
			}
		}

		return static::filter($tables);
	}

	/**
	 * @param array<int, string> $tables
	 * @return array<int, string> только целые ID типов и таблицы CRM
	 */
	/**
	 * Типы записей для итогов анализа: сделки и лиды всегда, смарт-процессы — с профилем
	 * или с проверками. Один источник для отчётов и MCP (1.73.0). Таблицы — уже через quote().
	 */
	public static function typesSql(string $profileTable, string $checkTable): string
	{
		return 'SELECT ENTITY_TYPE_ID FROM '.$profileTable.' UNION SELECT DISTINCT ENTITY_TYPE_ID FROM '.$checkTable;
	}

	public static function filter(array $tables): array
	{
		$result = [];
		foreach($tables as $entityTypeId => $table)
		{
			if((int)$entityTypeId > 0 && is_string($table) && preg_match(static::TABLE_PATTERN, $table) === 1)
			{
				$result[(int)$entityTypeId] = $table;
			}
		}

		return $result;
	}

	/**
	 * Условие «тип — один из» без псевдонима: `ENTITY_TYPE_ID IN (2,1)`. Нет типов — сделки.
	 * Столбец — первым: страница статистики приставляет псевдоним спереди ('c.'.$scope).
	 */
	public static function scope(array $tables): string
	{
		$ids = array_keys(static::filter($tables)) ?: [EntityType::DEAL];

		return 'ENTITY_TYPE_ID IN ('.implode(',', $ids).')';
	}

	/** LEFT JOIN таблиц записей к строкам $alias (ENTITY_TYPE_ID, ENTITY_ID). */
	public static function joins(array $tables, string $alias): string
	{
		$sql = '';
		foreach(static::filter($tables) as $entityTypeId => $table)
		{
			$sql .= sprintf(' LEFT JOIN %1$s o%2$d ON %3$s.ENTITY_TYPE_ID = %2$d AND o%2$d.ID = %3$s.ENTITY_ID', $table, $entityTypeId, $alias);
		}

		return $sql;
	}

	/** Ответственный записи из joins(): COALESCE(o2.ASSIGNED_BY_ID, …, 0). */
	public static function owner(array $tables): string
	{
		$columns = array_map(static fn(int $id): string => 'o'.$id.'.ASSIGNED_BY_ID', array_keys(static::filter($tables)));

		return 'COALESCE('.implode(', ', [...$columns, '0']).')';
	}
}
