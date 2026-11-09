<?php declare(strict_types=1);

/**
 * Раскладка модуля вне его каталога — для портала, который выкладывается из git
 * (bx-shef/options#55, читает Main\Modules\Layout shef.options без ядра): ровно то, что пишет
 * InstallFiles() — заглушки публичной страницы, компонента и робота БП (Main\PublicPage).
 * Копий каталогов нет: installDir пуст.
 */

require_once dirname(__DIR__).'/lib/main/publicpage.php';

return static function(string $documentRoot, string $moduleDir): array {
	$cut = static fn(string $target): string => substr($target, strlen(rtrim(str_replace('\\', '/', $documentRoot), '/')));
	$files = [];
	foreach(\Shef\ToolsAiCrm\Main\PublicPage::getList() as $page)
	{
		$files[$cut($page->getTarget($documentRoot))] = $page->getContent($documentRoot, $moduleDir);
	}

	return ['copy' => [], 'files' => $files];
};
