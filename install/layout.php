<?php declare(strict_types=1);

/**
 * Раскладка модуля вне его каталога — для портала, который выкладывается из git
 * (bx-shef/options#55, читает Main\Modules\Layout shef.options без ядра): ровно то, что пишет
 * InstallFiles() — заглушка в /bitrix/admin (Main\AdminPage) и заглушки публичной страницы
 * (Main\PublicPage). Копий каталогов нет: installDir пуст.
 */

require_once dirname(__DIR__).'/lib/main/adminpage.php';
require_once dirname(__DIR__).'/lib/main/publicpage.php';

return static function(string $documentRoot, string $moduleDir): array {
	$cut = static fn(string $target): string => substr($target, strlen(rtrim(str_replace('\\', '/', $documentRoot), '/')));
	$files = [$cut(\Shef\ToolsAiCrm\Main\AdminPage::getTarget($documentRoot)) => \Shef\ToolsAiCrm\Main\AdminPage::getContent($documentRoot, $moduleDir)];
	foreach(\Shef\ToolsAiCrm\Main\PublicPage::getList() as $page)
	{
		$files[$cut($page->getTarget($documentRoot))] = $page->getContent($documentRoot, $moduleDir);
	}

	return ['copy' => [], 'files' => $files];
};
