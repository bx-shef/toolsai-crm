<?php declare(strict_types=1);

/**
 * Перенос данных анализа из shef.toolsai (#639): Main\Migration без базы — на порте в памяти,
 * плюс SQL настоящего порта (Main\CoreMigrationPort) на подставном соединении.
 *
 * Что держит:
 *
 * * данные не теряются: копия с теми же ID, сверка по каждой таблице; сверка, которая не сошлась
 *   (копия потеряла строки, суммы разошлись, таблицы приёмника нет), ничего не помечает и
 *   снимает прежние отметки; из источника ничего не удаляется ни при каких исходах;
 * * старое остановлено ДО копии: агенты анализа и DealHealthAgent shef.toolsai сняты, чужие агенты
 *   (теги звонков) целы, подписки сняты и с ведущим «\», и без;
 * * повтор безопасен: ничего не дублируется, записанное не затирается; настройки, которые
 *   администратор поправил уже в этом модуле, остаются его;
 * * ANALYSIS_*_<тип> переносятся все; DEAL_crmevents копируется, в источнике остаётся;
 * * промпты: только analysis.%, ключ — CODE, чужой код не затирается;
 * * сбой порта — строка отчёта, исключения наружу нет;
 * * SQL: INSERT IGNORE ... SELECT пакетами, сверка LEFT JOIN, префикс CODE экранируется.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';
require_once $root.'/tests/stub/install.php';

use Bitrix\Main\Application;
use Shef\ToolsAiCrm\Main\Constants;
use Shef\ToolsAiCrm\Main\CoreMigrationPort;
use Shef\ToolsAiCrm\Main\Migration;

const T_SRC_PROFILE = 'shef_toolsai_deal_profile';
const T_DST_PROFILE = 'shef_toolsaicrm_deal_profile';
const T_SRC_CHECK = 'shef_toolsai_entity_check';
const T_DST_CHECK = 'shef_toolsaicrm_entity_check';
const T_SRC_PROMPT = 'shef_toolsai_prompt';
const T_DST_PROMPT = 'shef_toolsaicrm_prompt';

/** Портал с данными анализа в shef.toolsai и пустыми таблицами этого модуля. */
$fixture = static function(): MemoryPort
{
	$port = new MemoryPort();
	$port->addTable(T_SRC_PROFILE, ['ID', 'TITLE', 'IS_ENABLED', 'ENTITY_TYPE_ID'], [
		['ID' => 1, 'TITLE' => 'Сделки', 'IS_ENABLED' => 'Y', 'ENTITY_TYPE_ID' => 2],
		['ID' => 7, 'TITLE' => 'Лиды', 'IS_ENABLED' => 'Y', 'ENTITY_TYPE_ID' => 1],
	]);
	// у приёмника есть столбец, которого в старой таблице нет (ACTIVE_DAYS): копируются общие
	$port->addTable(T_DST_PROFILE, ['ID', 'TITLE', 'IS_ENABLED', 'ENTITY_TYPE_ID', 'ACTIVE_DAYS']);
	$port->addTable(T_SRC_CHECK, ['ID', 'ENTITY_TYPE_ID', 'ENTITY_ID', 'RISK'], [
		['ID' => 10, 'ENTITY_TYPE_ID' => 2, 'ENTITY_ID' => 100, 'RISK' => 55],
		['ID' => 11, 'ENTITY_TYPE_ID' => 2, 'ENTITY_ID' => 101, 'RISK' => 80],
		['ID' => 12, 'ENTITY_TYPE_ID' => 1, 'ENTITY_ID' => 5, 'RISK' => 10],
	]);
	$port->addTable(T_DST_CHECK, ['ID', 'ENTITY_TYPE_ID', 'ENTITY_ID', 'RISK']);
	$port->addTable('shef_toolsai_analysis_history', ['ID', 'ENTITY_TYPE_ID', 'ENTITY_ID', 'RISK'], [
		['ID' => 1, 'ENTITY_TYPE_ID' => 2, 'ENTITY_ID' => 100, 'RISK' => 55],
	]);
	$port->addTable('shef_toolsaicrm_analysis_history', ['ID', 'ENTITY_TYPE_ID', 'ENTITY_ID', 'RISK']);
	$port->addTable('shef_toolsai_call_script_rule', ['ID', 'ENTITY_TYPE_ID', 'ASSESSMENT_ID'], [
		['ID' => 3, 'ENTITY_TYPE_ID' => 2, 'ASSESSMENT_ID' => 9],
	]);
	$port->addTable('shef_toolsaicrm_call_script_rule', ['ID', 'ENTITY_TYPE_ID', 'ASSESSMENT_ID']);
	$port->addTable(T_SRC_PROMPT, ['ID', 'CODE', 'SYSTEM_TEXT'], [
		['ID' => 4, 'CODE' => 'analysis.deal', 'SYSTEM_TEXT' => 'своя сделка'],
		['ID' => 5, 'CODE' => 'analysis.lead', 'SYSTEM_TEXT' => 'свой лид'],
		['ID' => 6, 'CODE' => 'copilot.summary', 'SYSTEM_TEXT' => 'чужой промпт ядра'],
	]);
	$port->addTable(T_DST_PROMPT, ['ID', 'CODE', 'SYSTEM_TEXT']);

	$port->options['shef.toolsai'] = [
		'DEAL_enabled' => 'Y',
		'DEAL_maxperrun' => '30',
		'DEAL_interval' => '45',
		'DEAL_crmevents' => 'Y',
		'ANALYSIS_interval_2' => '15',
		'ANALYSIS_interval_1' => '20',
		'ANALYSIS_maxperrun_1000' => '5',
		'ANALYSIS_interval_' => 'мусор без типа',
		'CALLSCRIPT_mode' => 'rules',
		'CALLSCRIPT_fallback' => '77',
		'TAGS_enabled' => 'Y',
	];
	$port->agents['shef.toolsai'] = [
		10 => '\\Shef\\ToolsAi\\Agent\\AnalysisAgent::run(2);',
		11 => '\\Shef\\ToolsAi\\Agent\\AnalysisAgent::run(1);',
		12 => '\\Shef\\ToolsAi\\Agent\\DealHealthAgent::run();',
		13 => '\\Shef\\ToolsAi\\Agent\\CallTagAgent::run();',
	];

	return $port;
};

$ids = static fn(MemoryPort $port, string $table): array => array_map(static fn(array $row): int => (int)$row['ID'], $port->rows($table));
$stepOk = static fn(array $result, string $step): bool => (bool)($result['steps'][$step]['ok'] ?? false);

// region Чистые функции ////
Check::group('имена старых агентов анализа');
foreach([
	'\\Shef\\ToolsAi\\Agent\\AnalysisAgent::run(2);' => true,
	'Shef\\ToolsAi\\Agent\\AnalysisAgent::run(1234);' => true,
	'\\Shef\\ToolsAi\\Agent\\DealHealthAgent::run();' => true,
	'\\Shef\\ToolsAi\\Agent\\CallTagAgent::run();' => false,
	'\\Shef\\ToolsAi\\Agent\\GlossaryAgent::run();' => false,
	'\\Shef\\ToolsAiCrm\\Agent\\AnalysisAgent::run(2);' => false,
	'\\Shef\\ToolsAi\\Agent\\AnalysisAgentX::run(2);' => false,
] as $name => $expected)
{
	Check::same($name, Migration::isOldAnalysisAgent($name), $expected);
}

Check::group('какие настройки переносятся');
$picked = Migration::pickOptions([
	'DEAL_enabled' => 'Y', 'DEAL_crmevents' => 'N', 'ANALYSIS_interval_2' => '15', 'ANALYSIS_maxperrun_1000' => '5',
	'ANALYSIS_interval_' => 'x', 'ANALYSIS_other_2' => 'y', 'TAGS_enabled' => 'Y', 'SYS_token' => 'secret',
	'CALLSCRIPT_mode' => 'rules', 'CALLSCRIPT_fallback' => '7', 'DEAL_interval' => '30', 'DEAL_maxperrun' => '10',
]);
Check::same('список и префиксы, без чужого и без пустого хвоста', array_keys($picked), [
	'ANALYSIS_interval_2', 'ANALYSIS_maxperrun_1000', 'CALLSCRIPT_fallback', 'CALLSCRIPT_mode',
	'DEAL_crmevents', 'DEAL_enabled', 'DEAL_interval', 'DEAL_maxperrun',
]);

Check::group('имена таблиц и коды настроек — как в классах и в Config');
Check::same('приёмники — getTableName() классов', array_column(Migration::TABLES, 1), [
	\Shef\ToolsAiCrm\Deal\Model\DealProfileTable::getTableName(),
	\Shef\ToolsAiCrm\Analysis\Model\EntityCheckTable::getTableName(),
	\Shef\ToolsAiCrm\Analysis\Model\HistoryTable::getTableName(),
	\Shef\ToolsAiCrm\CallScript\Model\CallScriptRuleTable::getTableName(),
	\Shef\ToolsAiCrm\Analysis\Model\PromptTable::getTableName(),
]);
Check::same('источники — сырые shef_toolsai_*', array_column(Migration::TABLES, 0), [
	'shef_toolsai_deal_profile', 'shef_toolsai_entity_check', 'shef_toolsai_analysis_history', 'shef_toolsai_call_script_rule', 'shef_toolsai_prompt',
]);
Check::same('префиксы настроек — как у Config', Migration::OPTION_PREFIXES, [\Shef\ToolsAiCrm\Config::OPTION_ANALYSIS_INTERVAL, \Shef\ToolsAiCrm\Config::OPTION_ANALYSIS_MAX_PER_RUN]);
Check::same('CALLSCRIPT — как у Events', [Migration::OPTIONS[4], Migration::OPTIONS[5]], [\Shef\ToolsAiCrm\CallScript\Events::OPTION_MODE, \Shef\ToolsAiCrm\CallScript\Events::OPTION_FALLBACK]);
Check::same('DEAL_crmevents — константа модуля', in_array(Constants::OPTION_CRMEVENTS, Migration::OPTIONS, true), true);
Check::same('подписки, что снимаются, — те же, что ставит .settings.php (класс shef.toolsai вместо нашего)', array_map(
	static fn(array $e): string => $e[0].':'.$e[1].' '.str_replace('ToolsAi\\', 'ToolsAiCrm\\', $e[2]).'::'.$e[3],
	Migration::OLD_EVENTS
), array_map(
	static fn(array $e): string => $e['from']['module'].':'.$e['from']['event'].' '.$e['to']['class'].'::'.$e['to']['function'],
	array_values(array_filter(
		(require $root.'/.settings.php')['installEvents']['value'],
		static fn(array $e): bool => str_contains($e['to']['class'], 'Deal\\Field\\') || str_contains($e['to']['class'], 'CallScript\\')
	))
));
// endregion ////

// region Полный перенос ////
Check::group('перенос: копия, сверка, отметки');
$port = $fixture();
$result = (new Migration($port))->run();
Check::same('всё ок', $result['ok'], true);
Check::same('отметки поставлены', $result['marked'], true);
foreach([[T_SRC_PROFILE, T_DST_PROFILE], [T_SRC_CHECK, T_DST_CHECK], ['shef_toolsai_analysis_history', 'shef_toolsaicrm_analysis_history'], ['shef_toolsai_call_script_rule', 'shef_toolsaicrm_call_script_rule']] as [$from, $to])
{
	Check::same('те же ID: '.$to, $ids($port, $to), $ids($port, $from));
}
Check::same('строки — не только ID (столбцы общие)', $port->rows(T_DST_CHECK)[1], ['ID' => 11, 'ENTITY_TYPE_ID' => 2, 'ENTITY_ID' => 101, 'RISK' => 80]);
Check::same('столбец, которого нет в источнике, не выдуман', array_key_exists('ACTIVE_DAYS', $port->rows(T_DST_PROFILE)[0]), false);
Check::same('источник цел: строки', $ids($port, T_SRC_CHECK), [10, 11, 12]);
Check::same('источник цел: профили', $ids($port, T_SRC_PROFILE), [1, 7]);

Check::group('перенос: промпты — только analysis.%, ключ — CODE');
Check::same('перенесены analysis.deal и analysis.lead', array_column($port->rows(T_DST_PROMPT), 'CODE'), ['analysis.deal', 'analysis.lead']);
Check::same('чужой промпт не взят', in_array('copilot.summary', array_column($port->rows(T_DST_PROMPT), 'CODE'), true), false);
Check::same('тексты на месте', array_column($port->rows(T_DST_PROMPT), 'SYSTEM_TEXT'), ['своя сделка', 'свой лид']);
Check::same('источник промптов цел (3 строки)', count($port->rows(T_SRC_PROMPT)), 3);

Check::group('перенос: настройки');
$own = $port->options['shef.toolsaicrm'];
Check::same('DEAL_* и CALLSCRIPT_*', [$own['DEAL_enabled'], $own['DEAL_maxperrun'], $own['DEAL_interval'], $own['CALLSCRIPT_mode'], $own['CALLSCRIPT_fallback']], ['Y', '30', '45', 'rules', '77']);
Check::same('DEAL_crmevents скопирован', $own['DEAL_crmevents'], 'Y');
Check::same('DEAL_crmevents в shef.toolsai остался', $port->options['shef.toolsai']['DEAL_crmevents'], 'Y');
Check::same('ANALYSIS_*_<тип> — все', [$own['ANALYSIS_interval_2'], $own['ANALYSIS_interval_1'], $own['ANALYSIS_maxperrun_1000']], ['15', '20', '5']);
Check::same('«ANALYSIS_interval_» без типа не перенесён', isset($own['ANALYSIS_interval_']), false);
Check::same('чужая настройка (теги звонков) не перенесена', isset($own['TAGS_enabled']), false);
Check::same('в shef.toolsai ничего не удалено (все 11 на месте)', count($port->options['shef.toolsai']) - 1, 11);

Check::group('перенос: отметки договора');
$stamp = $own[Constants::OPTION_MIGRATED] ?? '';
Check::same('SYS_migrated_from_toolsai в shef.toolsaicrm', preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\|/', $stamp), 1);
Check::same('SYS_crm_moved в shef.toolsai — то же значение', $port->options['shef.toolsai'][Constants::CORE_OPTION_MIGRATED] ?? null, $stamp);
Check::same('в отметке числа строк', str_contains($stamp, 'entity_check=3') && str_contains($stamp, 'deal_profile=2') && str_contains($stamp, 'prompt=2'), true);

Check::group('перенос: старое остановлено до копии');
Check::same('сняты агенты анализа и DealHealthAgent', $port->deletedAgents, [10, 11, 12]);
Check::same('чужой агент (теги звонков) цел', $port->agents['shef.toolsai'], [13 => '\\Shef\\ToolsAi\\Agent\\CallTagAgent::run();']);
$firstCopy = array_search('copy '.T_SRC_PROFILE, $port->log, true);
$lastDelete = max(array_keys(array_filter($port->log, static fn(string $line): bool => str_starts_with($line, 'delete agent'))));
$lastUnregister = max(array_keys(array_filter($port->log, static fn(string $line): bool => str_starts_with($line, 'unregister'))));
Check::same('агенты сняты раньше первой копии', $lastDelete < $firstCopy, true);
Check::same('подписки сняты раньше первой копии', $lastUnregister < $firstCopy, true);
Check::same('подписки: каждая в двух видах (без «\\» и с ним), модуль-владелец shef.toolsai', count($port->unregistered), count(Migration::OLD_EVENTS) * 2);
Check::same('снимается подписка скриптов звонков', in_array(['crm', 'OnBeforeCrmActivityAdd', 'shef.toolsai', 'Shef\\ToolsAi\\CallScript\\Events', 'onBeforeActivityAdd'], $port->unregistered, true), true);
Check::same('и с ведущим «\\»', in_array(['main', 'OnUserTypeBuildList', 'shef.toolsai', '\\Shef\\ToolsAi\\Deal\\Field\\AckType', 'getUserTypeDescription'], $port->unregistered, true), true);
Check::same('чужие модули не тронуты: снимали только у shef.toolsai', array_unique(array_column($port->unregistered, 2)), ['shef.toolsai']);
// endregion ////

// region Повтор ////
Check::group('повтор: ничего не плодит и не затирает');
$port->options['shef.toolsaicrm']['DEAL_interval'] = '120'; // администратор поправил уже здесь
$port->options['shef.toolsaicrm']['ANALYSIS_interval_2'] = '99';
$port->tables[T_DST_PROMPT]['rows'][0]['SYSTEM_TEXT'] = 'правка администратора'; // и промпт
$before = $port->tables;
$port->batches = [];
$again = (new Migration($port))->run();
Check::same('снова ок', $again['ok'], true);
Check::same('строки не размножились', $ids($port, T_DST_CHECK), [10, 11, 12]);
Check::same('промптов — по-прежнему два', count($port->rows(T_DST_PROMPT)), 2);
Check::same('правка промпта цела', $port->rows(T_DST_PROMPT)[0]['SYSTEM_TEXT'], 'правка администратора');
Check::same('настройка администратора цела: DEAL_interval', $port->options['shef.toolsaicrm']['DEAL_interval'], '120');
Check::same('настройка администратора цела: ANALYSIS_interval_2', $port->options['shef.toolsaicrm']['ANALYSIS_interval_2'], '99');
Check::same('таблицы приёмника не изменились (кроме правок)', $port->tables === $before, true);
Check::same('отметка — время первого переноса', $port->options['shef.toolsaicrm'][Constants::OPTION_MIGRATED], $stamp);
Check::same('агенты не снимались второй раз', $port->deletedAgents, [10, 11, 12]);

Check::group('повтор добирает недостающее');
$port->options['shef.toolsai']['ANALYSIS_interval_3'] = '33';
$port->tables[T_SRC_CHECK]['rows'][] = ['ID' => 13, 'ENTITY_TYPE_ID' => 2, 'ENTITY_ID' => 102, 'RISK' => 1];
$port->tables[T_SRC_PROMPT]['rows'][] = ['ID' => 8, 'CODE' => 'analysis.dynamic', 'SYSTEM_TEXT' => 'смарт'];
$third = (new Migration($port))->run();
Check::same('ок', $third['ok'], true);
Check::same('новая строка добрана', $ids($port, T_DST_CHECK), [10, 11, 12, 13]);
Check::same('новая настройка добрана', $port->options['shef.toolsaicrm']['ANALYSIS_interval_3'], '33');
Check::same('новый промпт добран', array_column($port->rows(T_DST_PROMPT), 'CODE'), ['analysis.deal', 'analysis.lead', 'analysis.dynamic']);

Check::group('повтор: приёмник уже занял ID другой строкой — ID источника не затирает чужое');
$port = $fixture();
$port->tables[T_DST_CHECK]['rows'][] = ['ID' => 10, 'ENTITY_TYPE_ID' => 2, 'ENTITY_ID' => 100, 'RISK' => 55]; // уже перенесена
$result = (new Migration($port))->run();
Check::same('ок (строка уже там)', $result['ok'], true);
Check::same('без дублей', $ids($port, T_DST_CHECK), [10, 11, 12]);
// endregion ////

Check::group('промпты: ID источника в приёмнике занят другим кодом — промпт всё равно переносится (ID выдаёт приёмник)');
$port = $fixture();
$port->tables[T_DST_PROMPT]['rows'][] = ['ID' => 4, 'CODE' => 'analysis.custom', 'SYSTEM_TEXT' => 'уже здесь'];
$result = (new Migration($port))->run();
Check::same('ок', $result['ok'], true);
Check::same('оба кода на месте', array_column($port->rows(T_DST_PROMPT), 'CODE'), ['analysis.custom', 'analysis.deal', 'analysis.lead']);
Check::same('занятая строка цела', $port->rows(T_DST_PROMPT)[0]['SYSTEM_TEXT'], 'уже здесь');

// region Сверка не сошлась ////
Check::group('сверка не сошлась (копия потеряла строки): ничего не помечено, старое не тронуто');
$port = $fixture();
$port->lossy = true;
$result = (new Migration($port))->run();
Check::same('не ок', $result['ok'], false);
Check::same('отметки не поставлены', $result['marked'], false);
Check::same('нет SYS_migrated_from_toolsai', isset($port->options['shef.toolsaicrm'][Constants::OPTION_MIGRATED]), false);
Check::same('нет SYS_crm_moved', isset($port->options['shef.toolsai'][Constants::CORE_OPTION_MIGRATED]), false);
Check::same('сверка сообщает, чего не хватает', str_contains($result['steps']['migrate '.T_SRC_CHECK]['message'], 'без пары'), true);
Check::same('шаг отметок — не ок и объясняет', $stepOk($result, 'migrate mark'), false);
Check::same('источник цел: проверки', $ids($port, T_SRC_CHECK), [10, 11, 12]);
Check::same('источник цел: профили', $ids($port, T_SRC_PROFILE), [1, 7]);
Check::same('источник цел: промпты', count($port->rows(T_SRC_PROMPT)), 3);
Check::same('настройки shef.toolsai целы', count($port->options['shef.toolsai']), 11);
Check::same('из источника не удалено ни одной строки: порт копий — только вставки', array_filter($port->log, static fn(string $l): bool => str_starts_with($l, 'delete option shef.toolsai ') && !str_contains($l, 'SYS_crm_moved')), []);
Check::same('установка не падает: исключения нет, отчёт есть', is_array($result['steps']), true);

Check::group('сверка не сошлась: прежняя отметка снимается');
$port = $fixture();
(new Migration($port))->run();
Check::same('(отметка стояла)', isset($port->options['shef.toolsai'][Constants::CORE_OPTION_MIGRATED]), true);
$port->tables[T_SRC_CHECK]['rows'][] = ['ID' => 50, 'ENTITY_TYPE_ID' => 2, 'ENTITY_ID' => 9, 'RISK' => 3];
$port->lossy = true;
$result = (new Migration($port))->run();
Check::same('не ок', $result['ok'], false);
Check::same('SYS_crm_moved снята — shef.toolsai не уберёт то, что не перенесено', isset($port->options['shef.toolsai'][Constants::CORE_OPTION_MIGRATED]), false);
Check::same('и своя отметка снята', isset($port->options['shef.toolsaicrm'][Constants::OPTION_MIGRATED]), false);

Check::group('сверка: расхождение сумм ключей — тоже отказ');
Check::same('missing = 0, суммы равны', Migration::isMatched(['source' => 3, 'missing' => 0, 'sumSource' => '33', 'sumTarget' => '33']), true);
Check::same('missing > 0', Migration::isMatched(['source' => 3, 'missing' => 1, 'sumSource' => '33', 'sumTarget' => '22']), false);
Check::same('missing > 0, суммы случайно равны', Migration::isMatched(['source' => 3, 'missing' => 1, 'sumSource' => '33', 'sumTarget' => '33']), false);
Check::same('missing = 0, но суммы разошлись', Migration::isMatched(['source' => 3, 'missing' => 0, 'sumSource' => '33', 'sumTarget' => '34']), false);

Check::group('нет таблицы приёмника — отказ, не пометка');
$port = $fixture();
unset($port->tables[T_DST_CHECK]);
$result = (new Migration($port))->run();
Check::same('не ок', $result['ok'], false);
Check::same('не помечено', $result['marked'], false);
Check::same('сказано, какой таблицы нет', str_contains($result['steps']['migrate '.T_SRC_CHECK]['message'], T_DST_CHECK), true);

Check::group('настройка не перенеслась — отказ');
$port = $fixture();
$broken = new class($port) implements \Shef\ToolsAiCrm\Main\MigrationPort
{
	public function __construct(private readonly MemoryPort $inner)
	{
	}
	public function tableExists(string $table): bool { return $this->inner->tableExists($table); }
	public function columns(string $table): array { return $this->inner->columns($table); }
	public function batchEnd(string $table, ?string $codePrefix, int $fromId, int $limit): ?int { return $this->inner->batchEnd($table, $codePrefix, $fromId, $limit); }
	public function copyRange(string $source, string $target, array $columns, string $key, ?string $codePrefix, int $fromId, int $toId): int { return $this->inner->copyRange($source, $target, $columns, $key, $codePrefix, $fromId, $toId); }
	public function compare(string $source, string $target, string $key, ?string $codePrefix): array { return $this->inner->compare($source, $target, $key, $codePrefix); }
	public function options(string $moduleId): array { return $this->inner->options($moduleId); }
	/** Запись настроек модуля «не доходит» (например, база только на чтение). */
	public function setOption(string $moduleId, string $name, string $value): void { if($moduleId === 'shef.toolsai') { $this->inner->setOption($moduleId, $name, $value); } }
	public function deleteOption(string $moduleId, string $name): void { $this->inner->deleteOption($moduleId, $name); }
	public function agents(string $moduleId): array { return $this->inner->agents($moduleId); }
	public function deleteAgent(int $agentId): void { $this->inner->deleteAgent($agentId); }
	public function unregisterEvent(string $fromModule, string $event, string $toModule, string $class, string $method): void { $this->inner->unregisterEvent($fromModule, $event, $toModule, $class, $method); }
	public function oldRunInProgress(): bool { return $this->inner->oldRunInProgress(); }
	public function sleep(int $seconds): void { $this->inner->sleep($seconds); }
	public function now(): int { return $this->inner->now(); }
};
$result = (new Migration($broken))->run();
Check::same('не ок', $result['ok'], false);
Check::same('не помечено', isset($port->options['shef.toolsai'][Constants::CORE_OPTION_MIGRATED]), false);
Check::same('перечислены ненайденные настройки', str_contains($result['steps']['migrate options']['message'], 'DEAL_enabled'), true);
// endregion ////

// region Пропуски ////
Check::group('таблицы нет в источнике — пропуск, остальное идёт');
$port = $fixture();
unset($port->tables['shef_toolsai_call_script_rule']);
$result = (new Migration($port))->run();
Check::same('ок', $result['ok'], true);
Check::same('пропуск назван', str_contains($result['steps']['migrate shef_toolsai_call_script_rule']['message'], 'пропуск'), true);
Check::same('остальные перенесены', $ids($port, T_DST_CHECK), [10, 11, 12]);

Check::group('в таблице нет строк — пропуск, но отметка нужна (shef.toolsai уберёт пустую таблицу)');
$port = $fixture();
foreach([T_SRC_PROFILE, T_SRC_CHECK, 'shef_toolsai_analysis_history', 'shef_toolsai_call_script_rule'] as $table)
{
	$port->tables[$table]['rows'] = [];
}
$port->tables[T_SRC_PROMPT]['rows'] = [];
$port->options['shef.toolsai'] = [];
$result = (new Migration($port))->run();
Check::same('ок', $result['ok'], true);
Check::same('отметка стоит', $result['marked'], true);
Check::same('приёмник пуст', $port->rows(T_DST_CHECK), []);

Check::group('переносить нечего вообще (старых таблиц и настроек нет) — не помечаем');
$port = new MemoryPort();
$port->addTable(T_DST_CHECK, ['ID']);
$result = (new Migration($port))->run();
Check::same('ок', $result['ok'], true);
Check::same('не помечено', $result['marked'], false);
Check::same('«переносить нечего»', $result['steps']['migrate mark']['message'], 'переносить нечего');
Check::same('ни копий, ни настроек, ни отметок', array_values(array_filter($port->log, static fn(string $l): bool => !str_starts_with($l, 'unregister '))), []);
// endregion ////

// region Старый прогон, сбои ////
Check::group('старый прогон идёт: ждём, потом переносим');
$port = $fixture();
$port->busyPolls = 3;
$result = (new Migration($port))->run();
Check::same('ок', $result['ok'], true);
Check::same('ждали по секунде', $port->slept, 3);

Check::group('старый прогон не кончается: копия идёт, но отметки нет');
$port = $fixture();
$port->busyPolls = 1000;
$result = (new Migration($port))->run();
Check::same('ожидание ограничено', $port->slept, Migration::WAIT_SECONDS);
Check::same('не ок', $result['ok'], false);
Check::same('не помечено', $result['marked'], false);
Check::same('данные скопированы (повтор доберёт)', $ids($port, T_DST_CHECK), [10, 11, 12]);

Check::group('сбой порта: строка отчёта, исключений нет');
foreach(['batchEnd', 'copyRange', 'compare', 'options', 'deleteAgent'] as $method)
{
	$port = $fixture();
	$port->failOn = $method;
	$thrown = null;
	try
	{
		$result = (new Migration($port))->run();
	}
	catch(\Throwable $throwable)
	{
		$thrown = $throwable;
	}
	Check::same('сбой '.$method.': исключения нет', $thrown, null);
	Check::same('сбой '.$method.': не ок и не помечено', [$result['ok'] ?? null, $result['marked'] ?? null], [false, false]);
	Check::same('сбой '.$method.': в источнике ничего не потеряно', $ids($port, T_SRC_CHECK), [10, 11, 12]);
}

Check::group('пакеты: ID с пропусками и больше одного пакета');
$port = $fixture();
$rows = [];
for($i = 1; $i <= 2500; $i++)
{
	$rows[] = ['ID' => $i * 3, 'ENTITY_TYPE_ID' => 2, 'ENTITY_ID' => $i, 'RISK' => 1];
}
$port->tables[T_SRC_CHECK]['rows'] = $rows;
$result = (new Migration($port))->run();
Check::same('ок', $result['ok'], true);
Check::same('все 2500 строк в приёмнике', count($port->rows(T_DST_CHECK)), 2500);
$checkBatches = array_values(array_filter($port->batches, static fn(array $b): bool => $b[0] === T_SRC_CHECK));
Check::same('три пакета', count($checkBatches), 3);
Check::same('пакеты стыкуются без дыр и перекрытий', [$checkBatches[0][2] + 1 === $checkBatches[1][1], $checkBatches[1][2] + 1 === $checkBatches[2][1]], [true, true]);
// endregion ////

// region SQL настоящего порта ////
Check::group('SQL: INSERT IGNORE ... SELECT пакетом, по общим столбцам');
$sql = new SqlRecorder();
Application::$connection = $sql;
$port = new CoreMigrationPort();
$sql->affected = 7;
Check::same('вставлено — по числу затронутых', $port->copyRange(T_SRC_CHECK, T_DST_CHECK, ['ID', 'RISK'], 'ID', null, 1, 1000), 7);
Check::same('запрос пакета', $sql->queries[0], 'INSERT IGNORE INTO shef_toolsaicrm_entity_check (ID, RISK) SELECT s.ID, s.RISK FROM shef_toolsai_entity_check s WHERE s.ID BETWEEN 1 AND 1000');

Check::group('SQL: промпты — только analysis.%, не затирая существующий CODE');
$sql->queries = [];
$port->copyRange(T_SRC_PROMPT, T_DST_PROMPT, ['CODE', 'SYSTEM_TEXT'], 'CODE', 'analysis.', 1, 500);
Check::same('запрос промптов', $sql->queries[0], "INSERT IGNORE INTO shef_toolsaicrm_prompt (CODE, SYSTEM_TEXT) SELECT s.CODE, s.SYSTEM_TEXT FROM shef_toolsai_prompt s WHERE s.ID BETWEEN 1 AND 500 AND s.CODE LIKE 'analysis.%' AND NOT EXISTS (SELECT 1 FROM shef_toolsaicrm_prompt d WHERE d.CODE = s.CODE)");

Check::group('SQL: LIKE-знаки префикса экранируются');
$sql->queries = [];
$port->copyRange(T_SRC_PROMPT, T_DST_PROMPT, ['CODE'], 'CODE', "a_b%'", 1, 2);
Check::same('префикс', str_contains($sql->queries[0], "LIKE 'a\\\\_b\\\\%\\'%'"), true);

Check::group('SQL: сверка — LEFT JOIN по ключу');
$sql->queries = [];
$sql->answers = [['C' => '3', 'M' => '1', 'SS' => '33', 'SD' => '22']];
$compare = $port->compare(T_SRC_CHECK, T_DST_CHECK, 'ID', null);
Check::same('запрос', $sql->queries[0], 'SELECT COUNT(*) AS C, COALESCE(SUM(d.ID IS NULL), 0) AS M, CAST(COALESCE(SUM(s.ID), 0) AS CHAR) AS SS, CAST(COALESCE(SUM(d.ID), 0) AS CHAR) AS SD FROM shef_toolsai_entity_check s LEFT JOIN shef_toolsaicrm_entity_check d ON d.ID = s.ID WHERE 1 = 1');
Check::same('ответ разобран', $compare, ['source' => 3, 'missing' => 1, 'sumSource' => '33', 'sumTarget' => '22']);
$sql->queries = [];
$sql->answers = [['C' => '2', 'M' => '0', 'SS' => '10', 'SD' => '10']];
$port->compare(T_SRC_PROMPT, T_DST_PROMPT, 'CODE', 'analysis.');
Check::same('для CODE — CRC32 и префикс', $sql->queries[0], "SELECT COUNT(*) AS C, COALESCE(SUM(d.CODE IS NULL), 0) AS M, CAST(COALESCE(SUM(CRC32(s.CODE)), 0) AS CHAR) AS SS, CAST(COALESCE(SUM(CRC32(d.CODE)), 0) AS CHAR) AS SD FROM shef_toolsai_prompt s LEFT JOIN shef_toolsaicrm_prompt d ON d.CODE = s.CODE WHERE 1 = 1 AND s.CODE LIKE 'analysis.%'");

Check::group('SQL: конец пакета');
$sql->queries = [];
$sql->answers = [['E' => '1000']];
Check::same('конец пакета', $port->batchEnd(T_SRC_CHECK, null, 1, 1000), 1000);
Check::same('запрос', $sql->queries[0], 'SELECT MAX(t.ID) AS E FROM (SELECT ID FROM shef_toolsai_entity_check WHERE ID >= 1 ORDER BY ID LIMIT 1000) t');
$sql->answers = [['E' => null]];
Check::same('строк больше нет — null', $port->batchEnd(T_SRC_CHECK, null, 5000, 1000), null);

Check::group('SQL: настройки — через Option, чтение — только записанное в базе');
\Bitrix\Main\Config\Option::$values = ['shef.toolsai' => ['DEAL_enabled' => 'Y']];
Check::same('options()', $port->options('shef.toolsai'), ['DEAL_enabled' => 'Y']);
$port->setOption('shef.toolsaicrm', 'DEAL_enabled', 'Y');
Check::same('setOption()', \Bitrix\Main\Config\Option::$values['shef.toolsaicrm'], ['DEAL_enabled' => 'Y']);
$port->deleteOption('shef.toolsaicrm', 'DEAL_enabled');
Check::same('deleteOption()', \Bitrix\Main\Config\Option::$values['shef.toolsaicrm'] ?? [], []);

Check::group('SQL: столбцы — заглавными');
$sql->columns[T_SRC_CHECK] = ['id', 'Risk'];
Check::same('columns()', $port->columns(T_SRC_CHECK), ['ID', 'RISK']);
// endregion ////

Check::finish();
