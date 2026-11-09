<?php declare(strict_types=1);

/**
 * Профили анализа сделок: разбор, подбор к сделке, шкала, перенос старых
 * настроек, таблицы.
 *
 * Что держит:
 *
 * * тип клиента — коды речевой аналитики, перевод из enum ядра как у
 *   AssessmentClientTypeResolver;
 * * профиль: границы 0-100, HIGH не ниже LOW, форма не превращает «70%» в
 *   другое число молча;
 * * подбор: только включённые и своего направления; конкретный тип главнее
 *   «любого»; потом SORT, ID; нет профиля — null;
 * * шкала: < LOW — ничего; >= LOW — дело менеджеру только без
 *   запланированных дел; >= HIGH и needSenior — комментарий и дело
 *   старшему; менеджер = старший — одно дело; повторы не чаще срока;
 * * перенос: по профилю на направление, LOW = HIGH = порог, промпт пустой;
 *   нет направлений — ничего;
 * * таблицы: профили создаются, в таблицу проверок дописываются столбцы.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Application;
use Shef\ToolsAiCrm\Config;
use Shef\ToolsAiCrm\Deal\ClientType;
use Shef\ToolsAiCrm\Analysis\Model\EntityCheckTable;
use Shef\ToolsAiCrm\Deal\Model\DealProfileTable;
use Shef\ToolsAiCrm\Deal\Profile;
use Shef\ToolsAiCrm\Deal\ProfilePicker;
use Shef\ToolsAiCrm\Deal\RiskScale;
use Shef\ToolsAiCrm\Deal\Verdict;

Check::group('тип клиента');

Check::same('по кому: компания главнее контакта', ClientType::pickClient(5, 7), [ClientType::CLIENT_COMPANY, 5]);
Check::same('по кому: нет компании — контакт', ClientType::pickClient(0, 7), [ClientType::CLIENT_CONTACT, 7]);
Check::same('по кому: нет обоих — не определён', ClientType::pickClient(0, 0), null);

Check::same('коды как у речевой аналитики', ClientType::getAll(), [1, 2, 3, 4]);
Check::same('New -> NEW', ClientType::fromCoreName('New'), ClientType::NEW);
Check::same('Existing -> IN_WORK', ClientType::fromCoreName('Existing'), ClientType::IN_WORK);
Check::same('PreviouslyContacted -> REPEATED_APPROACH', ClientType::fromCoreName('PreviouslyContacted'), ClientType::REPEATED_APPROACH);
Check::same('WithSale -> RETURN_CUSTOMER', ClientType::fromCoreName('WithSale'), ClientType::RETURN_CUSTOMER);
Check::same('Unrecognised -> null', ClientType::fromCoreName('Unrecognised'), null);
Check::same('список: мусор и повторы отброшены, по порядку', ClientType::parseList('3, 1,x,9,3'), [1, 3]);
Check::same('список из массива формы', ClientType::parseList(['4', '2']), [2, 4]);
Check::same('пусто — любой', ClientType::parseList(''), []);
Check::same('в строку для таблицы', ClientType::toList([3, 1]), '1,3');

Check::group('профиль из строки');

$p = Profile::fromRow(['ID' => '5', 'TITLE' => ' Опт ', 'IS_ENABLED' => 'Y', 'CATEGORY_ID' => '2', 'CLIENT_TYPES' => '2,4', 'LOW_BORDER' => '150', 'HIGH_BORDER' => '-3']);
Check::same('ID и направление числом', [$p->id, $p->categoryId], [5, 2]);
Check::same('название без краевых пробелов', $p->title, 'Опт');
Check::same('LOW подрезан до 100', $p->lowBorder, 100);
Check::same('HIGH мусором — умолчание, но не ниже LOW', $p->highBorder, 100);
Check::same('типы клиента', $p->clientTypes, [2, 4]);
Check::same('выключен по умолчанию', Profile::fromRow([])->enabled, false);
Check::same('умолчания шкалы', [Profile::fromRow([])->lowBorder, Profile::fromRow([])->highBorder, Profile::fromRow([])->idleDays, Profile::fromRow([])->reanalyzeDays], [50, 70, 3, 7]);
Check::same('промпт обрезан', mb_strlen(Profile::fromRow(['PROMPT' => str_repeat('я', 9000)])->prompt), Profile::PROMPT_MAX);

Check::group('профиль из формы');

[$fields, $errors] = Profile::fromInput(['TITLE' => 'Опт', 'IS_ENABLED' => 'Y', 'CATEGORY_ID' => '2', 'CLIENT_TYPES' => ['3', '1'], 'LOW_BORDER' => '40', 'HIGH_BORDER' => '80', 'SENIOR_ID' => '7', 'PROMPT' => ' свой ']);
Check::same('без ошибок', $errors, []);
Check::same('поля', [$fields['IS_ENABLED'], $fields['CATEGORY_ID'], $fields['CLIENT_TYPES'], $fields['LOW_BORDER'], $fields['HIGH_BORDER'], $fields['SENIOR_ID'], $fields['PROMPT']], ['Y', 2, '1,3', 40, 80, 7, 'свой']);
Check::same('1.67.0: общий промпт, открытый «Изменить» и не тронутый, — не свой (и с \r\n браузера)', [
	Profile::ownPrompt(\Shef\ToolsAiCrm\Deal\HealthAnalyzer::getSystemPrompt()),
	Profile::ownPrompt(str_replace("\n", "\r\n", str_replace("\r\n", "\n", \Shef\ToolsAiCrm\Deal\HealthAnalyzer::getSystemPrompt()))."\r\n"),
	Profile::ownPrompt(\Shef\ToolsAiCrm\Deal\HealthAnalyzer::getSystemPrompt().' Плюс.') !== '',
	Profile::ownPrompt('  '),
	Profile::ownPrompt(\Shef\ToolsAiCrm\Analysis\Prompt\PromptTemplate::defaults('lead')->system),
], ['', '', true, '', '']);
[$leadFields] = Profile::fromInput(['TITLE' => 'Лиды', 'ENTITY_KEY' => '1:0', 'CLIENT_TYPES' => ['3'], 'LEAD_REPEAT' => 'repeat']);
[$dealFields] = Profile::fromInput(['TITLE' => 'Сделки', 'ENTITY_KEY' => '2:0', 'CLIENT_TYPES' => ['3'], 'LEAD_REPEAT' => 'repeat']);
Check::same('сервер не сохраняет поля чужого типа: лид — без типа клиента сделки, сделка — без признаков лида', [$leadFields['CLIENT_TYPES'], $dealFields['CLIENT_TYPES'], $dealFields['LEAD_REPEAT']], ['', '3', '']);
[, $errors] = Profile::fromInput(['TITLE' => '', 'LOW_BORDER' => '70%', 'HIGH_BORDER' => '101']);
Check::same('пустое название, «70%», 101 — ошибки', $errors, ['TITLE', 'LOW_BORDER', 'HIGH_BORDER']);
[, $errors] = Profile::fromInput(['TITLE' => 'x', 'LOW_BORDER' => '80', 'HIGH_BORDER' => '60']);
Check::same('HIGH ниже LOW — ошибка', $errors, ['HIGH_BORDER']);
[$fields] = Profile::fromInput(['TITLE' => 'x']);
Check::same('флажок не отмечен — выключен', $fields['IS_ENABLED'], 'N');

Check::group('подбор профиля');

$make = static fn(int $id, int $category, string $types = '', int $sort = 100, bool $enabled = true): Profile => Profile::fromRow([
	'ID' => $id, 'TITLE' => 'p'.$id, 'IS_ENABLED' => $enabled ? 'Y' : 'N', 'CATEGORY_ID' => $category, 'CLIENT_TYPES' => $types, 'SORT' => $sort,
]);
$profiles = [
	$make(1, 0),
	$make(2, 0, '1'),
	$make(3, 0, '1', 50),
	$make(4, 5, '', 10, false),
	$make(5, 5, '', 20),
	$make(6, 5, '', 20),
	$make(7, 9, '4'),
];
$pickId = static fn(int $category, ?int $type): ?int => ProfilePicker::pick($profiles, $category, $type)?->id;

Check::same('тип совпал — профиль с типом главнее «любого»', $pickId(0, ClientType::NEW), 3);
Check::same('среди профилей с типом — меньший SORT', $pickId(0, ClientType::NEW), 3);
Check::same('тип не совпал — «любой»', $pickId(0, ClientType::IN_WORK), 1);
Check::same('тип не определился — «любой»', $pickId(0, null), 1);
Check::same('выключенный пропущен, при равном SORT — меньший ID', $pickId(5, null), 5);
Check::same('только профиль с типом, тип другой — нет профиля', $pickId(9, ClientType::NEW), null);
Check::same('тип не определился, профиль с типом — нет профиля', $pickId(9, null), null);
Check::same('направление без профилей — нет', $pickId(42, ClientType::NEW), null);
Check::same('пустой список — нет', ProfilePicker::pick([], 0, null), null);

Check::same(
	'направления кандидатов: включённые, самый короткий повтор',
	ProfilePicker::getCategoryReanalyzeDays([
		Profile::fromRow(['IS_ENABLED' => 'Y', 'CATEGORY_ID' => 3, 'REANALYZE_DAYS' => 7]),
		Profile::fromRow(['IS_ENABLED' => 'Y', 'CATEGORY_ID' => 3, 'REANALYZE_DAYS' => 2]),
		Profile::fromRow(['IS_ENABLED' => 'N', 'CATEGORY_ID' => 4, 'REANALYZE_DAYS' => 1]),
		Profile::fromRow(['IS_ENABLED' => 'Y', 'CATEGORY_ID' => 0, 'REANALYZE_DAYS' => 5]),
	]),
	[0 => 5, 3 => 2]
);

$monthly = Profile::fromRow(['IS_ENABLED' => 'Y', 'CATEGORY_ID' => 3, 'REANALYZE_DAYS' => 30]);
$now = 1_800_000_000;
Check::same(
	'срок повтора — по профилю сделки, а не по самому короткому в направлении',
	[
		ProfilePicker::isDue($monthly, null, $now),
		ProfilePicker::isDue($monthly, $now - 86400, $now),
		ProfilePicker::isDue($monthly, $now - 29 * 86400, $now),
		ProfilePicker::isDue($monthly, $now - 30 * 86400, $now),
	],
	[true, false, false, true]
);

Check::group('шкала');

$scale = Profile::fromRow(['IS_ENABLED' => 'Y', 'LOW_BORDER' => 40, 'HIGH_BORDER' => 70, 'SENIOR_ID' => 7]);
$v = static fn(int $risk, bool $senior = true): Verdict => Verdict::fromArray(['risk' => $risk, 'needSenior' => $senior]);
$d = static fn(Verdict $verdict, int $manager = 3, int $open = 0, bool $managerRecent = false, bool $seniorRecent = false, ?Profile $profile = null): array => (array)RiskScale::decide($verdict, $profile ?? $scale, $manager, $open, $managerRecent, $seniorRecent);
$none = ['managerTodo' => false, 'comment' => false, 'seniorTodo' => false, 'seniorMissing' => false, 'seniorOverdueTodo' => false, 'seniorAgainstModel' => false, 'noSeniorComment' => false];

Check::same('ниже LOW — ничего', $d($v(39)), $none);
Check::same('ровно LOW, дел нет — дело менеджеру', $d($v(40)), ['managerTodo' => true] + $none);
Check::same('LOW, но есть запланированное дело — ничего', $d($v(60), 3, 1), $none);
Check::same('LOW, ответственного нет — ничего', $d($v(60), 0), $none);
Check::same('HIGH и needSenior, дел нет — менеджер, комментарий, старший', $d($v(70)), ['managerTodo' => true, 'comment' => true, 'seniorTodo' => true, 'seniorMissing' => false, 'seniorOverdueTodo' => false, 'seniorAgainstModel' => false, 'noSeniorComment' => false]);
Check::same('HIGH и needSenior, дела есть — только старший', $d($v(90), 3, 2), ['managerTodo' => false, 'comment' => true, 'seniorTodo' => true, 'seniorMissing' => false, 'seniorOverdueTodo' => false, 'seniorAgainstModel' => false, 'noSeniorComment' => false]);
Check::same('HIGH без needSenior — уровень менеджера и комментарий «старшего не привлекаем»', $d($v(90, false)), array_replace($none, ['managerTodo' => true, 'noSeniorComment' => true]));
Check::same('менеджер и есть старший — одно дело', $d($v(90), 7), ['managerTodo' => false, 'comment' => true, 'seniorTodo' => true, 'seniorMissing' => false, 'seniorOverdueTodo' => false, 'seniorAgainstModel' => false, 'noSeniorComment' => false]);
Check::same('менеджер = старший, но эскалация недавно — дело менеджеру', $d($v(90), 7, 0, false, true), ['managerTodo' => true] + $none);
Check::same('дело менеджеру недавно — не повторяем', $d($v(60), 3, 0, true), $none);
Check::same('эскалация недавно — не повторяем', $d($v(90), 3, 1, false, true), $none);
Check::same('старший не задан — комментарий и пометка', $d($v(90), 3, 1, false, false, Profile::fromRow(['LOW_BORDER' => 40, 'HIGH_BORDER' => 70])), ['managerTodo' => false, 'comment' => true, 'seniorTodo' => false, 'seniorMissing' => true, 'seniorOverdueTodo' => false, 'seniorAgainstModel' => false, 'noSeniorComment' => false]);
Check::same('пропущенная сделка — никогда', $d(Verdict::skipped('в работе')), $none);
Check::same('LOW = HIGH (после переноса): ниже — ничего', $d($v(69), 3, 0, false, false, Profile::fromRow(['LOW_BORDER' => 70, 'HIGH_BORDER' => 70, 'SENIOR_ID' => 7])), $none);

Check::group('таблицы');

$connection = Application::getConnection();
$connection->tables = [];
$connection->queries = [];
DealProfileTable::init();
Check::same('профили: создаётся таблица', str_contains($connection->queries[0] ?? '', 'CREATE TABLE shef_toolsaicrm_deal_profile'), true);
Check::same('профили: промпт — TEXT, типы — строка', str_contains($connection->queries[0] ?? '', 'PROMPT TEXT NULL') && str_contains($connection->queries[0] ?? '', "CLIENT_TYPES VARCHAR(50) NOT NULL DEFAULT ''"), true);

$connection->fields = [];

$connection->tables = [];
$connection->queries = [];
EntityCheckTable::init();
Check::same('общая таблица: создаётся с ключом (тип, запись)', str_contains($connection->queries[0] ?? '', 'UNIQUE KEY UX_SHEF_TOOLSAI_ENTITY (ENTITY_TYPE_ID, ENTITY_ID)'), true);

$connection->tables = ['shef_toolsaicrm_deal_profile'];
$connection->fields = ['shef_toolsaicrm_deal_profile' => ['ID', 'TITLE', 'SENIOR_ID']];
$connection->queries = [];
DealProfileTable::init();
Check::same('профили до 1.4.0: дописаны ACTIVE_DAYS, TOTAL_CONTROL (выключен), ENTITY_TYPE_ID (сделка) и тип клиента лида «любой» (1.11.0)', $connection->queries, [
	'ALTER TABLE shef_toolsaicrm_deal_profile ADD COLUMN ACTIVE_DAYS INT(11) NOT NULL DEFAULT 60',
	"ALTER TABLE shef_toolsaicrm_deal_profile ADD COLUMN TOTAL_CONTROL CHAR(1) NOT NULL DEFAULT 'N'",
	'ALTER TABLE shef_toolsaicrm_deal_profile ADD COLUMN ENTITY_TYPE_ID INT(11) NOT NULL DEFAULT 2',
	"ALTER TABLE shef_toolsaicrm_deal_profile ADD COLUMN LEAD_REPEAT VARCHAR(10) NOT NULL DEFAULT ''",
	"ALTER TABLE shef_toolsaicrm_deal_profile ADD COLUMN LEAD_LEGAL VARCHAR(10) NOT NULL DEFAULT ''",
	"ALTER TABLE shef_toolsaicrm_deal_profile ADD COLUMN NO_CONVERSATION CHAR(1) NOT NULL DEFAULT 'N'",
	"ALTER TABLE shef_toolsaicrm_deal_profile ADD COLUMN STAGES VARCHAR(1000) NOT NULL DEFAULT ''",
	"ALTER TABLE shef_toolsaicrm_deal_profile ADD COLUMN STAGE_SEMANTIC CHAR(1) NOT NULL DEFAULT ''",
]);
$connection->fields = [];
$connection->queries = [];
DealProfileTable::init();
Check::same('ACTIVE_DAYS уже есть — ничего', $connection->queries, []);
$connection->tables = [];
$connection->queries = [];
DealProfileTable::init();
Check::same('новая таблица — с ACTIVE_DAYS', str_contains($connection->queries[0] ?? '', 'ACTIVE_DAYS INT(11) NOT NULL DEFAULT 60'), true);
Check::same('новая таблица — с NO_CONVERSATION (1.67.3)', str_contains($connection->queries[0] ?? '', "NO_CONVERSATION CHAR(1) NOT NULL DEFAULT 'N'"), true);
Check::same('новая таблица — с TOTAL_CONTROL', str_contains($connection->queries[0] ?? '', "TOTAL_CONTROL CHAR(1) NOT NULL DEFAULT 'N'"), true);
Check::same('новая таблица — с типом клиента лида (1.11.0)', str_contains($connection->queries[0] ?? '', "LEAD_REPEAT VARCHAR(10) NOT NULL DEFAULT ''") && str_contains($connection->queries[0] ?? '', "LEAD_LEGAL VARCHAR(10) NOT NULL DEFAULT ''"), true);
$connection->tables = [];

Check::group('живые сделки: ACTIVE_DAYS');

Check::same('по умолчанию 60', Profile::fromRow([])->activeDays, 60);
Check::same('0 — без ограничения', Profile::fromRow(['ACTIVE_DAYS' => '0'])->activeDays, 0);
Check::same('форма: 90', Profile::fromInput(['TITLE' => 'x', 'ACTIVE_DAYS' => '90'])[0]['ACTIVE_DAYS'], 90);
Check::same('форма: мусор — ошибка', Profile::fromInput(['TITLE' => 'x', 'ACTIVE_DAYS' => '-5'])[1], ['ACTIVE_DAYS']);
$p = static fn(int $cat, int $days, string $on = 'Y'): Profile => Profile::fromRow(['CATEGORY_ID' => $cat, 'ACTIVE_DAYS' => $days, 'IS_ENABLED' => $on]);
Check::same(
	'выборка: самый мягкий срок направления, 0 побеждает, выключенные не в счёт',
	ProfilePicker::getCategoryActiveDays([$p(1, 30), $p(1, 90), $p(2, 60), $p(2, 0), $p(3, 10), $p(3, 500, 'N')]),
	[1 => 90, 2 => 0, 3 => 10]
);

Check::group('шкала: просрочка');

$o = static fn(Verdict $verdict, int $manager = 3, int $open = 0, int $overdue = 2, bool $overdueRecent = false, ?Profile $profile = null, bool $managerRecent = false): array => (array)RiskScale::decide($verdict, $profile ?? $scale, $manager, $open, $managerRecent, false, $overdue, $overdueRecent);
Check::same('LOW, просрочено 2, запланированных нет — менеджеру и старшему', $o($v(50)), array_replace($none, ['managerTodo' => true, 'seniorOverdueTodo' => true]));
Check::same('просрочек нет — только менеджеру', $o($v(50), 3, 0, 0), array_replace($none, ['managerTodo' => true]));
Check::same('есть запланированное — никому', $o($v(50), 3, 1), $none);
Check::same('ниже LOW — никому, даже с просрочкой', $o($v(10)), $none);
Check::same('старшему о просрочке писали недавно — только менеджеру', $o($v(50), 3, 0, 2, true), array_replace($none, ['managerTodo' => true]));
Check::same('дело менеджеру недавно — и старшему нет', $o($v(50), 3, 0, 2, false, null, true), $none);
Check::same('старший не задан — только менеджеру', $o($v(50), 3, 0, 2, false, Profile::fromRow(['LOW_BORDER' => 40, 'HIGH_BORDER' => 70])), array_replace($none, ['managerTodo' => true]));
Check::same('старший и есть менеджер — одно дело', $o($v(50), 7), array_replace($none, ['managerTodo' => true]));
Check::same('эскалация старшему уже идёт — второго дела нет', $o($v(90)), array_replace($none, ['managerTodo' => true, 'comment' => true, 'seniorTodo' => true]));
Check::same('мёртвая сделка (риск 100, без needSenior) с просрочкой — менеджеру и старшему', $o($v(100, false)), array_replace($none, ['managerTodo' => true, 'seniorOverdueTodo' => true, 'noSeniorComment' => true]));

Check::group('шкала: тотальный контроль (1.8.0)');

$total = Profile::fromRow(['IS_ENABLED' => 'Y', 'LOW_BORDER' => 40, 'HIGH_BORDER' => 70, 'SENIOR_ID' => 7, 'TOTAL_CONTROL' => 'Y']);
Check::same('по умолчанию выключен', [Profile::fromRow([])->totalControl, $scale->totalControl], [false, false]);
Check::same('«Не передавать переписку» (1.67.3): по умолчанию выключено, Y и true — включено', [Profile::fromRow([])->noConversation, Profile::fromRow(['NO_CONVERSATION' => 'Y'])->noConversation, Profile::fromRow(['NO_CONVERSATION' => true])->noConversation], [false, true, true]);
Check::same('форма: флажок — Y, нет — N', [Profile::fromInput(['TITLE' => 'x', 'NO_CONVERSATION' => 'Y'])[0]['NO_CONVERSATION'], Profile::fromInput(['TITLE' => 'x'])[0]['NO_CONVERSATION']], ['Y', 'N']);
Check::same('форма: флажок', [Profile::fromInput(['TITLE' => 'x', 'TOTAL_CONTROL' => 'Y'])[0]['TOTAL_CONTROL'], Profile::fromInput(['TITLE' => 'x'])[0]['TOTAL_CONTROL']], ['Y', 'N']);
Check::same('выкл, needSenior=true — старший', $d($v(90), 3, 1), array_replace($none, ['comment' => true, 'seniorTodo' => true]));
Check::same('выкл, needSenior=false — старшего нет, комментарий «не привлекаем»', $d($v(90, false), 3, 1), array_replace($none, ['noSeniorComment' => true]));
Check::same('вкл, needSenior=true — старший, без пометки', $d($v(90), 3, 1, false, false, $total), array_replace($none, ['comment' => true, 'seniorTodo' => true]));
Check::same('вкл, needSenior=false — старший с пометкой, комментария «не привлекаем» нет', $d($v(90, false), 3, 1, false, false, $total), array_replace($none, ['comment' => true, 'seniorTodo' => true, 'seniorAgainstModel' => true]));
Check::same('вкл, но риск ниже HIGH — старшего нет', $d($v(60, false), 3, 1, false, false, $total), $none);
Check::same('вкл, эскалация недавно — не повторяем', $d($v(90, false), 3, 1, false, true, $total), $none);
$n = static fn(Verdict $verdict, bool $noteRecent): array => (array)RiskScale::decide($verdict, $scale, 3, 1, false, false, 0, false, $noteRecent);
Check::same('комментарий «не привлекаем» недавно — не повторяем', $n($v(90, false), true), $none);
Check::same('комментарий только при риске >= HIGH', $n($v(69, false), false), $none);

Check::same('форма: общий промпт без правки — не свой', Profile::fromInput(['TITLE' => 'x', 'PROMPT' => \Shef\ToolsAiCrm\Deal\HealthAnalyzer::getSystemPrompt()])[0]['PROMPT'], '');

Check::finish();
