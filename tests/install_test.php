<?php declare(strict_types=1);

/**
 * Установщик shef.toolsaicrm (install/index.php) и подписки .settings.php — без портала.
 *
 * Что держит:
 *
 * * отказ до любых изменений: UTF-8, PHP, главный модуль, shef.options >= 3.0.0, shef.problems >= 2.0.0,
 *   shef.toolsai >= 1.96.0 (Constants::CORE_MIN_VERSION), модули crm и bizproc; DoInstall зовёт
 *   проверку раньше таблиц, файлов и подписок;
 * * requireModules — shef.options, shef.problems, shef.toolsai;
 * * подписки полей «AI: …» и скриптов звонков — ровно те, что описаны в Analysis\EntityFields::EVENTS
 *   и CallScript\Events::ADD (одна запись в b_module_to_module, не две);
 * * повторная установка не плодит подписок и агентов; удаление снимает подписки (с «\» и без);
 * * удаление: с savedata таблицы и настройки остаются, без него — уходят; поля «AI: …» и их тип
 *   не удаляются; предупреждение про роботов БП в тексте формы;
 * * все коды языковых сообщений установщика есть в lang/ru/install/index.php.
 * * страницы установщика — как в линейке: пролог и эпилог ядра — только в ShowForm; форма удаления —
 *   шаг unstep1.php через IncludeAdminFile ядра (#9); поведение — tests/installpage_test.php.
 *
 * Ядро подменяется заглушками, установщик подключается настоящий.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';
require_once $root.'/tests/stub/install.php';

use Bitrix\Main\Application;
use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Shef\ToolsAiCrm\Analysis\EntityFields;
use Shef\ToolsAiCrm\CallScript\Events as CallScriptEvents;
use Shef\ToolsAiCrm\Main\Constants;
use Shef\ToolsAiCrm\Main\Maintenance;
use Shef\ToolsAiCrm\Main\Setup;

$MESS = [];
require $root.'/lang/ru/install/index.php';
Loc::$messages = $MESS;

require_once $root.'/install/index.php';

$GLOBALS['APPLICATION'] = null;
$settings = require $root.'/.settings.php';
Configuration::$settings = $settings;

$given = static function(array $versions = [], array $extra = []): shef_toolsaicrm
{
	$defaults = ['shef.options' => '3.8.0', 'shef.problems' => '2.1.0', 'shef.toolsai' => '1.96.0'];
	$versions = array_merge($defaults, $versions);
	ModuleManager::$installed = array_unique(array_merge(['crm', 'bizproc'], array_keys($versions), $extra));
	ModuleManager::$versions = $versions;
	Application::$utf = true;

	return new shef_toolsaicrm();
};

// region Требования ////
Check::group('минимумы записаны в установщике');
$module = $given();
Check::same('shef.options', $module->NEED_MODULES_BY_VERSION['shef.options'] ?? null, '3.0.0');
Check::same('shef.problems', $module->NEED_MODULES_BY_VERSION['shef.problems'] ?? null, '2.0.0');
Check::same('shef.toolsai — Constants::CORE_MIN_VERSION', $module->NEED_MODULES_BY_VERSION['shef.toolsai'] ?? null, Constants::CORE_MIN_VERSION);
Check::same('CORE_MIN_VERSION', Constants::CORE_MIN_VERSION, '1.96.0');
Check::same('crm и bizproc', $module->NEED_MODULES, ['crm', 'bizproc']);
Check::same('PHP', $module->PHP_MIN_VER, '8.2.0');
Check::same('главный модуль', $module->NEED_MAIN_VERSION, '22.600.300');

Check::group('всё на месте — отказа нет');
Check::same('requirementError', $given()->requirementError(), null);
Check::same('версии ровно на границе', $given(['shef.options' => '3.0.0', 'shef.problems' => '2.0.0', 'shef.toolsai' => '1.96.0'])->requirementError(), null);
Check::same('версии выше', $given(['shef.toolsai' => '2.3.0'])->requirementError(), null);

Check::group('отказ с понятным текстом');
Application::$utf = false;
Check::same('не UTF-8', str_contains((string)(new shef_toolsaicrm())->requirementError(), 'UTF-8'), true);
Application::$utf = true;

$old = $given();
$old->PHP_MIN_VER = '99.0.0';
Check::same('PHP ниже нужного', str_contains((string)$old->requirementError(), '99.0.0'), true);

$old = $given();
$old->NEED_MAIN_VERSION = '99.0.0';
Check::same('главный модуль ниже нужного', str_contains((string)$old->requirementError(), '99.0.0'), true);

foreach(['crm', 'bizproc'] as $missing)
{
	$module = $given();
	ModuleManager::$installed = array_values(array_diff(ModuleManager::$installed, [$missing]));
	Check::same('нет модуля '.$missing, str_contains((string)$module->requirementError(), $missing), true);
}

$message = (string)$given(['shef.toolsai' => '1.95.9'])->requirementError();
Check::same('shef.toolsai 1.95.9: назван модуль, версия и текущая', [str_contains($message, 'shef.toolsai'), str_contains($message, '1.96.0'), str_contains($message, '1.95.9')], [true, true, true]);

$module = $given();
ModuleManager::$installed = array_values(array_diff(ModuleManager::$installed, ['shef.toolsai']));
Check::same('shef.toolsai не установлен — отказ', str_contains((string)$module->requirementError(), 'shef.toolsai'), true);
Check::same('…и сказано «нет»', str_contains((string)$module->requirementError(), 'Установлена: нет'), true);
Check::same('shef.options 2.9.9', str_contains((string)$given(['shef.options' => '2.9.9'])->requirementError(), 'shef.options'), true);
Check::same('shef.problems 1.9.9', str_contains((string)$given(['shef.problems' => '1.9.9'])->requirementError(), 'shef.problems'), true);
$module = $given();
ModuleManager::$versions['shef.toolsai'] = '';
Check::same('версия shef.toolsai не читается — отказ', str_contains((string)$module->requirementError(), 'версия не читается'), true);

Check::group('DoInstall: проверки — до таблиц, подписок и файлов');
$source = (string)file_get_contents($root.'/install/index.php');
preg_match('/public function DoInstall\(\): void\s*\{(.*?)\n\t\}\n/s', $source, $m);
$body = $m[1] ?? '';
Check::same('requirementError раньше InstallDB', strpos($body, 'requirementError()') < strpos($body, '$this->InstallDB()'), true);
Check::same('после отказа — return, дальше не идём', preg_match('/ShowForm\(\'ERROR\', \$error\);\s*return;/', $body), 1);
Check::same('порядок: таблицы, подписки, файлы, перенос', [strpos($body, 'InstallDB()') < strpos($body, 'InstallEvents()'), strpos($body, 'InstallEvents()') < strpos($body, 'InstallFiles()'), strpos($body, 'InstallFiles()') < strpos($body, 'InstallEngine()')], [true, true, true]);

Check::group('Страницы установщика — как в линейке (#9)');
preg_match('/private function ShowForm\(string \$type, string \$message, string \$buttonName = \'\'\): void\s*\{(.*?)\n\t\}\n/s', $source, $m);
$form = $m[1] ?? '';
$unstep = (string)file_get_contents($root.'/install/unstep1.php');
Check::same('ShowForm есть', $form !== '', true);
Check::same('пролог и эпилог ядра — по одному подключению, оба в ShowForm (поведение — tests/installpage_test.php)', [preg_match_all('/prolog_admin_after\.php[\'"]/', $source), preg_match_all('/epilog_admin\.php[\'"]/', $source), preg_match_all('/prolog_admin_after\.php[\'"]/', $form), preg_match_all('/epilog_admin\.php[\'"]/', $form)], [1, 1, 1, 1]);
Check::same('подпись кнопки экранирована', str_contains($form, 'value="<?=htmlspecialcharsbx($buttonName'), true);
Check::same('форма удаления — шаг unstep1.php через IncludeAdminFile ядра', preg_match('/public function DoUninstall\(\): void\s*\{.*?IncludeAdminFile\(.*?__DIR__\.\'\/unstep1\.php\'/s', $source), 1);
Check::same('в шаге удаления своих пролога и эпилога нет — их выводит ядро', [str_contains($unstep, 'prolog_admin'), str_contains($unstep, 'epilog_admin')], [false, false]);
Check::same('шаг удаления: сессия, step=2, модуль', [str_contains($unstep, 'bitrix_sessid_post()'), str_contains($unstep, 'name="step" value="2"'), str_contains($unstep, 'name="id" value="shef.toolsaicrm"')], [true, true, true]);

Check::group('requireModules');
Check::same('shef.options, shef.problems, shef.toolsai', $settings['requireModules']['value'], ['shef.options', 'shef.problems', 'shef.toolsai']);
// endregion ////

// region Подписки ////
Check::group('подписки .settings.php: поля «AI: …» и скрипты звонков — как в коде модуля');
$events = $settings['installEvents']['value'];
$find = static function(string $from, string $event, string $class, string $method) use ($events): ?array
{
	foreach($events as $row)
	{
		if($row['from']['module'] === $from && $row['from']['event'] === $event && $row['to']['class'] === $class && $row['to']['function'] === $method)
		{
			return $row;
		}
	}

	return null;
};
foreach([...EntityFields::EVENTS, CallScriptEvents::ADD] as [$from, $event, $class, $method])
{
	$row = $find($from, $event, $class, $method);
	Check::same($from.':'.$event.' → '.$class.'::'.$method.' есть', $row !== null, true);
	Check::same('  модуль — shef.toolsaicrm, не совместимый вид', [$row['to']['module'] ?? null, $row['isCompatible'] ?? null], ['shef.toolsaicrm', false]);
}
$keys = array_map(static fn(array $r): string => implode('|', [$r['from']['module'], $r['from']['event'], $r['to']['module'], $r['to']['class'], $r['to']['function']]), $events);
Check::same('дублей нет', count($keys), count(array_unique($keys)));
Check::same('все обработчики — свои классы и существуют', array_values(array_filter(
	$events,
	static fn(array $r): bool => $r['to']['module'] === 'shef.toolsaicrm' && !(class_exists($r['to']['class']) && method_exists($r['to']['class'], $r['to']['function']))
)), []);

Check::group('InstallEvents: повтор не плодит подписок и агентов');
EventManager::$registered = [];
EventManager::$unregistered = [];
CAgent::reset();
$module = $given();
$module->InstallEvents();
$firstRegistered = EventManager::$registered;
Check::same('подписок зарегистрировано — по числу installEvents', count($firstRegistered), count($events));
foreach(CAgent::$agents as $id => $agent)
{
	CAgent::Update($id, ['AGENT_INTERVAL' => 5]); // кто-то поправил интервал агента доведения
}
$module->InstallEvents();
Check::same('второй раз — те же подписки, каждая со снятием перед постановкой', [count(EventManager::$registered), count(EventManager::$unregistered)], [count($events) * 2, count($events) * 2]);
Check::same('набор подписок тот же', array_slice(EventManager::$registered, count($events)), $firstRegistered);
$own = array_values(array_filter(CAgent::$agents, static fn(array $a): bool => $a['MODULE_ID'] === 'shef.toolsaicrm'));
Check::same('агент доведения — один', array_column($own, 'NAME'), ['\\Shef\\ToolsAiCrm\\Agent\\Maintenance::run();']);
Check::same('повторная установка вернула интервал агента', $own[0]['AGENT_INTERVAL'], 600);
Check::same('имя и интервал агента — как у Main\\Maintenance', [$own[0]['NAME'], $own[0]['AGENT_INTERVAL']], [Maintenance::AGENT_NAME, Maintenance::AGENT_INTERVAL]);

Check::group('UnInstallEvents: снимает все подписки, класс — с «\\» и без');
EventManager::$unregistered = [];
$module->UnInstallEvents();
$expected = [];
foreach($events as $row)
{
	foreach(array_unique([$row['to']['class'], ltrim($row['to']['class'], '\\'), '\\'.ltrim($row['to']['class'], '\\')]) as $class)
	{
		$expected[] = [$row['from']['module'], $row['from']['event'], $row['to']['module'], $class, $row['to']['function']];
	}
}
Check::same('снято всё, что ставили', EventManager::$unregistered, $expected);
// endregion ////

// region Удаление ////
$tables = array_map(static fn(string $class): string => $class::getTableName(), Setup::TABLES);

Check::group('удаление без savedata: таблицы и настройки модуля уходят');
$sql = new SqlRecorder();
$sql->tables = $tables;
Application::$connection = $sql;
Option::$values = ['shef.toolsaicrm' => ['DEAL_enabled' => 'Y'], 'shef.toolsai' => ['DEAL_enabled' => 'Y']];
ModuleLog::$calls = [];
$module = $given();
$module->UnInstallDB([]);
Check::same('DROP TABLE по каждой таблице', array_values(array_filter($sql->queries, static fn(string $q): bool => str_starts_with($q, 'DROP TABLE'))), array_map(static fn(string $t): string => 'DROP TABLE '.$t, $tables));
Check::same('настройки модуля удалены', isset(Option::$values['shef.toolsaicrm']), false);
Check::same('настройки shef.toolsai не тронуты', Option::$values['shef.toolsai'] ?? null, ['DEAL_enabled' => 'Y']);
Check::same('регистрация снята — последней', ModuleLog::$calls, ['unregister shef.toolsaicrm']);

Check::group('удаление с savedata = Y: данные остаются');
$sql = new SqlRecorder();
$sql->tables = $tables;
Application::$connection = $sql;
Option::$values = ['shef.toolsaicrm' => ['DEAL_enabled' => 'Y', Constants::OPTION_MIGRATED => '2026-11-09 12:00:00|x=1']];
ModuleLog::$calls = [];
$module->UnInstallDB(['savedata' => 'Y']);
Check::same('таблицы не удалялись', $sql->queries, []);
Check::same('настройки на месте', Option::$values['shef.toolsaicrm']['DEAL_enabled'] ?? null, 'Y');
Check::same('отметка переноса на месте', isset(Option::$values['shef.toolsaicrm'][Constants::OPTION_MIGRATED]), true);
Check::same('регистрация снята', ModuleLog::$calls, ['unregister shef.toolsaicrm']);

Check::group('удаление: поля «AI: …» и их тип не удаляются');
CUserTypeEntity::reset();
CUserTypeEntity::seed('CRM_DEAL', 'UF_SHEF_AI_RISK');
CUserTypeEntity::seed('CRM_DEAL', 'UF_SHEF_AI_ACK_SENIOR');
$sql = new SqlRecorder();
$sql->tables = $tables;
Application::$connection = $sql;
$module->UnInstallDB([]);
$module->UnInstallEvents();
Check::same('поля на месте', count(CUserTypeEntity::$fields), 2);
Check::same('вызовов удаления полей нет', CUserTypeEntity::$calls, []);
Check::same('в установщике нет вызовов, трогающих поля', [str_contains($source, 'EntityFields::remove'), str_contains($source, 'CUserTypeEntity'), str_contains($source, 'UserTypeManager')], [false, false, false]);

Check::group('удаление: агенты и блокировки — литералами установщика совпадают с кодом модуля');
Check::same('агент доведения', (new ReflectionClassConstant($module, 'AGENT_NAME'))->getValue(), Maintenance::AGENT_NAME);
Check::same('интервал агента доведения', (new ReflectionClassConstant($module, 'AGENT_INTERVAL'))->getValue(), Maintenance::AGENT_INTERVAL);
Check::same('группа блокировок анализа', (new ReflectionClassConstant($module, 'LOCK_GROUP_ANALYSIS'))->getValue(), Constants::LOCK_GROUP_ANALYSIS_PREFIX);
Check::same('удаление снимает агенты модуля одним вызовом', str_contains($source, 'CAgent::RemoveModuleAgents($this->MODULE_ID)'), true);
Check::same('удаление ставит регистрацию последней', strpos($source, '$this->UnInstallDB([') > strpos($source, '$this->UnInstallEvents();'), true);
Check::same('форма удаления: savedata отмечен по умолчанию', preg_match('/name="savedata" value="Y" checked/', $unstep), 1);

Check::group('удаление: предупреждение про роботов БП');
Check::same('в тексте формы', str_contains((string)Loc::getMessage('SH_TOOLSAICRM_UNINSTALL_WARN'), 'роботы бизнес-процессов'), true);
Check::same('про сохранение данных', str_contains((string)Loc::getMessage('SH_TOOLSAICRM_UNINSTALL_WARN'), 'Сохранить данные'), true);
Check::same('про поля «AI: …»', str_contains((string)Loc::getMessage('SH_TOOLSAICRM_UNINSTALL_WARN'), 'Поля «AI: …»'), true);
Check::same('про тип поля без зарегистрированного обработчика и значения текстом', [str_contains((string)Loc::getMessage('SH_TOOLSAICRM_UNINSTALL_WARN'), 'без зарегистрированного обработчика'), str_contains((string)Loc::getMessage('SH_TOOLSAICRM_UNINSTALL_WARN'), 'значения полей «AI: …» текстом')], [true, true]);
// endregion ////

// region Язык ////
Check::group('языковой файл: каждый код из установщика есть');
preg_match_all('/Loc::getMessage\(\s*\'([^\']+)\'/', $source.$unstep, $matches);
$used = array_unique($matches[1]);
foreach($used as $code)
{
	Check::same($code, isset($MESS[$code]) && $MESS[$code] !== '', true);
}
Check::same('кодов проверено больше десяти', count($used) > 10, true);
Check::same('в тексте нет прежнего названия и цен', preg_match('/BitrixGPT|DeepSeek|₽|\$|руб/u', implode(' ', $MESS)), 0);
Check::same('«AI», не «ИИ»', str_contains(implode(' ', $MESS), 'ИИ'), false);
Check::same('название модуля', $MESS['shef.toolsaicrm_MODULE_NAME'], Constants::TITLE);
// endregion ////

Check::finish();
