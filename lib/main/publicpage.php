<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Main;

/**
 * Заглушки публичной страницы модуля (1.8.0): /shef/toolsaicrm/ и
 * компонент shef:toolsaicrm.settings в /local/components (ajax.php
 * рядом — сохранение прав ui.accessrights.v2). Канон — Main\PublicPage
 * shef.toolsai и Main\AdminPage этого модуля.
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
	)
	{
	}

	/**
	 * Порядок важен для удаления: с конца — ajax.php раньше компонента,
	 * каталог компонента остаётся пустым и уходит.
	 *
	 * @return static[]
	 */
	public static function getList(): array
	{
		return [
			new static('/shef/toolsaicrm/index.php', '/public/page.php', 2),
			// 1.9.0: картинка объявления для виджета чата AI-помощника
			new static('/shef/toolsaicrm/art.php', '/public/art.php'),
			new static('/local/components/shef/toolsaicrm.settings/component.php', '/public/component.php', 4),
			new static('/local/components/shef/toolsaicrm.settings/ajax.php', '/public/ajax.php'),
		];
	}

	/**
	 * Положить все заглушки.
	 *
	 * @return array<string, bool> заглушка => на месте и своя
	 */
	public static function installAll(string $documentRoot, string $moduleDir): array
	{
		$report = [];
		foreach(static::getList() as $page)
		{
			$report[$page->file] = $page->install($documentRoot, $moduleDir);
		}

		return $report;
	}

	/**
	 * Убрать свои заглушки (с конца списка).
	 *
	 * @return array<string, bool> заглушка => своей больше нет
	 */
	public static function uninstallAll(string $documentRoot, string $moduleDir = ''): array
	{
		$report = [];
		foreach(array_reverse(static::getList()) as $page)
		{
			$report[$page->file] = $page->uninstall($documentRoot, $moduleDir);
		}

		return $report;
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
	 */
	public function isOwn(string $content, string $documentRoot = '', string $moduleDir = ''): bool
	{
		if($moduleDir !== '' && $content === $this->getContent($documentRoot, $moduleDir))
		{
			return true;
		}

		$pattern = sprintf(
			'#^<\?php require\((?:\$_SERVER\[\'DOCUMENT_ROOT\'\]\.)?\'[^\']*/shef\.toolsaicrm%s\'\);\s*$#',
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
