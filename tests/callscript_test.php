<?php declare(strict_types=1);

/**
 * #408, часть B: правила скриптов оценки звонков — разбор и форма, подбор от
 * точного к общему, запись звонка, запасной скрипт, звонок телефонии.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Application;
use Shef\ToolsAiCrm\CallScript\Events;
use Shef\ToolsAiCrm\CallScript\Model\CallScriptRuleTable;
use Shef\ToolsAiCrm\CallScript\Rule;
use Shef\ToolsAiCrm\CallScript\RulePicker;

Check::group('разбор и форма');

Check::same('по умолчанию: любая воронка, любая стадия', [Rule::fromRow([])->categoryId, Rule::fromRow([])->stages, Rule::fromRow([])->stageSemantic], [-1, [], '']);
Check::same('у «любой воронки» стадий нет', Rule::fromRow(['CATEGORY_ID' => -1, 'STAGES' => 'NEW'])->stages, []);
[$fields, $errors] = Rule::fromInput(['PLACE' => '2:3', 'STAGES' => ['C3:NEW'], 'ASSESSMENT_ID' => '7', 'IS_ENABLED' => 'Y']);
Check::same('форма: сделка воронки 3', [$fields['ENTITY_TYPE_ID'], $fields['CATEGORY_ID'], $fields['STAGES'], $fields['ASSESSMENT_ID'], $errors], [2, 3, 'C3:NEW', 7, []]);
[$fields, $errors] = Rule::fromInput(['PLACE' => '2:*', 'STAGE_SEMANTIC' => 'S', 'ASSESSMENT_ID' => '7']);
Check::same('форма: любая воронка, успех', [$fields['CATEGORY_ID'], $fields['STAGE_SEMANTIC'], $errors], [-1, 'S', []]);
[, $errors] = Rule::fromInput(['PLACE' => 'x', 'ASSESSMENT_ID' => '0', 'SORT' => 'abc']);
Check::same('форма: мусор — ошибки, не молча', $errors, ['PLACE', 'ASSESSMENT_ID', 'SORT']);
Check::same('ключ места', [Rule::fromRow(['ENTITY_TYPE_ID' => 2, 'CATEGORY_ID' => 3])->placeKey(), Rule::fromRow(['ENTITY_TYPE_ID' => 2])->placeKey()], ['2:3', '2:*']);

Check::group('подбор от точного к общему');

$r = static fn(int $id, array $row): Rule => Rule::fromRow($row + ['ID' => $id, 'ENTITY_TYPE_ID' => 2, 'ASSESSMENT_ID' => 100 + $id]);
$rules = [
	$r(1, ['CATEGORY_ID' => -1]),
	$r(2, ['CATEGORY_ID' => 3]),
	$r(3, ['CATEGORY_ID' => 3, 'STAGES' => 'C3:NEW']),
	$r(4, ['CATEGORY_ID' => -1, 'STAGE_SEMANTIC' => 'F']),
	$r(5, ['CATEGORY_ID' => 3, 'STAGE_SEMANTIC' => 'F']),
	$r(6, ['ENTITY_TYPE_ID' => 1, 'CATEGORY_ID' => -1]),
];
$all = [101, 102, 103, 104, 105, 106];
$pick = static fn(int $type, int $cat, string $stage, string $sem, array $avail = []) => RulePicker::pick($rules, $type, $cat, $stage, $sem, $avail ?: $all)?->id;
Check::same('воронка + стадия', $pick(2, 3, 'C3:NEW', ''), 3);
Check::same('воронка', $pick(2, 3, 'C3:OFFER', ''), 2);
Check::same('другая воронка — любая воронка', $pick(2, 5, 'C5:NEW', ''), 1);
Check::same('провал: воронка главнее любой', $pick(2, 3, 'C3:LOSE', 'F'), 5);
Check::same('провал в другой воронке — правило «любая воронка, провал»', $pick(2, 5, 'C5:LOSE', 'F'), 4);
Check::same('успех без правила на итог — никакое', $pick(2, 3, 'C3:WON', 'S'), null);
Check::same('лид — своё правило', $pick(1, 0, 'NEW', ''), 6);
Check::same('смарт-процесс без правил — нет', $pick(130, 0, 'DT130_1:NEW', ''), null);
Check::same('скрипт выключен — следующее правило', $pick(2, 3, 'C3:NEW', '', [101, 102]), 2);
Check::same('выключенное правило — мимо', RulePicker::pick([$r(9, ['CATEGORY_ID' => 3, 'IS_ENABLED' => 'N'])], 2, 3, '', '', [109]), null);

Check::group('запись звонка');

Check::same('владелец — сделка', RulePicker::pickOwner(2, 10, [['OWNER_TYPE_ID' => 1, 'OWNER_ID' => 5]]), [2, 10]);
Check::same('владелец — контакт: сделка из привязок главнее лида', RulePicker::pickOwner(3, 7, [['OWNER_TYPE_ID' => 1, 'OWNER_ID' => 50], ['OWNER_TYPE_ID' => 2, 'OWNER_ID' => 8], ['OWNER_TYPE_ID' => 2, 'OWNER_ID' => 9]]), [2, 9]);
Check::same('смарт-процесс главнее лида', RulePicker::pickOwner(0, 0, [['OWNER_TYPE_ID' => 1, 'OWNER_ID' => 50], ['OWNER_TYPE_ID' => 130, 'OWNER_ID' => 4]]), [130, 4]);
Check::same('только контакт и компания — нет записи', RulePicker::pickOwner(3, 7, [['OWNER_TYPE_ID' => 4, 'OWNER_ID' => 1]]), null);

Check::group('выбор и запасной');

Check::same('правило', Events::choose($rules, [2, 3, 'C3:NEW', ''], $all, 0), 103);
Check::same('правило не подошло — запасной', Events::choose($rules, [130, 0, 'X', ''], $all, 106), 106);
Check::same('запасной выключен — штатно (0)', Events::choose($rules, [130, 0, 'X', ''], [101], 106), 0);
Check::same('нет записи — запасной', Events::choose($rules, null, $all, 102), 102);
Check::same('пустой запасной — штатно', Events::choose($rules, null, $all, 0), 0);

Check::group('звонок телефонии');

Check::same('звонок Битрикс24', Events::callId(['TYPE_ID' => 2, 'ORIGIN_ID' => 'VI_abc.123']), 'abc.123');
Check::same('сторонняя телефония — нет', Events::callId(['TYPE_ID' => 2, 'ORIGIN_ID' => 'REST_1']), null);
Check::same('не звонок — нет', Events::callId(['TYPE_ID' => 4, 'ORIGIN_ID' => 'VI_x']), null);
Check::same('старое ядро (без закрепления скрипта) — подбор недоступен', Events::isSupported(), false);
Check::same('режим по умолчанию — штатно', Events::isRulesMode(), false);
$fields = ['TYPE_ID' => 2, 'ORIGIN_ID' => 'VI_x'];
Check::same('штатно — обработчик ничего не делает и не мешает записи', Events::onBeforeActivityAdd($fields), true);

Check::group('таблица');

$connection = Application::getConnection();
$connection->tables = [];
$connection->queries = [];
CallScriptRuleTable::init();
Check::same('создаётся с воронкой -1 по умолчанию', str_contains($connection->queries[0] ?? '', 'CATEGORY_ID INT(11) NOT NULL DEFAULT -1'), true);
$connection->tables = ['shef_toolsaicrm_call_script_rule'];
$connection->queries = [];
CallScriptRuleTable::init();
Check::same('есть — ничего', $connection->queries, []);
$connection->tables = [];

Check::finish();
