<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Main;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Bitrix\Main\ModuleManager;
use Shef\ToolsAiCrm\Analysis\Candidates;
use Shef\ToolsAiCrm\Container;

/**
 * Доведение портала без установщика — когда модуль обновили копированием файлов
 * (на порталах так и обновляют: установщик не запускается).
 *
 * Новая подписка из installEvents, новая заглушка, новый столбец таблицы или потерянный
 * агент сами не появятся. Поэтому при первом запуске после смены файлов модуля
 * (ensureOnce() — его зовёт агент доведения раз в 10 минут, Agent\Maintenance) модуль
 * доводит себя: подписки installEvents, таблицы (init() создаёт недостающее и дописывает
 * столбцы), заглушки публичной страницы и робота БП, агенты анализа по включённым профилям.
 * Всё идемпотентно: registerEventHandler ядра — INSERT IGNORE по UNIQUE_ID, агенты — по имени.
 *
 * Перенос данных из shef.toolsai сюда НЕ входит: он тяжёлый и идёт при установке и по кнопке
 * «Проверить и включить» (Main\Setup).
 *
 * До чего довели — опция SYS_eventsversion: «<версия>|<хеш .settings.php>» (stamp()). Одной версии
 * мало: запуск посреди замены файлов (новый version.php, старый .settings.php или его копия в
 * opcache) поставил бы подписки по старому списку и записал бы новую версию. Поэтому файлы
 * читаются мимо opcache (freshInclude()), а шаг events сверяет факт (missingEvents()) и без всех
 * подписок не считается успешным — отметка не пишется. Сбой любого шага — отметку не пишем и
 * пробуем снова не раньше чем через RETRY секунд (SYS_maintenancefail). Исключений наружу нет.
 */
final class Maintenance
{
	public const OPTION_VERSION = 'SYS_eventsversion';
	/** Время последнего сбоя: повтор не чаще RETRY секунд, а не на каждом запуске. */
	public const OPTION_FAILED = 'SYS_maintenancefail';
	public const RETRY = 600;
	/** Агент доведения: ensureOnce() без хита — после обновления копированием. Повторено литералом в install/index.php. */
	public const AGENT_NAME = '\\Shef\\ToolsAiCrm\\Agent\\Maintenance::run();';
	public const AGENT_INTERVAL = 600;

	private static bool $checked = false;

	/** Раз за запуск: версия отстала — довести. */
	public static function ensureOnce(): void
	{
		if(self::$checked)
		{
			return;
		}
		self::$checked = true;
		try
		{
			$stamp = static::stamp(dirname(__DIR__, 2));
			if(
				!static::isNeeded((string)Option::get(Constants::MODULE_ID, self::OPTION_VERSION, ''), $stamp)
				|| time() - (int)Option::get(Constants::MODULE_ID, self::OPTION_FAILED, 0) < self::RETRY
			)
			{
				return;
			}
			$report = static::ensure((string)Application::getDocumentRoot(), dirname(__DIR__, 2));
			if(!in_array(false, $report, true))
			{
				Option::set(Constants::MODULE_ID, self::OPTION_VERSION, $stamp);
				Option::delete(Constants::MODULE_ID, ['name' => self::OPTION_FAILED]);
			}
			else
			{
				Option::set(Constants::MODULE_ID, self::OPTION_FAILED, (string)time());
			}
		}
		catch(\Throwable)
		{
		}
	}

	/**
	 * Довести портал до версии модуля. Шаги независимы: сбой одного не мешает другим;
	 * версия пишется, только когда успешны все.
	 *
	 * @return array<string, bool> шаг => успешно
	 */
	public static function ensure(string $documentRoot, string $moduleDir, ?Setup $setup = null): array
	{
		// Агент доведения: стоит — ничего, нет (портал обновлён копированием) — поставить
		static::installAgent();
		$setup ??= new Setup(Container::getConfig());
		$report = [];
		$steps = [
			'events' => static function() use ($moduleDir, $setup): void
			{
				$result = $setup->ensureEvents($moduleDir);
				if(!$result['ok'])
				{
					throw new \RuntimeException($result['message']);
				}
			},
			'tables' => static function() use ($setup): void
			{
				$result = $setup->ensureTables();
				if(!$result['ok'])
				{
					throw new \RuntimeException($result['message']);
				}
			},
			'agents' => static function() use ($setup): void
			{
				// Профили не прочитались — исключение: пустой список снял бы агентов всех типов
				$enabled = Candidates::groupByType($setup->readProfiles());
				$result = $setup->ensureAnalysisAgents(array_keys($enabled));
				if(!$result['ok'])
				{
					throw new \RuntimeException($result['message']);
				}
			},
		];
		foreach($steps as $name => $step)
		{
			try
			{
				$step();
				$report[$name] = true;
			}
			catch(\Throwable)
			{
				$report[$name] = false;
			}
		}
		foreach(PublicPage::installAll($documentRoot, $moduleDir) as $file => $ok)
		{
			$report['file '.$file] = $ok;
		}

		return $report;
	}

	/**
	 * Поставить агент доведения, если его нет (идемпотентно). Модуль не установлен
	 * (удаление на том же хите) — не ставим. Исключений наружу нет.
	 */
	public static function installAgent(): void
	{
		try
		{
			if(!class_exists('\\CAgent') || !ModuleManager::isModuleInstalled(Constants::MODULE_ID))
			{
				return;
			}
			// Фильтр NAME ядро может отдать в LIKE, где «\» — экранирование: имя сверяем сами
			$agents = \CAgent::GetList([], ['MODULE_ID' => Constants::MODULE_ID]);
			while($agent = $agents->Fetch())
			{
				if(ltrim((string)($agent['NAME'] ?? ''), '\\') === ltrim(self::AGENT_NAME, '\\'))
				{
					return;
				}
			}
			\CAgent::AddAgent(self::AGENT_NAME, Constants::MODULE_ID, 'N', self::AGENT_INTERVAL);
		}
		catch(\Throwable)
		{
		}
	}

	/** Нужно ли: сохранённая отметка не та, что у файлов модуля. */
	public static function isNeeded(string $saved, string $current): bool
	{
		return $current !== '' && $saved !== $current;
	}

	/**
	 * Отметка файлов модуля: «<версия>|<12 знаков md5 .settings.php>». md5_file читает диск,
	 * не opcache. Версии нет — '' (доводить нечего).
	 */
	public static function stamp(string $moduleDir): string
	{
		$version = static::fileVersion($moduleDir);
		if($version === '')
		{
			return '';
		}
		$settings = $moduleDir.'/.settings.php';

		return $version.'|'.(is_file($settings) ? substr((string)md5_file($settings), 0, 12) : '');
	}

	/**
	 * installEvents из .settings.php модуля — свежим чтением.
	 *
	 * @return list<array>
	 */
	public static function installEvents(string $moduleDir): array
	{
		$settings = static::freshInclude($moduleDir.'/.settings.php');

		return is_array($settings) ? array_values((array)($settings['installEvents']['value'] ?? [])) : [];
	}

	/**
	 * Каких подписок из списка нет у ядра: «модуль:событие → класс::метод».
	 * Сравнение — как хранит b_module_to_module (TO_MODULE_ID, TO_CLASS, TO_METHOD),
	 * класс без ведущего «\» и без учёта регистра.
	 *
	 * @param list<array> $events
	 * @return list<string>
	 */
	public static function missingEvents(array $events): array
	{
		$manager = EventManager::getInstance();
		$norm = static fn(string $class): string => strtolower(ltrim($class, '\\'));
		$missing = [];
		foreach($events as $event)
		{
			$found = false;
			foreach($manager->findEventHandlers((string)$event['from']['module'], (string)$event['from']['event']) as $handler)
			{
				if(
					strcasecmp((string)($handler['TO_MODULE_ID'] ?? ''), (string)$event['to']['module']) === 0
					&& $norm((string)($handler['TO_CLASS'] ?? '')) === $norm((string)$event['to']['class'])
					&& strcasecmp((string)($handler['TO_METHOD'] ?? ''), (string)$event['to']['function']) === 0
				)
				{
					$found = true;
					break;
				}
			}
			if(!$found)
			{
				$missing[] = $event['from']['module'].':'.$event['from']['event'].' → '.$event['to']['class'].'::'.$event['to']['function'];
			}
		}

		return $missing;
	}

	/**
	 * Версия из install/version.php чтением файла, без include: на каждом запуске, поэтому
	 * без opcache. Формат строки — как пишет линейка: 'VERSION' => 'x.y.z'.
	 */
	public static function fileVersion(string $moduleDir): string
	{
		$file = $moduleDir.'/install/version.php';
		$text = is_file($file) ? (string)file_get_contents($file) : '';

		return preg_match("/'VERSION'\s*=>\s*'([^']+)'/", $text, $m) === 1 ? $m[1] : '';
	}

	/** include файла, сбросив его копию в opcache: запуск мог прийти посреди замены файлов. Только при доведении. */
	public static function freshInclude(string $file): mixed
	{
		if(!is_file($file))
		{
			return null;
		}
		if(function_exists('opcache_invalidate'))
		{
			@opcache_invalidate($file, true);
		}

		return include $file;
	}

	/** Подписки из installEvents — как InstallEvents() установщика, без снятия. */
	public static function registerEvents(array $events): void
	{
		$manager = EventManager::getInstance();
		foreach($events as $event)
		{
			$method = !empty($event['isCompatible']) ? 'registerEventHandlerCompatible' : 'registerEventHandler';
			$manager->{$method}(
				$event['from']['module'],
				$event['from']['event'],
				$event['to']['module'],
				$event['to']['class'],
				$event['to']['function'],
				(int)($event['to']['sort'] ?? 100)
			);
		}
	}

	/** Версия модуля из install/version.php. */
	public static function moduleVersion(string $moduleDir): string
	{
		$arModuleVersion = [];
		$file = $moduleDir.'/install/version.php';
		if(is_file($file))
		{
			include $file;
		}

		return (string)($arModuleVersion['VERSION'] ?? '');
	}

	/** Для тестов: снова проверить в этом же процессе. */
	public static function reset(): void
	{
		self::$checked = false;
	}
}
