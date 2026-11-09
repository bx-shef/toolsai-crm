<?php

/**
 * Заглушки ядра Битрикса для тестов.
 *
 * Ровно столько, сколько нужно, чтобы подключить настоящие классы модуля.
 * Логику модуля здесь не повторяем — иначе тест проверял бы заглушку, а не
 * код. Основа — заглушки shef.toolsai (tests/stub/bitrix.php там): настройки,
 * события, менеджер событий, личные настройки пользователя.
 *
 * Блочный синтаксис namespace: в одном файле нужно объявить классы сразу в
 * нескольких пространствах имён.
 */

namespace Bitrix\Main\Config
{
	if(!class_exists(Option::class))
	{
		/** Настройки модуля: значение — строка, как из формы. */
		class Option
		{
			/** @var array<string, array<string, mixed>> */
			public static array $values = [];

			public static function set(string $moduleId, string $name, mixed $value): void
			{
				static::$values[$moduleId][$name] = $value;
			}

			public static function get(string $moduleId, string $name, mixed $default = '', mixed $siteId = false): mixed
			{
				return static::$values[$moduleId][$name] ?? $default;
			}

			/** Как в ядре: без умолчаний default_option.php, не сохраняли — null. */
			public static function getRealValue(string $moduleId, string $name, mixed $siteId = false): ?string
			{
				return isset(static::$values[$moduleId][$name]) ? (string)static::$values[$moduleId][$name] : null;
			}

			/** Как в ядре: с filter['name'] — одна настройка, без — все настройки модуля. */
			public static function delete(string $moduleId, array $filter = []): void
			{
				if(isset($filter['name']))
				{
					unset(static::$values[$moduleId][$filter['name']]);

					return;
				}
				unset(static::$values[$moduleId]);
			}
		}
	}

	if(!class_exists(Configuration::class))
	{
		/** Содержимое .settings.php: отдаёт value ключа, как читает autoload.php. */
		class Configuration
		{
			/** @var array<string, array<string, mixed>> */
			public static array $settings = [];

			public function __construct(private readonly string $moduleId = '') {}

			public static function getInstance(?string $moduleId = null): static
			{
				return new static((string)$moduleId);
			}

			public function get(string $key): mixed
			{
				return static::$settings[$this->moduleId][$key] ?? null;
			}
		}
	}
}

namespace Bitrix\Main
{
	if(!class_exists(EventResult::class))
	{
		/**
		 * Событие: send() зовёт обработчики из EventManager::$handlers и
		 * собирает ответы — как ядро, только без базы.
		 */
		class Event
		{
			/** @var list<mixed> */
			private array $results = [];

			public function __construct(
				private readonly string $moduleId = '',
				private readonly string $type = '',
				private readonly array $parameters = [],
				private readonly mixed $filter = null
			) {}

			public function getModuleId(): string
			{
				return $this->moduleId;
			}

			public function getEventType(): string
			{
				return $this->type;
			}

			public function getParameter(string $key): mixed
			{
				return $this->parameters[$key] ?? null;
			}

			public function getParameters(): array
			{
				return $this->parameters;
			}

			public function send(): void
			{
				foreach(EventManager::$handlers[$this->moduleId][$this->type] ?? [] as $toModule => $handler)
				{
					// Фильтр — как в ядре: по модулю назначения (ключ массива обработчиков).
					if($this->filter !== null && !in_array($toModule, (array)$this->filter, true))
					{
						continue;
					}
					$result = $handler($this);
					if($result !== null && $result !== false)
					{
						$this->results[] = $result instanceof EventResult ? $result : new EventResult(EventResult::UNDEFINED, $result);
					}
				}
			}

			/** @return list<mixed> */
			public function getResults(): array
			{
				return $this->results;
			}
		}

		class Error
		{
			public function __construct(private readonly string $message = '', private readonly int|string $code = 0) {}

			public function getMessage(): string
			{
				return $this->message;
			}
		}

		class EventResult
		{
			public const UNDEFINED = 0;
			public const SUCCESS = 1;
			public const ERROR = 2;

			public function __construct(
				private readonly int $type,
				private readonly mixed $parameters = null,
				private readonly ?string $moduleId = null
			) {}

			public function getType(): int
			{
				return $this->type;
			}

			public function getParameters(): mixed
			{
				return $this->parameters;
			}

			public function getModuleId(): ?string
			{
				return $this->moduleId;
			}
		}

		/** Обработчики в памяти и журнал регистраций. */
		class EventManager
		{
			/** @var array<string, array<string, list<callable>>> */
			public static array $handlers = [];

			/** @var list<array> */
			public static array $registered = [];

			/** @var list<array> */
			public static array $unregistered = [];

			public static function getInstance(): static
			{
				return new static();
			}

			/** Подписки, которые «не встают» (как b_module_to_module, где запись не легла): «модуль:событие». */
			public static array $dropRegistration = [];
			/** События, снятие с которых «не проходит» (сбой посреди шага, Main\Maintenance). */
			public static array $dropUnregistration = [];

			/**
			 * Как ядро: записи с TO_MODULE_ID — ключ массива обработчиков модуля; плюс
			 * поставленные registerEventHandler() — с TO_CLASS и TO_METHOD, как в
			 * b_module_to_module (сверка Main\Maintenance::missingEvents(), #18).
			 */
			/**
			 * Кеш обработчиков, как в ядре (b_module_to_module_v1): включён — findEventHandlers()
			 * читает снимок $loaded; снимок сбрасывает clearLoadedHandlers() и регистрация НОВОЙ
			 * строки, а повтор существующей — нет (INSERT IGNORE, affectedRows = 0;
			 * main/lib/EventManager.php :194, :312). Выключен — читает $registered сразу (#20).
			 */
			public static bool $cache = false;

			/** @var list<array>|null */
			public static ?array $loaded = null;

			public function findEventHandlers(string $moduleId, string $eventType, ?array $filter = null): array
			{
				$list = [];
				foreach(array_keys(static::$handlers[$moduleId][$eventType] ?? []) as $toModule)
				{
					$list[] = ['TO_MODULE_ID' => is_string($toModule) ? $toModule : ''];
				}
				if(static::$cache && static::$loaded === null)
				{
					static::$loaded = static::$registered;
				}
				foreach(static::$cache ? static::$loaded : static::$registered as [$from, $event, $toModule, $class, $method])
				{
					if(strcasecmp($from, $moduleId) === 0 && strcasecmp($event, $eventType) === 0)
					{
						$list[] = ['TO_MODULE_ID' => $toModule, 'TO_CLASS' => $class, 'TO_METHOD' => $method];
					}
				}

				return $list;
			}

			public function clearLoadedHandlers(): void
			{
				static::$loaded = null;
			}

			public function registerEventHandler(string $fromModule, string $event, string $toModule, string $class, string $method, int $sort = 100): void
			{
				if(in_array($fromModule.':'.$event, static::$dropRegistration, true))
				{
					return;
				}
				$row = [$fromModule, $event, $toModule, $class, $method];
				if(static::$cache)
				{
					// INSERT IGNORE: строка уже есть — ничего не меняется, кеш не сбрасывается
					if(in_array($row, static::$registered, true))
					{
						return;
					}
					static::$loaded = null;
				}
				static::$registered[] = $row;
			}

			public function registerEventHandlerCompatible(string $fromModule, string $event, string $toModule, string $class, string $method, int $sort = 100): void
			{
				$this->registerEventHandler($fromModule, $event, $toModule, $class, $method, $sort);
			}

			/** Как таблица b_module_to_module: снимает точное совпадение строк. */
			public function unRegisterEventHandler(string $fromModule, string $event, string $toModule, string $class, string $method): void
			{
				$row = [$fromModule, $event, $toModule, $class, $method];
				static::$unregistered[] = $row;
				if(in_array($fromModule.':'.$event, static::$dropUnregistration, true))
				{
					return;
				}
				static::$registered = array_values(array_filter(static::$registered, static fn(array $r): bool => $r !== $row));
			}
		}
	}
}

namespace Bitrix\Main
{
	if(!class_exists(ModuleManager::class))
	{
		/** Установленные модули и версии — задаёт тест: ['shef.toolsai' => '1.79.0']. */
		class ModuleManager
		{
			/** @var array<string, string> */
			public static array $versions = [];
			/** Задаёт тест: ModuleManager бросает исключение (сбой ядра). */
			public static bool $throw = false;

			public static function isModuleInstalled(string $id): bool { if(static::$throw) { throw new \RuntimeException('сбой'); } return isset(static::$versions[$id]); }
			/** Значение false в $versions — установлен, версии нет. */
			public static function getVersion(string $id): string|false { if(static::$throw) { throw new \RuntimeException('сбой'); } return static::$versions[$id] ?? false; }
			public static function getInstalledModules(): array { return array_map(static fn(): array => [], static::$versions); }
		}
	}
}

namespace Bitrix\Main
{
	if(!class_exists(Context::class))
	{
		/** Текущий запрос: адрес, ajax, параметры — задаёт тест. */
		class Context
		{
			public static string $page = '/';
			/** Адрес как в адресной строке; пусто — $page. */
			public static string $uri = '';
			public static bool $ajax = false;
			/** @var array<string, string> */
			public static array $params = [];

			public static function getCurrent(): static
			{
				return new static();
			}

			public function getRequest(): object
			{
				return new class
				{
					public function getRequestedPage(): string
					{
						return Context::$page;
					}

					public function getRequestUri(): string
					{
						return Context::$uri !== '' ? Context::$uri : Context::$page;
					}

					public function isAjaxRequest(): bool
					{
						return Context::$ajax;
					}

					public function get(string $name): ?string
					{
						return Context::$params[$name] ?? null;
					}
				};
			}
		}
	}
}

namespace Bitrix\Main
{
	if(!class_exists(UserTable::class))
	{
		/** Пользователи — из $GLOBALS['TEST_USERS'] (id => строка); фильтр только =ID / @ID. */
		class UserTable
		{
			public static int $calls = 0;

			public static function getList(array $params = []): object
			{
				static::$calls++;
				$ids = (array)($params['filter']['=ID'] ?? $params['filter']['@ID'] ?? []);
				$rows = array_values(array_filter($GLOBALS['TEST_USERS'] ?? [], static fn(array $r): bool => in_array((int)$r['ID'], array_map('intval', $ids), true)));

				return new class($rows)
				{
					public function __construct(private array $rows) {}

					public function fetch(): array|false
					{
						return array_shift($this->rows) ?? false;
					}
				};
			}
		}
	}
}

namespace Bitrix\Main\Page
{
	if(!class_exists(Asset::class))
	{
		/** Что положили в страницу. */
		class Asset
		{
			/** @var list<string> */
			public static array $strings = [];

			public static function getInstance(): static
			{
				return new static();
			}

			public function addString(string $str): void
			{
				static::$strings[] = $str;
			}
		}
	}
}

namespace Bitrix\Main\Data
{
	if(!class_exists(Cache::class))
	{
		/** Кеш в памяти: ключ — каталог и строка. */
		class Cache
		{
			/** @var array<string, array<string, mixed>> */
			public static array $store = [];
			private string $dir = '';
			private string $key = '';

			public static function createInstance(array $params = []): static
			{
				return new static();
			}

			public function initCache(int $ttl, string $key, string $dir = ''): bool
			{
				$this->dir = $dir;
				$this->key = $key;

				return array_key_exists($key, static::$store[$dir] ?? []);
			}

			public function getVars(): mixed
			{
				return static::$store[$this->dir][$this->key] ?? null;
			}

			public function startDataCache(): bool
			{
				return true;
			}

			public function endDataCache(mixed $vars = false): void
			{
				static::$store[$this->dir][$this->key] = $vars;
			}

			public function cleanDir(string $dir = ''): void
			{
				unset(static::$store[$dir]);
			}
		}
	}
}

namespace Bitrix\Main\Engine
{
	if(!class_exists(Controller::class))
	{
		/** Текущий пользователь контроллера. */
		class CurrentUser
		{
			public function __construct(private readonly ?int $id) {}

			public function getId(): ?int
			{
				return $this->id;
			}
		}

		/** Контроллер: ошибки и текущий пользователь — как в ядре, без жизненного цикла. */
		class Controller
		{
			public static ?int $userId = null;
			/** @var list<\Bitrix\Main\Error> */
			public array $errors = [];

			public function addError(\Bitrix\Main\Error $error): static
			{
				$this->errors[] = $error;

				return $this;
			}

			final public function getCurrentUser(): ?CurrentUser
			{
				return new CurrentUser(static::$userId);
			}
		}
	}
}

namespace Bitrix\Main\Engine\ActionFilter
{
	if(!class_exists(HttpMethod::class))
	{
		class Authentication {}
		class Csrf {}
		class HttpMethod
		{
			public const METHOD_POST = 'POST';

			public function __construct(public readonly array $methods = []) {}
		}
	}
}

namespace Bitrix\Main\Localization
{
	if(!class_exists(Loc::class))
	{
		/** Подписи: отдаёт код — тесту важен не текст, а что его спросили. */
		class Loc
		{
			public static function loadMessages(string $file): void {}

			public static function getMessage(string $code, ?array $replace = null, ?string $language = null): ?string
			{
				return $code;
			}
		}
	}
}

namespace
{
	if(!class_exists('CModule'))
	{
		/** Базовый класс установщика: тестам нужен только сам класс. */
		class CModule {}
	}

	if(!function_exists('UnRegisterModule'))
	{
		/** Снятые модули — для проверки установщика. */
		function UnRegisterModule(string $moduleId): void
		{
			$GLOBALS['UNREGISTERED_MODULES'][] = $moduleId;
		}
	}
	if(!class_exists('CUserOptions'))
	{
		/** Личные настройки пользователей: категория → имя → пользователь → значение. */
		class CUserOptions
		{
			/** @var array<string, array<string, array<int, mixed>>> */
			public static array $values = [];

			public static function GetOption($category, $name, $default = false, $userId = false)
			{
				return static::$values[$category][$name][(int)$userId] ?? $default;
			}

			public static function SetOption($category, $name, $value, $common = false, $userId = false): bool
			{
				static::$values[$category][$name][(int)$userId] = $value;

				return true;
			}

			public static function DeleteOptionsByName($category, $name): bool
			{
				unset(static::$values[$category][$name]);

				return true;
			}

			/** Как ядро: строки b_user_option, VALUE — serialize(). */
			public static function GetList($order = [], $filter = []): object
			{
				$rows = [];
				foreach(static::$values[$filter['CATEGORY'] ?? ''][$filter['NAME'] ?? ''] ?? [] as $userId => $value)
				{
					$rows[] = ['USER_ID' => (string)$userId, 'VALUE' => is_string($value) ? $value : serialize($value)];
				}

				return new class($rows)
				{
					public function __construct(private array $rows) {}

					public function Fetch(): array|false
					{
						return array_shift($this->rows) ?? false;
					}
				};
			}
		}
	}
}

namespace Bitrix\Main
{
	if(!class_exists(Application::class))
	{
		/** Соединение на заглушке: журнал блокировок. */
		class Application
		{
			public static array $locks = [];
			public static bool $busy = false;
		/** Корень сайта для заглушек публичной страницы (Main\PublicPage). */
		public static string $documentRoot = '';

		public static function getDocumentRoot(): string
		{
			return static::$documentRoot;
		}
			public static array $queries = [];
		/** Какие таблицы проверял установщик таблиц (TableInstaller). */
		public static array $tables = [];
			/** Значение в «БД», если отличается от кеша хита. */
			public static ?string $db = null;

			public static function getConnection(): object
			{
				return new class {
					public function lock(string $name, int $timeout = 0): bool { Application::$locks[] = 'lock:'.$name; return !Application::$busy; }
					public function unlock(string $name): bool { Application::$locks[] = 'unlock:'.$name; return true; }
					/** Таблицы ролей «уже есть»: тестам установщика и Maintenance хватает. */
					public function isTableExists(string $name): bool { Application::$tables[] = $name; return true; }
					public function isIndexExists(string $name, array $columns): bool { return true; }
					public function getSqlHelper(): object { return new class { public function forSql(string $v): string { return addslashes($v); } }; }
					/** b_option: общее значение — из «БД» (Option::$db), не из кеша хита (Option::$values). */
					public function query(string $sql): object
					{
						Application::$queries[] = $sql;
						$v = Application::$db ?? (\Bitrix\Main\Config\Option::$values['shef.toolsaicrm']['ANNOUNCE_list'] ?? null);
						return new class($v) { public function __construct(private $v) {} public function fetch() { return $this->v === null ? false : ['VALUE' => $this->v]; } };
					}
				};
			}
		}
	}
}

namespace
{
	if(!class_exists('CUser'))
	{
		/** Текущий пользователь на заглушке: ID и администратор ли. */
		class CUser
		{
			public function __construct(private readonly int $id = 0, private readonly bool $admin = false) {}
			public function IsAuthorized(): bool { return $this->id > 0; }
			public function GetID(): int { return $this->id; }
			public function IsAdmin(): bool { return $this->admin; }
			/** @var array<int, list<int>> группы по ID сотрудника; null — сбой ядра */
			public static ?array $groups = [];
			public static function GetUserGroup(int $id): array { if(static::$groups === null) { throw new \RuntimeException('сбой'); } return static::$groups[$id] ?? [2]; }
		}
	}
	if(!class_exists('CAccess'))
	{
		/** Коды доступа сотрудника: U<id> и то, что задал тест. */
		class CAccess
		{
			/** @var array<int, list<string>> */
			public static array $codes = [];
			public static function GetUserCodesArray(int $userId): array { return static::$codes[$userId] ?? ['U'.$userId]; }
		}
	}
}

namespace Bitrix\Main\ORM\Data
{
	if(!class_exists(DataManager::class))
	{
		/** ORM-сущность на заглушке: тестам нужно имя таблицы, не база. */
		abstract class DataManager
		{
			abstract public static function getTableName(): string;
		}
	}
}

namespace Bitrix\Main
{
	if(!class_exists(Loader::class))
	{
		/** Подключение модулей: установленные — Loader::$modules (по умолчанию нет ни одного). */
		class Loader
		{
			/** @var list<string> */
			public static array $modules = [];

			public static function includeModule(string $moduleId): bool
			{
				return in_array($moduleId, static::$modules, true);
			}
		}
	}
}

namespace Bitrix\Rest\Engine
{
	if(!class_exists(ScopeManager::class))
	{
		/** Кеш списка областей REST (ядро — 7 дней): сколько раз сбрасывали. */
		class ScopeManager
		{
			public static int $cleaned = 0;

			public static function cleanCache(): void
			{
				static::$cleaned++;
			}
		}
	}
}

namespace
{
	if(!class_exists('CAgent'))
	{
		/** Агенты ядра: поставленные [имя, модуль, интервал], снятые по модулю. */
		class CAgent
		{
			/** @var list<array{0: string, 1: string, 2: int}> */
			public static array $agents = [];

			public static function AddAgent(string $name, string $module = '', string $period = 'N', int $interval = 86400): int
			{
				static::$agents[] = [$name, $module, $interval];

				return count(static::$agents);
			}

			public static function RemoveAgent(string $name, string $module = ''): void
			{
				static::$agents = array_values(array_filter(static::$agents, static fn(array $a): bool => !($a[0] === $name && $a[1] === $module)));
			}

			public static function RemoveModuleAgents(string $module): void
			{
				static::$agents = array_values(array_filter(static::$agents, static fn(array $a): bool => $a[1] !== $module));
			}

			public static function GetList(array $order = [], array $filter = []): object
			{
				$rows = array_values(array_filter(static::$agents, static fn(array $a): bool => (!isset($filter['NAME']) || $a[0] === $filter['NAME']) && (!isset($filter['MODULE_ID']) || $a[1] === $filter['MODULE_ID'])));

				return new class($rows) {
					public function __construct(private array $rows) {}

					public function Fetch(): array|false
					{
						$row = array_shift($this->rows);

						return $row === null ? false : ['NAME' => $row[0], 'MODULE_ID' => $row[1]];
					}
				};
			}
		}
	}
}
