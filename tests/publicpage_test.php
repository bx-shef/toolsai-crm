<?php declare(strict_types=1);

/**
 * Заглушки публичных страниц shef.toolsaicrm: страница /shef/toolsaicrm/, компонент настроек и робот БП
 * «AI: оценить сейчас» (/bitrix/activities/custom/shtoolsaianalyzeactivity).
 *
 * Что держит:
 *
 * * какие заглушки и куда ведут; путь — туда, где модуль стоит на самом деле (/bitrix/modules,
 *   /local/modules, вне корня сайта);
 * * удаляется только СВОЯ заглушка: чужой файл проекта на её месте цел и установкой не перезаписывается;
 * * заглушку робота, которую до выноса анализа писал shef.toolsai (ведёт в .../shef.toolsai/activities/analyze/...),
 *   установка ЗАМЕНЯЕТ — иначе робот в схемах БП вёл бы в каталог, которого в shef.toolsai нет;
 *   такой «прежней» заглушки на других местах (страница, компонент) и чужих модулей нет;
 * * свои каталоги создаются и убираются, если опустели; общие (/shef, /local/components, custom) —
 *   только пока пусты;
 * * install/layout.php (портал из git) отдаёт тот же набор файлов с тем же содержимым.
 *
 * Класс подключается явным require_once — ровно как в установщике.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/assert.php';
require_once $root.'/lib/main/publicpage.php';

use Shef\ToolsAiCrm\Main\Constants;
use Shef\ToolsAiCrm\Main\PublicPage;

$portal = sys_get_temp_dir().'/shef-toolsaicrm-publicpage-'.getmypid();
$www = $portal.'/www';
mkdir($www.'/bitrix/activities/custom', 0777, true);
mkdir($www.'/bitrix/modules/shef.toolsaicrm', 0777, true);
register_shutdown_function(static function() use ($portal): void
{
	$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($portal, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	foreach($items as $item)
	{
		$item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
	}
	@rmdir($portal);
});

$require = static fn(string $path): string => "<?php require(\$_SERVER['DOCUMENT_ROOT'].'".$path."');\n";
$moduleDir = $www.'/bitrix/modules/shef.toolsaicrm';
$robotDir = $www.'/bitrix/activities/custom/shtoolsaianalyzeactivity';

Check::group('какие заглушки');
Check::same(
	'страница, компонент настроек (с ajax) и робот БП',
	array_map(static fn(PublicPage $page): string => $page->file.' -> '.$page->modulePage, PublicPage::getList()),
	[
		'/shef/toolsaicrm/index.php -> /settings/page.php',
		'/local/components/shef/toolsaicrm.settings/component.php -> /settings/component.php',
		'/local/components/shef/toolsaicrm.settings/ajax.php -> /settings/ajax.php',
		'/bitrix/activities/custom/shtoolsaianalyzeactivity/.description.php -> /activities/analyze/.description.php',
		'/bitrix/activities/custom/shtoolsaianalyzeactivity/shtoolsaianalyzeactivity.php -> /activities/analyze/shtoolsaianalyzeactivity.php',
	]
);
Check::same('нет лишних страниц (картинки объявлений и прочего из соседа)', array_values(array_filter(
	array_map(static fn(PublicPage $page): string => $page->file, PublicPage::getList()),
	static fn(string $file): bool => str_contains($file, 'art.php') || str_contains($file, 'announce')
)), []);
Check::same('адреса — из Constants', [PublicPage::getList()[0]->file, PublicPage::getList()[1]->file], [Constants::SETTINGS_FILE, Constants::COMPONENT_FILE]);
Check::same('ownDirs: страница 2, компонент 4, ajax 0, описание робота 2, класс робота 1', array_map(static fn(PublicPage $page): int => $page->ownDirs, PublicPage::getList()), [2, 4, 0, 2, 1]);
foreach(PublicPage::getList() as $page)
{
	if(str_starts_with($page->modulePage, '/settings/'))
	{
		Check::same('страница модуля есть: '.$page->modulePage, is_file($root.$page->modulePage), true);
	}
}

Check::group('установка: заглушка ведёт туда, где модуль стоит');
$report = PublicPage::installAll($www, $moduleDir);
Check::same('все записаны', array_filter($report), $report);
Check::same('страница', file_get_contents($www.'/shef/toolsaicrm/index.php'), $require('/bitrix/modules/shef.toolsaicrm/settings/page.php'));
Check::same('компонент', file_get_contents($www.'/local/components/shef/toolsaicrm.settings/component.php'), $require('/bitrix/modules/shef.toolsaicrm/settings/component.php'));
Check::same('ajax компонента', file_get_contents($www.'/local/components/shef/toolsaicrm.settings/ajax.php'), $require('/bitrix/modules/shef.toolsaicrm/settings/ajax.php'));
Check::same('описание робота', file_get_contents($robotDir.'/.description.php'), $require('/bitrix/modules/shef.toolsaicrm/activities/analyze/.description.php'));
Check::same('класс робота', file_get_contents($robotDir.'/shtoolsaianalyzeactivity.php'), $require('/bitrix/modules/shef.toolsaicrm/activities/analyze/shtoolsaianalyzeactivity.php'));
Check::same('второй раз — то же, всё на месте', array_filter(PublicPage::installAll($www, $moduleDir)), $report);

Check::group('путь: /local/modules и модуль вне корня сайта');
$page = PublicPage::getList()[0];
Check::same('/local/modules', $page->getContent($www, $www.'/local/modules/shef.toolsaicrm'), $require('/local/modules/shef.toolsaicrm/settings/page.php'));
Check::same('вне корня — абсолютный путь', $page->getContent($www, '/opt/modules/shef.toolsaicrm'), "<?php require('/opt/modules/shef.toolsaicrm/settings/page.php');\n");
Check::same('кавычка в пути экранируется', $page->getContent($www, "/opt/it's/shef.toolsaicrm"), "<?php require('/opt/it\\'s/shef.toolsaicrm/settings/page.php');\n");
Check::same('каталог модуля с другим именем (клон репозитория): своя заглушка по содержимому', $page->isOwn($page->getContent($www, $www.'/bitrix/modules/toolsai-crm'), $www, $www.'/bitrix/modules/toolsai-crm'), true);
Check::same('модуль переехал — заглушка переписывается', (function() use ($www, $page): bool
{
	$ok = $page->install($www, $www.'/local/modules/shef.toolsaicrm');

	return $ok && file_get_contents($www.'/shef/toolsaicrm/index.php') === "<?php require(\$_SERVER['DOCUMENT_ROOT'].'/local/modules/shef.toolsaicrm/settings/page.php');\n";
})(), true);
PublicPage::installAll($www, $moduleDir);

Check::group('чужой файл на месте заглушки: ни установка, ни удаление его не трогают');
foreach(PublicPage::getList() as $page)
{
	$target = $page->getTarget($www);
	$foreign = "<?php // файл проекта: ".$page->file."\n";
	file_put_contents($target, $foreign);
	Check::same('установка не перезаписывает: '.$page->file, [$page->install($www, $moduleDir), file_get_contents($target)], [false, $foreign]);
	Check::same('удаление не трогает: '.$page->file, [$page->uninstall($www, $moduleDir), file_get_contents($target)], [false, $foreign]);
	file_put_contents($target, $page->getContent($www, $moduleDir));
}

// region Заглушка shef.toolsai ////
$legacy = static fn(string $file): string => $require('/bitrix/modules/shef.toolsai'.$file);
$legacyAbsolute = static fn(string $file): string => "<?php require('/var/www/bitrix/modules/shef.toolsai".$file."');\n";

Check::group('прежняя заглушка робота от shef.toolsai заменяется, чтобы робот вёл в новый модуль');
foreach(['/.description.php', '/shtoolsaianalyzeactivity.php'] as $name)
{
	foreach([$legacy('/activities/analyze'.$name), $legacyAbsolute('/activities/analyze'.$name)] as $variant => $oldStub)
	{
		file_put_contents($robotDir.$name, $oldStub);
		$page = null;
		foreach(PublicPage::getList() as $candidate)
		{
			if(str_ends_with($candidate->file, $name))
			{
				$page = $candidate;
			}
		}
		Check::same($name.' ('.($variant === 0 ? 'от корня сайта' : 'абсолютный путь').'): «своя»', $page->isOwn($oldStub, $www, $moduleDir), true);
		Check::same($name.': установка заменила', [$page->install($www, $moduleDir), file_get_contents($robotDir.$name)], [true, $require('/bitrix/modules/shef.toolsaicrm/activities/analyze'.$name)]);
	}
}
// весь набор: прежний файл только у класса робота
file_put_contents($robotDir.'/shtoolsaianalyzeactivity.php', $legacy('/activities/analyze/shtoolsaianalyzeactivity.php'));
$report = PublicPage::installAll($www, $moduleDir);
Check::same('installAll: всё на месте и своё', array_filter($report), $report);
Check::same('и теперь файл ведёт в shef.toolsaicrm', str_contains((string)file_get_contents($robotDir.'/shtoolsaianalyzeactivity.php'), 'shef.toolsaicrm/activities/analyze'), true);

Check::group('чужая заглушка робота не заменяется');
$page = PublicPage::getList()[4];
foreach([
	'другой модуль' => $require('/bitrix/modules/shef.toolsaiassistant/activities/analyze/shtoolsaianalyzeactivity.php'),
	'тот же модуль, другая страница' => $legacy('/activities/prompt/shtoolsaianalyzeactivity.php'),
	'похожее имя модуля' => $require('/bitrix/modules/shef.toolsaix/activities/analyze/shtoolsaianalyzeactivity.php'),
	'ещё одно имя модуля' => $require('/bitrix/modules/my.shef.toolsai2/activities/analyze/shtoolsaianalyzeactivity.php'),
	'файл проекта' => "<?php\nclass CBPShToolsAiAnalyzeActivity {}\n",
	'прежняя заглушка с лишним кодом' => $legacy('/activities/analyze/shtoolsaianalyzeactivity.php')."echo 1;\n",
] as $label => $foreign)
{
	file_put_contents($robotDir.'/shtoolsaianalyzeactivity.php', $foreign);
	Check::same($label.': не «своя»', $page->isOwn($foreign, $www, $moduleDir), false);
	Check::same($label.': установка не заменила', [$page->install($www, $moduleDir), file_get_contents($robotDir.'/shtoolsaianalyzeactivity.php')], [false, $foreign]);
}
file_put_contents($robotDir.'/shtoolsaianalyzeactivity.php', $page->getContent($www, $moduleDir));

Check::group('прежняя заглушка shef.toolsai — только у робота: страница и компонент её «своей» не считают');
foreach([[0, '/settings/page.php'], [1, '/settings/component.php'], [2, '/settings/ajax.php']] as [$index, $modulePage])
{
	$page = PublicPage::getList()[$index];
	$oldStub = $legacy($modulePage);
	Check::same($page->file.': заглушка shef.toolsai не «своя»', $page->isOwn($oldStub, $www, $moduleDir), false);
	Check::same($page->file.': у страницы нет прежних модулей', $page->legacyModules, []);
}
Check::same('у робота — только shef.toolsai', [PublicPage::getList()[3]->legacyModules, PublicPage::getList()[4]->legacyModules], [['shef.toolsai'], ['shef.toolsai']]);
// endregion ////

Check::group('удаление: только свои, каталоги — по очереди');
$report = PublicPage::uninstallAll($www, $moduleDir);
Check::same('всё убрано', array_filter($report), $report);
Check::same('страница и каталог модуля', [is_dir($www.'/shef/toolsaicrm'), is_dir($www.'/shef')], [false, false]);
Check::same('компонент: каталог и общие уровни, пока пусты', [is_dir($www.'/local/components/shef/toolsaicrm.settings'), is_dir($www.'/local')], [false, false]);
Check::same('робот: каталог убран', is_dir($robotDir), false);
Check::same('/bitrix/activities/custom — создан и пуст: уходит вместе с роботом', is_dir($www.'/bitrix/activities/custom'), false);
mkdir($www.'/bitrix/activities/custom/otherrobot', 0777, true);
file_put_contents($www.'/bitrix/activities/custom/otherrobot/.description.php', '<?php // чужой робот');
PublicPage::installAll($www, $moduleDir);
PublicPage::uninstallAll($www, $moduleDir);
Check::same('чужой робот в custom: каталог и робот целы, наш убран', [is_file($www.'/bitrix/activities/custom/otherrobot/.description.php'), is_dir($robotDir)], [true, false]);

Check::group('общие каталоги с чужим содержимым остаются');
PublicPage::installAll($www, $moduleDir);
mkdir($www.'/local/components/shef/other.component', 0777, true);
file_put_contents($www.'/local/components/shef/other.component/component.php', '<?php // чужой');
file_put_contents($www.'/shef/other.php', '<?php // чужая страница');
file_put_contents($www.'/local/php_interface.txt', 'x');
$report = PublicPage::uninstallAll($www, $moduleDir);
Check::same('свои убраны', array_filter($report), $report);
Check::same('чужой компонент и /shef целы', [is_file($www.'/local/components/shef/other.component/component.php'), is_file($www.'/shef/other.php')], [true, true]);
Check::same('/local цел', is_file($www.'/local/php_interface.txt'), true);
Check::same('удаление без стоящих заглушек — спокойно', array_filter(PublicPage::uninstallAll($www, $moduleDir)), PublicPage::uninstallAll($www, $moduleDir));

Check::group('корня сайта нет — своих каталогов не создаём');
$page = PublicPage::getList()[0];
Check::same('установка отказывает', $page->install($portal.'/nowhere', $moduleDir), false);
Check::same('каталога не появилось', is_dir($portal.'/nowhere'), false);

Check::group('install/layout.php — тот же набор файлов');
$layout = require $root.'/install/layout.php';
$result = $layout($www, $moduleDir);
Check::same('ключи copy и files', [$result['copy'], array_keys($result['files'])], [[], array_map(static fn(PublicPage $p): string => $p->file, PublicPage::getList())]);
foreach(PublicPage::getList() as $page)
{
	Check::same('содержимое '.$page->file, $result['files'][$page->file], $page->getContent($www, $moduleDir));
}
Check::same('в раскладке нет страницы /bitrix/admin и картинки объявлений', array_filter(array_keys($result['files']), static fn(string $f): bool => str_starts_with($f, '/bitrix/admin') || str_contains($f, 'art.php')), []);

Check::finish();
