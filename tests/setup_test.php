<?php declare(strict_types=1);

/**
 * «Проверить и включить» (Main\Setup) и доведение после обновления копированием (Main\Maintenance) — без портала.
 *
 * Что держит:
 *
 * * агенты анализа: по одному на тип с включённым профилем, интервал — Config::getAnalysisInterval(),
 *   первый запуск через интервал, не сразу; повтор дублей не плодит, лишние копии и агенты типов
 *   без профилей снимаются; профили не прочитались — агентов не трогаем;
 * * перенос из shef.toolsai: старые агенты сняты, новые — ровно по одному на тип, профили для них
 *   берутся ПОСЛЕ переноса (из приёмника);
 * * таблицы создаются (init) один раз; подписки встали — иначе шаг не ок;
 * * поля «AI: …» не пересоздаются, если уже есть (остались от shef.toolsai); нет — создаются;
 * * Maintenance: отметка версии и .settings.php, повтор не раньше RETRY после сбоя, шаги независимы;
 * * заглушки пишутся при подготовке и доведении.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';
require_once $root.'/tests/stub/install.php';

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\ORM\Data\DataManager;
use Shef\ToolsAiCrm\Agent\Maintenance as MaintenanceAgent;
use Shef\ToolsAiCrm\Analysis\EntityFields;
use Shef\ToolsAiCrm\Analysis\EntityType;
use Shef\ToolsAiCrm\Config;
use Shef\ToolsAiCrm\Deal\Model\DealProfileTable;
use Shef\ToolsAiCrm\Deal\Profile;
use Shef\ToolsAiCrm\Main\Constants;
use Shef\ToolsAiCrm\Main\Maintenance;
use Shef\ToolsAiCrm\Main\Setup;

$settings = require $root.'/.settings.php';
$installEvents = $settings['installEvents']['value'];

$portal = sys_get_temp_dir().'/shef-toolsaicrm-setup-'.getmypid();
mkdir($portal.'/www/bitrix/activities', 0777, true);
register_shutdown_function(static function() use ($portal): void
{
	\Bitrix\Main\IO\Directory::deleteDirectory($portal);
});

$config = static fn(array $options = []): Config => new Config(static fn(string $name): mixed => $options[$name] ?? null);
$profile = static fn(int $typeId, bool $enabled, int $category = 0, int $id = 1): Profile => Profile::fromRow(['ID' => $id, 'ENTITY_TYPE_ID' => $typeId, 'IS_ENABLED' => $enabled ? 'Y' : 'N', 'CATEGORY_ID' => $category]);
$names = static fn(string $module): array => array_values(array_map(static fn(array $a): string => $a['NAME'], array_filter(CAgent::$agents, static fn(array $a): bool => $a['MODULE_ID'] === $module)));
$agentName = static fn(int $typeId): string => '\\Shef\\ToolsAiCrm\\Agent\\AnalysisAgent::run('.$typeId.');';
/** Подписки «стоят у ядра»: заглушка ищет их в b_module_to_module, а её нет. */
$seedHandlers = static function() use ($installEvents): void
{
	EventManager::$stubHandlers = [];
	foreach($installEvents as $row)
	{
		EventManager::$stubHandlers[$row['from']['module'].':'.$row['from']['event']][] = [
			'TO_MODULE_ID' => $row['to']['module'], 'TO_CLASS' => $row['to']['class'], 'TO_METHOD' => $row['to']['function'],
		];
	}
};

// region Имена и план агентов ////
Check::group('имя агента анализа');
Check::same('сделки', Setup::analysisAgentName(2), '\\Shef\\ToolsAiCrm\\Agent\\AnalysisAgent::run(2);');
Check::same('смарт-процесс', Setup::analysisAgentName(1050), '\\Shef\\ToolsAiCrm\\Agent\\AnalysisAgent::run(1050);');
Check::same('разбор своего имени', [Setup::parseAnalysisAgentName('\\Shef\\ToolsAiCrm\\Agent\\AnalysisAgent::run(1050);'), Setup::parseAnalysisAgentName('Shef\\ToolsAiCrm\\Agent\\AnalysisAgent::run(2);')], [1050, 2]);
Check::same('старое имя shef.toolsai — не наше', Setup::parseAnalysisAgentName('\\Shef\\ToolsAi\\Agent\\AnalysisAgent::run(2);'), 0);
Check::same('агент доведения — не анализ', Setup::parseAnalysisAgentName(Maintenance::AGENT_NAME), 0);

Check::group('план агентов — чистая функция');
Check::same('нужные типы — по порядку, без дублей и нуля', Setup::planAnalysisAgents([2, 1, 2, 0, -5], [])['ensure'], [1, 2]);
Check::same('агент типа без профиля и вторая копия — к снятию, чужой не трогаем', Setup::planAnalysisAgents([2], [
	5 => $agentName(2), 6 => $agentName(1), 7 => $agentName(2), 8 => Maintenance::AGENT_NAME,
])['remove'], [6, 7]);
Check::same('первая копия остаётся', Setup::planAnalysisAgents([2], [9 => $agentName(2), 5 => $agentName(2)])['remove'], [9]);
Check::same('интервал: поменять', Setup::planAgentUpdate(3600, null, 900, 1000), ['AGENT_INTERVAL' => 900]);
Check::same('интервал тот же, запуск далеко — подтянуть', Setup::planAgentUpdate(900, 1000 + 5000, 900, 1000), ['NEXT_EXEC' => 1900]);
Check::same('менять нечего', Setup::planAgentUpdate(900, 1000 + 100, 900, 1000), []);
// endregion ////

// region Агенты ////
Check::group('агенты: по одному на тип с включённым профилем, первый запуск — через интервал');
CAgent::reset();
$setup = new Setup($config(['DEAL_interval' => '60', 'ANALYSIS_interval_1' => '15']), null, static fn(): array => []);
$before = time();
$report = $setup->ensureAnalysisAgents([2, 1]);
Check::same('ok', $report['ok'], true);
Check::same('агенты типов', $names('shef.toolsaicrm'), [$agentName(1), $agentName(2)]);
$byName = [];
foreach(CAgent::$agents as $row)
{
	$byName[$row['NAME']] = $row;
}
Check::same('интервал сделок — общий (60 мин)', $byName[$agentName(2)]['AGENT_INTERVAL'], 3600);
Check::same('интервал лидов — свой (15 мин)', $byName[$agentName(1)]['AGENT_INTERVAL'], 900);
$next = (int)MakeTimeStamp($byName[$agentName(2)]['NEXT_EXEC']);
Check::same('сделки: первый запуск не сразу, а через интервал', $next >= $before + 3600 - 2 && $next <= time() + 3600 + 2, true);
$next = (int)MakeTimeStamp($byName[$agentName(1)]['NEXT_EXEC']);
Check::same('лиды: первый запуск через 15 минут', $next >= $before + 900 - 2 && $next <= time() + 900 + 2, true);
Check::same('у агентов МОДУЛЬ — shef.toolsaicrm, не активные периодические', array_unique(array_column(CAgent::$agents, 'MODULE_ID')), ['shef.toolsaicrm']);

Check::group('агенты: повторный запуск не плодит');
$setup->ensureAnalysisAgents([2, 1]);
$setup->ensureAnalysisAgents([2, 1]);
Check::same('ровно по одному на тип', $names('shef.toolsaicrm'), [$agentName(1), $agentName(2)]);

Check::group('агенты: дубли, снятый профиль, смена интервала');
CAgent::$agents[99] = ['ID' => 99, 'NAME' => $agentName(2), 'MODULE_ID' => 'shef.toolsaicrm', 'AGENT_INTERVAL' => 3600, 'NEXT_EXEC' => ConvertTimeStamp(time() + 3600, 'FULL'), 'ACTIVE' => 'Y'];
$report = $setup->ensureAnalysisAgents([2]);
Check::same('лишняя копия и агент лидов сняты', $names('shef.toolsaicrm'), [$agentName(2)]);
Check::same('сообщение', str_contains($report['message'], 'снято лишних: 2'), true);
Check::same('остался первый по ID', array_keys(array_filter(CAgent::$agents, static fn(array $a): bool => $a['NAME'] === $agentName(2))), [2]);
CAgent::reset();
$setup->ensureAnalysisAgents([2]);
CAgent::Update(1, ['AGENT_INTERVAL' => 7200, 'NEXT_EXEC' => ConvertTimeStamp(time() + 7200, 'FULL')]);
$setup->ensureAnalysisAgents([2]);
Check::same('интервал вернулся к настройке', CAgent::$agents[1]['AGENT_INTERVAL'], 3600);
Check::same('следующий запуск подтянут', (int)MakeTimeStamp(CAgent::$agents[1]['NEXT_EXEC']) <= time() + 3600 + 2, true);
$report = $setup->ensureAnalysisAgents([]);
Check::same('профилей нет — агентов нет', [$names('shef.toolsaicrm'), str_starts_with($report['message'], 'включённых профилей нет')], [[], true]);
// endregion ////

// region prepare ////
Check::group('prepare: старые агенты сняты, новые — ровно по одному на тип, профили берутся после переноса');
Application::$documentRoot = $portal.'/www';
$sql = new SqlRecorder();
Application::$connection = $sql;
ModuleManager::$installed = ['shef.toolsaicrm', 'shef.toolsai', 'shef.options', 'shef.problems', 'crm', 'bizproc'];
CAgent::reset();
Option::$values = [];
$seedHandlers();

$port = new MemoryPort();
$port->addTable('shef_toolsai_deal_profile', ['ID', 'IS_ENABLED', 'ENTITY_TYPE_ID', 'CATEGORY_ID'], [
	['ID' => 1, 'IS_ENABLED' => 'Y', 'ENTITY_TYPE_ID' => 2, 'CATEGORY_ID' => 0],
	['ID' => 2, 'IS_ENABLED' => 'Y', 'ENTITY_TYPE_ID' => 2, 'CATEGORY_ID' => 3],
	['ID' => 3, 'IS_ENABLED' => 'Y', 'ENTITY_TYPE_ID' => 1, 'CATEGORY_ID' => 0],
	['ID' => 4, 'IS_ENABLED' => 'N', 'ENTITY_TYPE_ID' => 1050, 'CATEGORY_ID' => 0],
]);
$port->addTable('shef_toolsaicrm_deal_profile', ['ID', 'IS_ENABLED', 'ENTITY_TYPE_ID', 'CATEGORY_ID']);
$port->agents['shef.toolsai'] = [
	10 => '\\Shef\\ToolsAi\\Agent\\AnalysisAgent::run(2);',
	11 => '\\Shef\\ToolsAi\\Agent\\AnalysisAgent::run(1);',
	12 => '\\Shef\\ToolsAi\\Agent\\DealHealthAgent::run();',
	13 => '\\Shef\\ToolsAi\\Agent\\CallTagAgent::run();',
];
// профили читаются из приёмника — того, что наполнил перенос
$profilesFromTarget = static fn(): array => array_map(static fn(array $row): Profile => Profile::fromRow($row), $port->rows('shef_toolsaicrm_deal_profile'));
$setup = new Setup($config(), $port, $profilesFromTarget);
$report = $setup->prepare($portal.'/www', $root);
foreach($report as $step => $row)
{
	Check::same('шаг «'.$step.'» ок', $row['ok'], true);
}
Check::same('старые агенты shef.toolsai сняты, чужой цел', array_values($port->agents['shef.toolsai']), ['\\Shef\\ToolsAi\\Agent\\CallTagAgent::run();']);
Check::same('новые: по одному на тип с включённым профилем', $names('shef.toolsaicrm'), [$agentName(1), $agentName(2), Maintenance::AGENT_NAME]);
Check::same('перенос выполнен и сверен', isset($port->options['shef.toolsai'][Constants::CORE_OPTION_MIGRATED]), true);
Check::same('таблицы создаются: 5 CREATE TABLE', array_map(static fn(string $q): string => explode(' ', $q)[2], array_values(array_filter($sql->queries, static fn(string $q): bool => str_starts_with($q, 'CREATE TABLE')))), [
	'shef_toolsaicrm_deal_profile', 'shef_toolsaicrm_entity_check', 'shef_toolsaicrm_analysis_history', 'shef_toolsaicrm_prompt', 'shef_toolsaicrm_call_script_rule',
]);
Check::same('подписки зарегистрированы', count(EventManager::$registered) >= count($installEvents), true);
Check::same('заглушки: страница, компонент, ajax, робот (описание и класс)', array_values(array_filter(array_keys($report), static fn(string $step): bool => str_starts_with($step, 'file '))), [
	'file /shef/toolsaicrm/index.php',
	'file /local/components/shef/toolsaicrm.settings/component.php',
	'file /local/components/shef/toolsaicrm.settings/ajax.php',
	'file /bitrix/activities/custom/shtoolsaianalyzeactivity/.description.php',
	'file /bitrix/activities/custom/shtoolsaianalyzeactivity/shtoolsaianalyzeactivity.php',
]);

Check::group('prepare: повтор безопасен');
$agentsBefore = CAgent::$agents;
$report = $setup->prepare($portal.'/www', $root);
Check::same('снова всё ок', array_filter($report, static fn(array $row): bool => !$row['ok']), []);
Check::same('агенты те же (ничего не добавлено и не снято)', CAgent::$agents, $agentsBefore);
Check::same('новых CREATE TABLE нет', count(array_filter($sql->queries, static fn(string $q): bool => str_starts_with($q, 'CREATE TABLE'))), 5);
Check::same('в shef.toolsai агентов анализа не вернулось', count($port->agents['shef.toolsai']), 1);

Check::group('prepare: профили не прочитались — агентов не трогаем');
$failing = new Setup($config(), $port, static function(): array
{
	throw new RuntimeException('таблица недоступна');
});
$agentsBefore = CAgent::$agents;
$report = $failing->prepare($portal.'/www', $root);
Check::same('шаг агентов не ок', $report['analysis agents']['ok'], false);
Check::same('агенты остались', CAgent::$agents, $agentsBefore);

Check::group('prepare: перенос не сошёлся — установка не падает');
$bad = new MemoryPort();
$bad->addTable('shef_toolsai_deal_profile', ['ID', 'IS_ENABLED', 'ENTITY_TYPE_ID'], [['ID' => 1, 'IS_ENABLED' => 'Y', 'ENTITY_TYPE_ID' => 2], ['ID' => 2, 'IS_ENABLED' => 'Y', 'ENTITY_TYPE_ID' => 2]]);
$bad->addTable('shef_toolsaicrm_deal_profile', ['ID', 'IS_ENABLED', 'ENTITY_TYPE_ID']);
$bad->lossy = true;
$report = (new Setup($config(), $bad, static fn(): array => []))->prepare($portal.'/www', $root);
Check::same('сверка таблицы — не ок', $report['migrate shef_toolsai_deal_profile']['ok'], false);
Check::same('отметок нет', isset($bad->options['shef.toolsai'][Constants::CORE_OPTION_MIGRATED]), false);
Check::same('остальные шаги прошли', [$report['tables']['ok'], $report['events']['ok']], [true, true]);

Check::group('prepare: подписки не встали у ядра — шаг не ок');
EventManager::$stubHandlers = [];
$report = (new Setup($config(), new MemoryPort(), static fn(): array => []))->prepare($portal.'/www', $root);
Check::same('events не ок', $report['events']['ok'], false);
Check::same('сказано, какие', str_contains($report['events']['message'], 'не встали'), true);
$seedHandlers();
// endregion ////

// region Поля «AI: …» ////
Check::group('run: поля «AI: …» от shef.toolsai не пересоздаются');
Loader::$missing = ['crm', 'ui']; // раздел карточки — крупная часть ядра; ниже — только поля
$deal = EntityType::deal();
CUserTypeEntity::reset();
foreach(EntityFields::getCodes() as $code)
{
	CUserTypeEntity::seed($deal->ufEntityId, $code, ['EDIT_FORM_LABEL' => ['ru' => 'AI: поле'], 'LIST_COLUMN_LABEL' => ['ru' => 'AI: поле'], 'LIST_FILTER_LABEL' => ['ru' => 'AI: поле'], 'HELP_MESSAGE' => ['ru' => 'подсказка'], 'ERROR_MESSAGE' => ['ru' => 'ошибка']]);
}
$existing = count(CUserTypeEntity::$fields);
$setup = new Setup($config(), new MemoryPort(), static fn(): array => [$profile(2, true)]);
$report = $setup->run($portal.'/www', $root);
Check::same('полей на портале', $existing, count(EntityFields::getCodes()));
Check::same('ни одного Add', array_values(array_filter(CUserTypeEntity::$calls, static fn(string $c): bool => str_starts_with($c, 'add'))), []);
Check::same('столько же полей, сколько было', count(CUserTypeEntity::$fields), $existing);
Check::same('отчёт: на месте', $report['fields '.$deal->ufEntityId], ['ok' => true, 'message' => 'на месте']);
Check::same('раздел карточки при недоступном crm/ui — сообщением, не падением', $report['card '.$deal->ufEntityId]['ok'], false);

Check::group('run: нет полей — создаются все; идемпотентно');
CUserTypeEntity::reset();
$report = $setup->run($portal.'/www', $root);
Check::same('создано по числу кодов', count(array_filter(CUserTypeEntity::$calls, static fn(string $c): bool => str_starts_with($c, 'add'))), count(EntityFields::getCodes()));
CUserTypeEntity::$calls = [];
$setup->run($portal.'/www', $root);
Check::same('повторный запуск не добавляет', array_filter(CUserTypeEntity::$calls, static fn(string $c): bool => str_starts_with($c, 'add')), []);

Check::group('run: типы без включённого профиля — поля не создаются');
CUserTypeEntity::reset();
(new Setup($config(), new MemoryPort(), static fn(): array => [$profile(2, false)]))->run($portal.'/www', $root);
Check::same('ничего', CUserTypeEntity::$calls, []);
Loader::$missing = [];
// endregion ////

// region Maintenance ////
Check::group('Maintenance: отметка файлов');
Check::same('версия из version.php', Maintenance::fileVersion($root), '1.0.0');
Check::same('отметка — версия и хеш .settings.php', preg_match('/^1\.0\.0\|[0-9a-f]{12}$/', Maintenance::stamp($root)), 1);
Check::same('нужно, пока отметка не та', [Maintenance::isNeeded('', 'a|b'), Maintenance::isNeeded('a|b', 'a|b'), Maintenance::isNeeded('a|b', '')], [true, false, false]);
Check::same('имя и интервал агента доведения', [Maintenance::AGENT_NAME, Maintenance::AGENT_INTERVAL], ['\\Shef\\ToolsAiCrm\\Agent\\Maintenance::run();', 600]);

Check::group('Maintenance: доведение — подписки, таблицы, агенты, заглушки');
$portal2 = $portal.'/www2';
mkdir($portal2.'/bitrix/activities', 0777, true);
CAgent::reset();
EventManager::$registered = [];
$sql = new SqlRecorder();
Application::$connection = $sql;
ModuleManager::$installed = ['shef.toolsaicrm'];
$setup = new Setup($config(), new MemoryPort(), static fn(): array => [$profile(2, true), $profile(1, false)]);
$report = Maintenance::ensure($portal2, $root, $setup);
Check::same('все шаги успешны', array_filter($report, static fn(bool $ok): bool => !$ok), []);
Check::same('шаги: events, tables, agents и пять заглушек', array_keys($report), [
	'events', 'tables', 'agents',
	'file /shef/toolsaicrm/index.php',
	'file /local/components/shef/toolsaicrm.settings/component.php',
	'file /local/components/shef/toolsaicrm.settings/ajax.php',
	'file /bitrix/activities/custom/shtoolsaianalyzeactivity/.description.php',
	'file /bitrix/activities/custom/shtoolsaianalyzeactivity/shtoolsaianalyzeactivity.php',
]);
Check::same('подписки — по installEvents', count(EventManager::$registered), count($installEvents));
Check::same('агент доведения и агент сделок — по одному', $names('shef.toolsaicrm'), [Maintenance::AGENT_NAME, $agentName(2)]);
Check::same('создано пять таблиц', count(array_filter($sql->queries, static fn(string $q): bool => str_starts_with($q, 'CREATE TABLE'))), 5);
Check::same('заглушка страницы на месте', is_file($portal2.'/shef/toolsaicrm/index.php') || is_file($portal2.'/shef/toolsaicrm/index.php'), true);
Maintenance::ensure($portal2, $root, $setup);
Check::same('повтор: агентов столько же', $names('shef.toolsaicrm'), [Maintenance::AGENT_NAME, $agentName(2)]);

Check::group('Maintenance: шаги независимы, сбой одного не мешает остальным');
EventManager::$stubHandlers = [];
CAgent::reset();
$report = Maintenance::ensure($portal2, $root, $setup);
Check::same('подписки не встали — events не ок', $report['events'], false);
Check::same('таблицы и агенты — ок', [$report['tables'], $report['agents']], [true, true]);
$seedHandlers();

Check::group('Maintenance: профили не прочитались — агентов не снимаем');
CAgent::reset();
CAgent::AddAgent($agentName(2), 'shef.toolsaicrm', 'N', 3600);
$failing = new Setup($config(), new MemoryPort(), static function(): array
{
	throw new RuntimeException('нет таблицы');
});
$report = Maintenance::ensure($portal2, $root, $failing);
Check::same('шаг agents не ок', $report['agents'], false);
Check::same('агент сделок остался', in_array($agentName(2), $names('shef.toolsaicrm'), true), true);

Check::group('Maintenance: агент доведения — идемпотентно, без модуля не ставится');
CAgent::reset();
ModuleManager::$installed = [];
Maintenance::installAgent();
Check::same('модуль не установлен — агента нет', CAgent::$agents, []);
ModuleManager::$installed = ['shef.toolsaicrm'];
Maintenance::installAgent();
Maintenance::installAgent();
Check::same('один', $names('shef.toolsaicrm'), [Maintenance::AGENT_NAME]);
Check::same('агент возвращает себя', MaintenanceAgent::run(), Maintenance::AGENT_NAME);

Check::group('Maintenance: отметка и повтор после сбоя');
Option::$values = [];
CAgent::reset();
Maintenance::reset();
DataManager::$rows = [DealProfileTable::class => [['ID' => 1, 'ENTITY_TYPE_ID' => 2, 'IS_ENABLED' => 'Y', 'CATEGORY_ID' => 0]]];
DataManager::$fail = [];
ModuleManager::$installed = ['shef.toolsaicrm'];
$sql = new SqlRecorder();
Application::$connection = $sql;
EventManager::$stubHandlers = []; // подписки не встанут: сбой
Maintenance::ensureOnce();
Check::same('сбой: отметки версии нет', isset(Option::$values['shef.toolsaicrm'][Maintenance::OPTION_VERSION]), false);
Check::same('сбой: время сбоя записано', isset(Option::$values['shef.toolsaicrm'][Maintenance::OPTION_FAILED]), true);
$queries = count($sql->queries);
Maintenance::reset();
Maintenance::ensureOnce();
Check::same('повтор раньше RETRY — не пробуем (запросов нет)', count($sql->queries), $queries);
Option::$values['shef.toolsaicrm'][Maintenance::OPTION_FAILED] = (string)(time() - Maintenance::RETRY - 1);
$seedHandlers();
Maintenance::reset();
Maintenance::ensureOnce();
Check::same('после RETRY и успеха — отметка версии записана', Option::$values['shef.toolsaicrm'][Maintenance::OPTION_VERSION] ?? null, Maintenance::stamp($root));
Check::same('отметка сбоя снята', isset(Option::$values['shef.toolsaicrm'][Maintenance::OPTION_FAILED]), false);
$queries = count($sql->queries);
Maintenance::reset();
Maintenance::ensureOnce();
Check::same('отметка совпала — доведения нет', count($sql->queries), $queries);
// endregion ////

Check::finish();
