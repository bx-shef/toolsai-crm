<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Main;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;

/**
 * Настоящий порт переноса: соединение с базой, Option, CAgent, EventManager, блокировки агентов.
 *
 * Запросы — сырые: таблицы источника — не наши, классов у нас на них нет. Имена таблиц и
 * ключей приходят только из констант Main\Migration, значения — целые числа и префикс CODE
 * (через forSql). Класс тонкий: логика и порядок — в Main\Migration.
 */
final class CoreMigrationPort implements MigrationPort
{
	/** Блокировка старше этого — след умершего процесса, прогон не идёт. */
	public const STALE_LOCK_SECONDS = 10800;

	/** Группы блокировок агентов анализа shef.toolsai: общая и по типам (Constants::CORE_LOCK_GROUP_ANALYSIS_PREFIX). */
	private const OLD_LOCK_GROUP = 'shef.toolsai.dealhealth';

	public function tableExists(string $table): bool
	{
		return Application::getConnection()->isTableExists($table);
	}

	public function columns(string $table): array
	{
		return array_map('strtoupper', array_map('strval', array_keys((array)Application::getConnection()->getTableFields($table))));
	}

	public function batchEnd(string $table, ?string $codePrefix, int $fromId, int $limit): ?int
	{
		$row = Application::getConnection()->query(sprintf(
			'SELECT MAX(t.ID) AS E FROM (SELECT ID FROM %s WHERE ID >= %d%s ORDER BY ID LIMIT %d) t',
			$table,
			$fromId,
			$this->like($codePrefix, ''),
			$limit
		))->fetch();

		return is_array($row) && ($row['E'] ?? null) !== null ? (int)$row['E'] : null;
	}

	public function copyRange(string $source, string $target, array $columns, string $key, ?string $codePrefix, int $fromId, int $toId): int
	{
		$connection = Application::getConnection();
		$list = implode(', ', $columns);
		$select = implode(', ', array_map(static fn(string $column): string => 's.'.$column, $columns));
		$where = sprintf('s.ID BETWEEN %d AND %d', $fromId, $toId).$this->like($codePrefix, 's.');
		if($key === 'CODE')
		{
			$where .= sprintf(' AND NOT EXISTS (SELECT 1 FROM %s d WHERE d.CODE = s.CODE)', $target);
		}
		// IGNORE: строка с таким ключом уже есть — не затираем и не падаем
		$connection->queryExecute(sprintf('INSERT IGNORE INTO %s (%s) SELECT %s FROM %s s WHERE %s', $target, $list, $select, $source, $where));

		return (int)$connection->getAffectedRowsCount();
	}

	public function compare(string $source, string $target, string $key, ?string $codePrefix, array $columns): array
	{
		// Содержимое строки: общие столбцы через «|»; NULL — отдельным знаком, чтобы не сливался с пустой строкой
		$content = static function(string $alias) use ($columns): string
		{
			$parts = array_map(
				static fn(string $column): string => sprintf("IF(%1\$s.%2\$s IS NULL, 'N', CONCAT('V', %1\$s.%2\$s))", $alias, $column),
				$columns
			);

			return "CRC32(CONCAT_WS('|', ".implode(', ', $parts).'))';
		};
		// У приёмника без пары строки нет (d.<ключ> IS NULL): её вклад — NULL, SUM его пропускает
		$row = Application::getConnection()->query(sprintf(
			'SELECT COUNT(*) AS C, COALESCE(SUM(d.%1$s IS NULL), 0) AS M, CAST(COALESCE(SUM(%2$s), 0) AS CHAR) AS SS, CAST(COALESCE(SUM(IF(d.%1$s IS NULL, NULL, %3$s)), 0) AS CHAR) AS SD'
			.' FROM %4$s s LEFT JOIN %5$s d ON d.%1$s = s.%1$s WHERE 1 = 1%6$s',
			$key,
			$content('s'),
			$content('d'),
			$source,
			$target,
			$this->like($codePrefix, 's.')
		))->fetch();

		return [
			'source' => (int)($row['C'] ?? 0),
			'missing' => (int)($row['M'] ?? 0),
			'sumSource' => (string)($row['SS'] ?? '0'),
			'sumTarget' => (string)($row['SD'] ?? '0'),
		];
	}

	public function options(string $moduleId): array
	{
		return array_map('strval', Option::getForModule($moduleId));
	}

	public function setOption(string $moduleId, string $name, string $value): void
	{
		Option::set($moduleId, $name, $value);
	}

	public function deleteOption(string $moduleId, string $name): void
	{
		Option::delete($moduleId, ['name' => $name]);
	}

	public function agents(string $moduleId): array
	{
		$result = [];
		$list = \CAgent::GetList(['ID' => 'ASC'], ['MODULE_ID' => $moduleId]);
		while($row = $list->Fetch())
		{
			$result[(int)$row['ID']] = (string)$row['NAME'];
		}

		return $result;
	}

	public function deleteAgent(int $agentId): void
	{
		\CAgent::Delete($agentId);
	}

	public function unregisterEvent(string $fromModule, string $event, string $toModule, string $class, string $method): void
	{
		EventManager::getInstance()->unRegisterEventHandler($fromModule, $event, $toModule, $class, $method);
	}

	/**
	 * Блокировки агентов — файлы *.lock в каталогах групп (Shef\Options\Main\TempFile\Pid). Группа
	 * общего агента и по типу на каждый тип; свежий файл — прогон идёт.
	 */
	public function oldRunInProgress(): bool
	{
		if(!class_exists(\Shef\Options\Main\TempFile\Pid::class))
		{
			return false;
		}

		$groups = [\Shef\Options\Main\TempFile\Pid::getBasePath(self::OLD_LOCK_GROUP)];
		$root = dirname($groups[0]);
		foreach(glob($root.'/'.Constants::CORE_LOCK_GROUP_ANALYSIS_PREFIX.'*', GLOB_ONLYDIR) ?: [] as $dir)
		{
			$groups[] = $dir;
		}
		foreach($groups as $dir)
		{
			foreach(glob($dir.'/*.lock') ?: [] as $file)
			{
				if(time() - (int)@filemtime($file) < self::STALE_LOCK_SECONDS)
				{
					return true;
				}
			}
		}

		return false;
	}

	public function sleep(int $seconds): void
	{
		sleep($seconds);
	}

	public function now(): int
	{
		return time();
	}

	/** Условие «CODE начинается с префикса» (без префикса — пусто); LIKE-знаки в самом префиксе экранируются. */
	private function like(?string $prefix, string $alias): string
	{
		if($prefix === null)
		{
			return '';
		}
		$escaped = addcslashes($prefix, '%_\\');
		$helper = Application::getConnection()->getSqlHelper();

		return sprintf(" AND %sCODE LIKE '%s%%'", $alias, $helper->forSql($escaped));
	}
}
