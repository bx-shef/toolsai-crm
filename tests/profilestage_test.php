<?php declare(strict_types=1);

/**
 * #408: профиль анализа по стадии — стадии (группа), итог «успех»/«провал»
 * (семантика S/F), подбор от точного к общему, один раз после закрытия,
 * миграция столбцов.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Application;
use Shef\ToolsAiCrm\Deal\ClientType;
use Shef\ToolsAiCrm\Deal\Model\DealProfileTable;
use Shef\ToolsAiCrm\Deal\Profile;
use Shef\ToolsAiCrm\Deal\ProfilePicker;

Check::group('разбор и форма');

Check::same('по умолчанию — любая стадия', [Profile::fromRow([])->stages, Profile::fromRow([])->stageSemantic], [[], '']);
Check::same('стадии из строки, без повторов и мусора', Profile::fromRow(['STAGES' => 'C3:NEW, C3:WON,C3:NEW,<x>,'])->stages, ['C3:NEW', 'C3:WON']);
Check::same('итог S/F', [Profile::fromRow(['STAGE_SEMANTIC' => 'S'])->stageSemantic, Profile::fromRow(['STAGE_SEMANTIC' => 'F'])->stageSemantic, Profile::fromRow(['STAGE_SEMANTIC' => 'P'])->stageSemantic], ['S', 'F', '']);
Check::same('итог задан — стадии не действуют', Profile::fromRow(['STAGE_SEMANTIC' => 'F', 'STAGES' => 'NEW'])->stages, []);

[$fields, $errors] = Profile::fromInput(['TITLE' => 'x', 'STAGES' => ['NEW', 'PREPARATION']]);
Check::same('форма: стадии списком', [$fields['STAGES'], $fields['STAGE_SEMANTIC'], $errors], ['NEW,PREPARATION', '', []]);
[$fields, $errors] = Profile::fromInput(['TITLE' => 'x', 'STAGES' => ['NEW'], 'STAGE_SEMANTIC' => 'S']);
Check::same('форма: итог — стадии пусто', [$fields['STAGES'], $fields['STAGE_SEMANTIC'], $errors], ['', 'S', []]);
[, $errors] = Profile::fromInput(['TITLE' => 'x', 'STAGE_SEMANTIC' => 'X']);
Check::same('форма: чужой итог — ошибка', $errors, ['STAGE_SEMANTIC']);
[, $errors] = Profile::fromInput(['TITLE' => 'x', 'STAGES' => ['NEW', 'bad code']]);
Check::same('форма: чужой код стадии — ошибка, не молча', $errors, ['STAGES']);

Check::group('подбор от точного к общему');

$p = static fn(int $id, array $row): Profile => Profile::fromRow($row + ['ID' => $id, 'TITLE' => 'p'.$id, 'IS_ENABLED' => 'Y', 'CATEGORY_ID' => 3]);
$profiles = [
	$p(1, []),
	$p(2, ['CLIENT_TYPES' => (string)ClientType::NEW]),
	$p(3, ['STAGES' => 'C3:NEW,C3:PREPARE']),
	$p(4, ['STAGES' => 'C3:NEW', 'CLIENT_TYPES' => (string)ClientType::NEW]),
	$p(5, ['STAGE_SEMANTIC' => 'S']),
	$p(6, ['STAGE_SEMANTIC' => 'F']),
];
$pick = static fn(?int $type, string $stage, string $sem): ?int => ProfilePicker::pick($profiles, 3, $type, null, $stage, $sem)?->id;
Check::same('стадия + тип клиента', $pick(ClientType::NEW, 'C3:NEW', ''), 4);
Check::same('стадия (группа), тип не совпал', $pick(null, 'C3:NEW', ''), 3);
Check::same('стадия группы и тип клиента у другого — стадия главнее', $pick(ClientType::NEW, 'C3:PREPARE', ''), 3);
Check::same('стадия вне профилей + тип клиента', $pick(ClientType::NEW, 'C3:OFFER', ''), 2);
Check::same('общий', $pick(null, 'C3:OFFER', ''), 1);
Check::same('успех', $pick(null, 'C3:WON', 'S'), 5);
Check::same('провал', $pick(ClientType::NEW, 'C3:LOSE', 'F'), 6);
Check::same('стадия неизвестна — только «любая»', $pick(null, '', ''), 1);
Check::same('закрытая без профиля на её итог — никакой (не «любая стадия»)', ProfilePicker::pick([$p(1, []), $p(5, ['STAGE_SEMANTIC' => 'S'])], 3, null, null, 'C3:LOSE', 'F'), null);
Check::same('закрытая: профиль итога с чужим типом клиента — никакой', ProfilePicker::pick([$p(1, []), $p(9, ['STAGE_SEMANTIC' => 'S', 'CLIENT_TYPES' => (string)ClientType::NEW])], 3, ClientType::IN_WORK, null, 'C3:WON', 'S'), null);
Check::same('вызов без стадии — как раньше', ProfilePicker::pick($profiles, 3, ClientType::NEW)?->id, 2);
Check::same('равные по точности — по SORT', ProfilePicker::pick([$p(7, ['STAGES' => 'C3:NEW', 'SORT' => 200]), $p(8, ['STAGES' => 'C3:NEW,C3:X', 'SORT' => 50])], 3, null, null, 'C3:NEW', '')?->id, 8);

$lead = static fn(int $id, array $row): Profile => Profile::fromRow($row + ['ID' => $id, 'TITLE' => 'l', 'IS_ENABLED' => 'Y', 'ENTITY_TYPE_ID' => 1]);
$leads = [$lead(1, []), $lead(2, ['STAGES' => 'IN_PROCESS']), $lead(3, ['STAGE_SEMANTIC' => 'F'])];
Check::same('лид: стадия', ProfilePicker::pick($leads, 0, null, null, 'IN_PROCESS', '')?->id, 2);
Check::same('лид: провал', ProfilePicker::pick($leads, 0, null, null, 'JUNK', 'F')?->id, 3);
Check::same('лид: остальное — общий', ProfilePicker::pick($leads, 0, null, null, 'NEW', '')?->id, 1);

Check::group('закрытые: один раз после закрытия');

$won = $p(5, ['STAGE_SEMANTIC' => 'S', 'REANALYZE_DAYS' => 1]);
Check::same('не проверялась — пора', ProfilePicker::isDue($won, null, 1000, 900), true);
Check::same('проверена до закрытия — пора', ProfilePicker::isDue($won, 800, 10_000_000, 900), true);
Check::same('проверена после закрытия — больше нет', ProfilePicker::isDue($won, 950, 10_000_000, 900), false);
Check::same('открытый профиль — по сроку', ProfilePicker::isDue($p(1, ['REANALYZE_DAYS' => 1]), 950, 950 + 86400, 900), true);
Check::same('итоги по воронкам', ProfilePicker::getCategoryOutcomes($profiles), [3 => ['F', 'S']]);
Check::same('только профиль итога — открытые воронки не кандидаты', ProfilePicker::getCategoryReanalyzeDays([$p(5, ['STAGE_SEMANTIC' => 'S'])]), []);
Check::same('срок открытых — без профиля итога', ProfilePicker::getCategoryReanalyzeDays([$p(1, ['REANALYZE_DAYS' => 30]), $p(5, ['STAGE_SEMANTIC' => 'S', 'REANALYZE_DAYS' => 1])]), [3 => 30]);
Check::same('«живость» открытых — без профиля итога', ProfilePicker::getCategoryActiveDays([$p(1, ['ACTIVE_DAYS' => 30]), $p(5, ['STAGE_SEMANTIC' => 'S', 'ACTIVE_DAYS' => 0])]), [3 => 30]);
Check::same('выключенный профиль итога — не кандидаты', ProfilePicker::getCategoryOutcomes([$p(5, ['STAGE_SEMANTIC' => 'S', 'IS_ENABLED' => 'N'])]), []);
Check::same('проверена в момент закрытия — больше нет', ProfilePicker::isDue($won, 900, 10_000_000, 900), false);
Check::same('без MOVED_TIME и уже проверялась — нет', ProfilePicker::isDue($won, 800, 10_000_000, null), false);

Check::same('окно закрытых — 4 дня (владелец 04.11)', \Shef\ToolsAiCrm\Analysis\Candidates::CLOSED_WINDOW_DAYS, 4);
Check::group('миграция');

$connection = Application::getConnection();
$connection->tables = ['shef_toolsaicrm_deal_profile'];
$connection->fields = ['shef_toolsaicrm_deal_profile' => ['ID', 'TITLE', 'ACTIVE_DAYS', 'TOTAL_CONTROL', 'ENTITY_TYPE_ID', 'LEAD_REPEAT', 'LEAD_LEGAL', 'NO_CONVERSATION']];
$connection->queries = [];
DealProfileTable::init();
Check::same('таблица без стадий: дописаны два столбца', $connection->queries, [
	"ALTER TABLE shef_toolsaicrm_deal_profile ADD COLUMN STAGES VARCHAR(1000) NOT NULL DEFAULT ''",
	"ALTER TABLE shef_toolsaicrm_deal_profile ADD COLUMN STAGE_SEMANTIC CHAR(1) NOT NULL DEFAULT ''",
]);
$connection->fields = ['shef_toolsaicrm_deal_profile' => ['ID', 'ACTIVE_DAYS', 'TOTAL_CONTROL', 'ENTITY_TYPE_ID', 'LEAD_REPEAT', 'LEAD_LEGAL', 'NO_CONVERSATION', 'STAGES', 'STAGE_SEMANTIC']];
$connection->queries = [];
DealProfileTable::init();
Check::same('повтор — ничего', $connection->queries, []);
$connection->tables = [];
$connection->queries = [];
DealProfileTable::init();
Check::same('новая таблица — со стадиями', str_contains($connection->queries[0] ?? '', "STAGES VARCHAR(1000) NOT NULL DEFAULT ''") && str_contains($connection->queries[0] ?? '', "STAGE_SEMANTIC CHAR(1) NOT NULL DEFAULT ''"), true);
$connection->tables = [];
$connection->fields = [];

Check::finish();
