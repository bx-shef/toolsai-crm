<?php declare(strict_types=1);

/**
 * Заглушки для тестов установщика, переноса и доведения: агенты ядра (CAgent), время в формате
 * сайта, регистрация модуля, пользовательские поля, подставной порт переноса в памяти и
 * подставное соединение с базой, которое запоминает SQL.
 *
 * Подключается ПОСЛЕ tests/stub/autoload.php: общие заглушки ядра лежат там.
 */

use Shef\ToolsAiCrm\Main\MigrationPort;

if(!class_exists('CModule'))
{
	class CModule
	{
	}
}

if(!defined('SM_VERSION'))
{
	define('SM_VERSION', '26.700.0');
}

// region Ядро: агенты, время, регистрация ////
if(!class_exists('CAgent'))
{
	/** Агенты в памяти: ровно то, что зовёт модуль. Фильтр по NAME — точный (ядро отдаёт его в LIKE). */
	class CAgent
	{
		/** @var array<int, array<string, mixed>> */
		public static array $agents = [];
		public static int $nextId = 1;

		public static function reset(): void
		{
			static::$agents = [];
			static::$nextId = 1;
		}

		public static function GetList(array $order = [], array $filter = []): object
		{
			$rows = array_values(array_filter(
				static::$agents,
				static fn(array $row): bool => (!isset($filter['MODULE_ID']) || $row['MODULE_ID'] === $filter['MODULE_ID'])
					&& (!isset($filter['NAME']) || $row['NAME'] === $filter['NAME'])
			));

			return new class($rows)
			{
				public function __construct(private array $rows)
				{
				}

				public function Fetch(): array|false
				{
					return array_shift($this->rows) ?? false;
				}
			};
		}

		public static function AddAgent(string $name, string $module = '', string $period = 'N', int $interval = 86400, string $datecheck = '', string $active = 'Y', string $nextExec = '', int $sort = 100): int|false
		{
			foreach(static::$agents as $row)
			{
				if($row['NAME'] === $name && $row['MODULE_ID'] === $module)
				{
					return false;
				}
			}
			$id = static::$nextId++;
			static::$agents[$id] = [
				'ID' => $id,
				'NAME' => $name,
				'MODULE_ID' => $module,
				'AGENT_INTERVAL' => $interval,
				'NEXT_EXEC' => $nextExec !== '' ? $nextExec : ConvertTimeStamp(time(), 'FULL'),
				'ACTIVE' => $active,
			];

			return $id;
		}

		public static function Update(int $id, array $fields): bool
		{
			if(!isset(static::$agents[$id]))
			{
				return false;
			}
			static::$agents[$id] = array_merge(static::$agents[$id], $fields);

			return true;
		}

		public static function Delete(int $id): bool
		{
			unset(static::$agents[$id]);

			return true;
		}

		public static function RemoveAgent(string $name, string $module = ''): bool
		{
			foreach(static::$agents as $id => $row)
			{
				if($row['NAME'] === $name && $row['MODULE_ID'] === $module)
				{
					unset(static::$agents[$id]);
				}
			}

			return true;
		}

		public static function RemoveModuleAgents(string $module): bool
		{
			foreach(static::$agents as $id => $row)
			{
				if($row['MODULE_ID'] === $module)
				{
					unset(static::$agents[$id]);
				}
			}

			return true;
		}
	}
}

if(!function_exists('ConvertTimeStamp'))
{
	function ConvertTimeStamp(int $timestamp, string $type = 'SHORT'): string
	{
		return date('d.m.Y H:i:s', $timestamp);
	}
}

if(!function_exists('MakeTimeStamp'))
{
	function MakeTimeStamp(string $value): int|false
	{
		$date = DateTime::createFromFormat('d.m.Y H:i:s', $value);

		return $date === false ? false : $date->getTimestamp();
	}
}

/** Регистрация модуля: что звали. */
final class ModuleLog
{
	/** @var list<string> */
	public static array $calls = [];
}

if(!function_exists('RegisterModule'))
{
	function RegisterModule(string $id): void
	{
		ModuleLog::$calls[] = 'register '.$id;
	}

	function UnRegisterModule(string $id): void
	{
		ModuleLog::$calls[] = 'unregister '.$id;
	}
}

if(!isset($GLOBALS['CACHE_MANAGER']))
{
	$GLOBALS['CACHE_MANAGER'] = new class
	{
		public int $cleaned = 0;

		public function CleanAll(): void
		{
			$this->cleaned++;
		}
	};
}
// endregion ////

// region Пользовательские поля ////
if(!class_exists('CUserTypeEntity'))
{
	/** Поля в памяти: GetList/GetByID/Add/Update/Delete и журнал вызовов. */
	class CUserTypeEntity
	{
		/** @var array<int, array<string, mixed>> */
		public static array $fields = [];
		/** @var list<string> */
		public static array $calls = [];

		public static function reset(): void
		{
			static::$fields = [];
			static::$calls = [];
		}

		/** Положить поле, как будто оно уже есть на портале (от shef.toolsai). */
		public static function seed(string $entityId, string $fieldName, array $labels = []): int
		{
			$id = count(static::$fields) + 1;
			static::$fields[$id] = ['ID' => $id, 'ENTITY_ID' => $entityId, 'FIELD_NAME' => $fieldName] + $labels;

			return $id;
		}

		public static function GetList(array $order = [], array $filter = []): object
		{
			$rows = array_values(array_filter(
				static::$fields,
				static fn(array $row): bool => (!isset($filter['ENTITY_ID']) || $row['ENTITY_ID'] === $filter['ENTITY_ID'])
					&& (!isset($filter['FIELD_NAME']) || $row['FIELD_NAME'] === $filter['FIELD_NAME'])
			));

			return new class($rows)
			{
				public function __construct(private array $rows)
				{
				}

				public function Fetch(): array|false
				{
					return array_shift($this->rows) ?? false;
				}
			};
		}

		public static function GetByID(int $id): array|false
		{
			return static::$fields[$id] ?? false;
		}

		public function Add(array $fields): int
		{
			static::$calls[] = 'add '.$fields['ENTITY_ID'].' '.$fields['FIELD_NAME'];
			$id = count(static::$fields) + 1;
			static::$fields[$id] = ['ID' => $id] + $fields;

			return $id;
		}

		public function Update(int $id, array $fields): bool
		{
			static::$calls[] = 'update '.$id;
			static::$fields[$id] = array_merge(static::$fields[$id] ?? [], $fields);

			return true;
		}

		public function Delete(int $id): bool
		{
			static::$calls[] = 'delete '.$id;
			unset(static::$fields[$id]);

			return true;
		}
	}
}
// endregion ////

// region Порт переноса в памяти ////
/**
 * Порт переноса, в котором таблицы, настройки и агенты — массивы. Копия повторяет смысл
 * INSERT IGNORE: ключ уже занят — строку не вставляет и не меняет. Поломки для тестов:
 *   - lossy — копия молча теряет каждую вторую строку (сверка обязана это заметить);
 *   - busyPolls — старый прогон «идёт» столько опросов;
 *   - failOn — метод, который бросает исключение.
 */
final class MemoryPort implements MigrationPort
{
	/** @var array<string, array{columns: list<string>, rows: list<array<string, mixed>>}> */
	public array $tables = [];
	/** @var array<string, array<string, string>> */
	public array $options = [];
	/** @var array<string, array<int, string>> */
	public array $agents = [];
	/** @var list<string> */
	public array $unregistered = [];
	/** @var list<int> */
	public array $deletedAgents = [];
	/** @var list<string> журнал вызовов, по порядку */
	public array $log = [];
	/** @var list<array{string, int, int}> пакеты копии: таблица, от, до */
	public array $batches = [];
	public bool $lossy = false;
	public int $busyPolls = 0;
	public ?string $failOn = null;
	public int $clock = 1700000000;
	public int $slept = 0;

	public function addTable(string $name, array $columns, array $rows = []): void
	{
		$this->tables[$name] = ['columns' => $columns, 'rows' => array_values($rows)];
	}

	/** @return list<array<string, mixed>> */
	public function rows(string $table): array
	{
		return $this->tables[$table]['rows'] ?? [];
	}

	public function tableExists(string $table): bool
	{
		return isset($this->tables[$table]);
	}

	public function columns(string $table): array
	{
		return array_map('strtoupper', $this->tables[$table]['columns'] ?? []);
	}

	/** @return list<array<string, mixed>> строки источника по условию префикса, по ID */
	private function selected(string $table, ?string $prefix): array
	{
		$rows = array_values(array_filter(
			$this->tables[$table]['rows'] ?? [],
			static fn(array $row): bool => $prefix === null || str_starts_with((string)$row['CODE'], $prefix)
		));
		usort($rows, static fn(array $a, array $b): int => $a['ID'] <=> $b['ID']);

		return $rows;
	}

	public function batchEnd(string $table, ?string $codePrefix, int $fromId, int $limit): ?int
	{
		$this->fail('batchEnd');
		$ids = array_values(array_filter(
			array_map(static fn(array $row): int => (int)$row['ID'], $this->selected($table, $codePrefix)),
			static fn(int $id): bool => $id >= $fromId
		));

		return $ids === [] ? null : $ids[min($limit, count($ids)) - 1];
	}

	public function copyRange(string $source, string $target, array $columns, string $key, ?string $codePrefix, int $fromId, int $toId): int
	{
		$this->fail('copyRange');
		$this->log[] = 'copy '.$source;
		$this->batches[] = [$source, $fromId, $toId];
		$inserted = 0;
		$n = 0;
		foreach($this->selected($source, $codePrefix) as $row)
		{
			if($row['ID'] < $fromId || $row['ID'] > $toId)
			{
				continue;
			}
			if($this->lossy && ++$n % 2 === 0)
			{
				continue;
			}
			// INSERT IGNORE: ключ занят — пропуск
			foreach($this->tables[$target]['rows'] as $existing)
			{
				if($existing[$key] === $row[$key])
				{
					continue 2;
				}
			}
			if(in_array('ID', $columns, true))
			{
				foreach($this->tables[$target]['rows'] as $existing)
				{
					if($existing['ID'] === $row['ID'])
					{
						continue 2;
					}
				}
			}
			$new = [];
			foreach($columns as $column)
			{
				$new[$column] = $row[$column] ?? null;
			}
			if($key === 'CODE' && !in_array('ID', $columns, true))
			{
				// ID выдаёт приёмник
				$ids = array_map(static fn(array $r): int => (int)$r['ID'], $this->tables[$target]['rows']);
				$new['ID'] = ($ids === [] ? 0 : max($ids)) + 1;
			}
			$this->tables[$target]['rows'][] = $new;
			$inserted++;
		}

		return $inserted;
	}

	public function compare(string $source, string $target, string $key, ?string $codePrefix): array
	{
		$this->fail('compare');
		$sum = static fn($value): int => $key === 'CODE' ? crc32((string)$value) : (int)$value;
		$count = $missing = $sumSource = $sumTarget = 0;
		foreach($this->selected($source, $codePrefix) as $row)
		{
			$count++;
			$sumSource += $sum($row[$key]);
			$found = false;
			foreach($this->tables[$target]['rows'] ?? [] as $existing)
			{
				if($existing[$key] === $row[$key])
				{
					$found = true;
					break;
				}
			}
			$found ? $sumTarget += $sum($row[$key]) : $missing++;
		}

		return ['source' => $count, 'missing' => $missing, 'sumSource' => (string)$sumSource, 'sumTarget' => (string)$sumTarget];
	}

	public function options(string $moduleId): array
	{
		$this->fail('options');

		return $this->options[$moduleId] ?? [];
	}

	public function setOption(string $moduleId, string $name, string $value): void
	{
		$this->log[] = 'set '.$moduleId.' '.$name;
		$this->options[$moduleId][$name] = $value;
	}

	public function deleteOption(string $moduleId, string $name): void
	{
		$this->log[] = 'delete option '.$moduleId.' '.$name;
		unset($this->options[$moduleId][$name]);
	}

	public function agents(string $moduleId): array
	{
		return $this->agents[$moduleId] ?? [];
	}

	public function deleteAgent(int $agentId): void
	{
		$this->fail('deleteAgent');
		$this->log[] = 'delete agent '.$agentId;
		$this->deletedAgents[] = $agentId;
		foreach($this->agents as $module => $list)
		{
			unset($this->agents[$module][$agentId]);
		}
	}

	public function unregisterEvent(string $fromModule, string $event, string $toModule, string $class, string $method): void
	{
		$this->log[] = 'unregister '.$fromModule.':'.$event;
		$this->unregistered[] = [$fromModule, $event, $toModule, $class, $method];
	}

	public function oldRunInProgress(): bool
	{
		if($this->busyPolls > 0)
		{
			$this->busyPolls--;

			return true;
		}

		return false;
	}

	public function sleep(int $seconds): void
	{
		$this->slept += $seconds;
	}

	public function now(): int
	{
		return $this->clock;
	}

	private function fail(string $method): void
	{
		if($this->failOn === $method)
		{
			throw new \RuntimeException('сбой '.$method);
		}
	}
}
// endregion ////

// region Соединение, запоминающее SQL ////
/** Соединение для Main\CoreMigrationPort: запоминает запросы, отвечает заранее заданными строками. */
final class SqlRecorder
{
	/** @var list<string> */
	public array $queries = [];
	/** @var list<array|null> ответы query() по очереди */
	public array $answers = [];
	public int $affected = 0;
	/** @var array<string, list<string>> */
	public array $columns = [];
	/** @var list<string> */
	public array $tables = [];

	public function isTableExists(string $table): bool
	{
		return in_array($table, $this->tables, true);
	}

	public function getTableFields(string $table): array
	{
		return array_flip($this->columns[$table] ?? []);
	}

	public function queryExecute(string $sql): void
	{
		$this->queries[] = preg_replace('/\s+/', ' ', trim($sql));
		// созданная таблица на месте: повторный init() её не создаёт
		if(preg_match('/^\s*CREATE TABLE (\S+)/', $sql, $m) === 1)
		{
			$this->tables[] = $m[1];
		}
	}

	public function getAffectedRowsCount(): int
	{
		return $this->affected;
	}

	public function query(string $sql): object
	{
		$this->queries[] = preg_replace('/\s+/', ' ', trim($sql));
		$row = array_shift($this->answers);

		return new class($row)
		{
			public function __construct(private readonly ?array $row)
			{
			}

			public function fetch(): array|false
			{
				return $this->row ?? false;
			}
		};
	}

	public function getSqlHelper(): object
	{
		return new class
		{
			public function forSql(string $value): string
			{
				return addslashes($value);
			}
		};
	}
}
// endregion ////

// region Базовый тип поля ядра ////
// Только то, что нужно AckType: getUserTypeDescription() — метод, на который подписано ядро (OnUserTypeBuildList).
if(!class_exists(\Bitrix\Main\UserField\Types\StringType::class))
{
	eval(<<<'PHP'
	namespace Bitrix\Main\UserField\Types
	{
		abstract class BaseType
		{
			public static function getUserTypeDescription(): array
			{
				return [];
			}
		}

		class StringType extends BaseType {}
	}
	PHP);
}
// endregion ////
