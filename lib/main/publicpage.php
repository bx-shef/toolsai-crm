<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Main;

// Адреса и каталоги — из Constants; layout.php читает класс без ядра и автозагрузки
require_once __DIR__.'/constants.php';

/**
 * Заглушки публичных страниц модуля: страница /shef/toolsaicrm/, компонент
 * shef:toolsaicrm.settings в /local/components (ajax.php рядом — права и
 * действия разделов) и робот БП «AI: оценить сейчас» в
 * /bitrix/activities/custom. Канон — Main\PublicPage shef.toolsai.
 *
 * Каталог модуля браузеру недоступен, поэтому снаружи лежит файл в одну
 * строку:
 *
 *   <?php require($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/shef.toolsaicrm/public/page.php');
 *
 * Путь — туда, где модуль стоит НА САМОМ ДЕЛЕ (/bitrix/modules или
 * /local/modules), поэтому заглушку не копируют, а пишут. Пишут установка и
 * Main\Maintenance — на портале модуль обновляют копированием, установщик
 * не запускается. Удаляется только СВОЯ заглушка; чужой файл на её месте
 * не трогается.
 *
 * Класс самодостаточен: установщик подключает его явным require_once.
 */
class PublicPage
{
	/** Модуль, из которого вынесен анализ: его заглушка робота заменяется (legacyModules). */
	public const LEGACY_MODULE_ID = 'shef.toolsai';

	public function __construct(
		/** От корня сайта: /shef/toolsaicrm/index.php */
		public readonly string $file,
		/** От каталога модуля: /public/page.php */
		public readonly string $modulePage,
		/**
		 * Сколько уровней каталогов над заглушкой модуль создаёт сам и
		 * убирает, если они опустели: /shef/toolsaicrm — 2, компонент — 4 (до /local).
		 */
		public readonly int $ownDirs = 0,
		/**
		 * Модули, чья заглушка на этом месте тоже считается «своей» и подлежит замене.
		 * Робот БП до выноса анализа писал shef.toolsai: путь .../shef.toolsai/activities/analyze/...
		 * Без этого прежнюю заглушку заменить нельзя (чужой файл не трогаем), и робот
		 * вёл бы в каталог, которого в shef.toolsai больше нет.
		 *
		 * @var string[]
		 */
		public readonly array $legacyModules = [],
	)
	{
	}

	/**
	 * Порядок важен для удаления: с конца — класс робота раньше описания,
	 * ajax.php раньше компонента; пустые каталоги уходят.
	 *
	 * @return static[]
	 */
	public static function getList(): array
	{
		return [
			new static(Constants::SETTINGS_FILE, Constants::SETTINGS_MODULE_PAGE, 2),
			// /local/components/shef/toolsaicrm.settings: на портале без /local модуль создаёт
			// и /local/components, и /local — и убирает их, если опустели (с php_interface
			// или чужими компонентами каталог не пустой и остаётся)
			new static(Constants::COMPONENT_FILE, Constants::COMPONENT_MODULE_PAGE, 4),
			// ajax.php компонента. Каталог создаёт заглушка компонента выше; удаляется
			// раньше неё, и каталог остаётся пустым
			new static(Constants::COMPONENT_AJAX_FILE, Constants::COMPONENT_AJAX_MODULE_PAGE),
			// Робот «AI: оценить сейчас»: ядро ищет действия в /bitrix/activities/custom.
			// Каталог и класс прежние (shtoolsaianalyzeactivity): на них ссылаются схемы
			// бизнес-процессов. До выноса анализа заглушку писал shef.toolsai — она «своя»
			// и заменяется (LEGACY_MODULE_ID). Каталог робота модуль создаёт сам; custom — тоже,
			// если его нет (2 уровня). Класс удаляется раньше описания: каталог остаётся пустым и уходит
			new static(Constants::ROBOT_ANALYZE_DIR.'/.description.php', Constants::ROBOT_ANALYZE_MODULE_DIR.'/.description.php', 2, [self::LEGACY_MODULE_ID]),
			new static(Constants::ROBOT_ANALYZE_DIR.'/shtoolsaianalyzeactivity.php', Constants::ROBOT_ANALYZE_MODULE_DIR.'/shtoolsaianalyzeactivity.php', 1, [self::LEGACY_MODULE_ID]),
		];
	}

	/**
	 * Положить все заглушки.
	 *
	 * @param bool $withRobot класть и робота БП; нет — перенос данных из shef.toolsai не завершён,
	 *        заглушка робота остаётся прежней (она ведёт в shef.toolsai) и не трогается
	 * @return array<string, bool> заглушка => на месте и своя
	 */
	public static function installAll(string $documentRoot, string $moduleDir, bool $withRobot = true): array
	{
		$report = [];
		foreach(static::getList() as $page)
		{
			if(!$withRobot && str_starts_with($page->file, Constants::ROBOT_ANALYZE_DIR.'/'))
			{
				continue;
			}
			$report[$page->file] = $page->install($documentRoot, $moduleDir);
		}

		return $report;
	}

	/**
	 * Убрать свои заглушки (с конца списка). Заглушки робота БП: если у shef.toolsai есть
	 * переходная заглушка (legacyRobotModuleDir()), они возвращаются на неё, а не удаляются —
	 * схемы бизнес-процессов не остаются со ссылкой в пустоту.
	 *
	 * @return array<string, bool> заглушка => своей больше нет (у возвращённых на shef.toolsai — стоит прежняя)
	 */
	public static function uninstallAll(string $documentRoot, string $moduleDir = ''): array
	{
		$legacyDir = static::legacyRobotModuleDir($documentRoot, $moduleDir);
		$report = [];
		foreach(array_reverse(static::getList()) as $page)
		{
			$report[$page->file] = $legacyDir !== null && $page->legacyModules !== []
				? $page->install($documentRoot, $legacyDir)
				: $page->uninstall($documentRoot, $moduleDir);
		}

		return $report;
	}

	/**
	 * Каталог shef.toolsai с переходной заглушкой робота: на месте и описание
	 * (activities/analyze/.description.php), и класс (shtoolsaianalyzeactivity.php). Ищется рядом
	 * с этим модулем, затем в /bitrix/modules и /local/modules. Нет — null (заглушку робота удалять).
	 */
	public static function legacyRobotModuleDir(string $documentRoot, string $moduleDir): ?string
	{
		if($moduleDir === '')
		{
			return null;
		}
		$root = rtrim(str_replace('\\', '/', $documentRoot), '/');
		$candidates = [
			dirname(rtrim(str_replace('\\', '/', $moduleDir), '/')).'/'.self::LEGACY_MODULE_ID,
			$root.'/bitrix/modules/'.self::LEGACY_MODULE_ID,
			$root.'/local/modules/'.self::LEGACY_MODULE_ID,
		];
		foreach($candidates as $dir)
		{
			if(
				is_file($dir.Constants::ROBOT_ANALYZE_MODULE_DIR.'/.description.php')
				&& is_file($dir.Constants::ROBOT_ANALYZE_MODULE_DIR.'/shtoolsaianalyzeactivity.php')
			)
			{
				return $dir;
			}
		}

		return null;
	}

	public function getTarget(string $documentRoot): string
	{
		return rtrim(str_replace('\\', '/', $documentRoot), '/').$this->file;
	}

	/**
	 * Содержимое заглушки для модуля, лежащего в $moduleDir.
	 */
	public function getContent(string $documentRoot, string $moduleDir): string
	{
		$documentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');
		$page = rtrim(str_replace('\\', '/', $moduleDir), '/').$this->modulePage;

		if($documentRoot !== '' && str_starts_with($page, $documentRoot.'/'))
		{
			return sprintf(
				"<?php require(\$_SERVER['DOCUMENT_ROOT'].'%s');\n",
				addcslashes(substr($page, strlen($documentRoot)), "'\\")
			);
		}

		// путь — в PHP-строку в одинарных кавычках: ' и \ экранируются
		return sprintf("<?php require('%s');\n", addcslashes($page, "'\\"));
	}

	/**
	 * Своя заглушка: та, что модуль написал бы сейчас из $moduleDir (каталог
	 * может называться иначе — клон репозитория), либо require на
	 * .../shef.toolsaicrm<страница модуля> от корня сайта или абсолютным путём.
	 * У робота БП — ещё и на .../shef.toolsai<страница> (заглушка до выноса анализа).
	 */
	public function isOwn(string $content, string $documentRoot = '', string $moduleDir = ''): bool
	{
		if($moduleDir !== '' && $content === $this->getContent($documentRoot, $moduleDir))
		{
			return true;
		}

		$modules = implode('|', array_map(
			static fn(string $module): string => preg_quote($module, '#'),
			[Constants::MODULE_ID, ...$this->legacyModules]
		));
		$pattern = sprintf(
			'#^<\?php require\((?:\$_SERVER\[\'DOCUMENT_ROOT\'\]\.)?\'[^\']*/(?:%s)%s\'\);\s*$#',
			$modules,
			preg_quote($this->modulePage, '#')
		);

		return 1 === preg_match($pattern, $content);
	}

	/**
	 * Положить заглушку. Нет файла — пишет. Своя с другим путём (модуль
	 * переехал) — переписывает. Чужая — не трогает.
	 *
	 * @return bool заглушка на месте и ведёт в этот модуль
	 */
	public function install(string $documentRoot, string $moduleDir): bool
	{
		$target = $this->getTarget($documentRoot);
		$content = $this->getContent($documentRoot, $moduleDir);

		if(is_file($target))
		{
			$current = (string)file_get_contents($target);
			if($current === $content)
			{
				return true;
			}

			if(!$this->isOwn($current, $documentRoot, $moduleDir))
			{
				return false;
			}
		}

		$dir = dirname($target);
		if(
			!is_dir($dir)
			&& $this->ownDirs > 0
			&& is_dir(rtrim(str_replace('\\', '/', $documentRoot), '/'))
		)
		{
			@mkdir($dir, 0755, true);
		}

		if(!is_dir($dir) || !is_writable($dir))
		{
			return false;
		}

		return false !== file_put_contents($target, $content);
	}

	/**
	 * Убрать заглушку — только свою.
	 *
	 * @return bool своей заглушки больше нет
	 */
	public function uninstall(string $documentRoot, string $moduleDir = ''): bool
	{
		$target = $this->getTarget($documentRoot);

		if(!is_file($target))
		{
			return true;
		}

		if(!$this->isOwn((string)file_get_contents($target), $documentRoot, $moduleDir))
		{
			return false;
		}

		if(!unlink($target))
		{
			return false;
		}

		// Свои каталоги — только если опустели: в /local/components/shef
		// могут лежать компоненты других модулей линейки.
		$dir = dirname($target);
		for($level = 0; $level < $this->ownDirs; $level++)
		{
			if(!is_dir($dir) || (scandir($dir) ?: []) !== ['.', '..'] || !@rmdir($dir))
			{
				break;
			}
			$dir = dirname($dir);
		}

		return true;
	}
}
