<?php declare(strict_types=1);

/**
 * Общий блок анализа сущности (1.9.0): чистая логика.
 *
 * Держит: описание типов (сделка, лид, смарт-процесс — ENTITY_ID полей,
 * воронки, ключ промпта); разбор ENTITY_ID полей и ключа «тип:воронка»;
 * поля «AI: …» на лиде и смарт-процессе — те же коды, свой ENTITY_ID;
 * сделка — ровно как в 1.8.x (коды, ENTITY_ID, тексты дел и сводки);
 * профиль с типом сущности из формы.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

if(!class_exists(\Bitrix\Main\UserField\Types\StringType::class))
{
	eval(<<<'PHP'
	namespace Bitrix\Main\UserField\Types
	{
		abstract class BaseType {}
		class StringType extends BaseType {}
	}
	PHP);
}

use Shef\ToolsAiCrm\Analysis\EntityFields;
use Shef\ToolsAiCrm\Analysis\EntityType;
use Shef\ToolsAiCrm\Deal\DealFacts;
use Shef\ToolsAiCrm\Deal\Escalation;
use Shef\ToolsAiCrm\Deal\Profile;
use Shef\ToolsAiCrm\Deal\Verdict;

Check::group('типы сущностей');

$deal = EntityType::deal();
$lead = EntityType::lead();
$smart = EntityType::dynamic(1032, 'CRM_7', 'Заявки');
Check::same('сделка: тип 2, CRM_DEAL, воронки есть', [$deal->entityTypeId, $deal->ufEntityId, $deal->hasCategories, $deal->promptKind()], [2, 'CRM_DEAL', true, 'deal']);
Check::same('лид: тип 1, CRM_LEAD, одна «воронка»', [$lead->entityTypeId, $lead->ufEntityId, $lead->hasCategories, $lead->promptKind()], [1, 'CRM_LEAD', false, 'lead']);
Check::same('смарт-процесс: ENTITY_ID по ID типа, не по entityTypeId', [$smart->entityTypeId, $smart->ufEntityId, $smart->promptKind(), $smart->isDynamic()], [1032, 'CRM_7', 'dynamic', true]);
Check::same('названия', [$deal->nounGenitive, $lead->nounGenitive, $smart->nounGenitive, $smart->title], ['сделки', 'лида', 'элемента «Заявки»', 'Смарт-процесс «Заявки»']);
Check::same('«не найдена» — по роду', [$deal->notFoundText(5), $lead->notFoundText(5), $smart->notFoundText(5)], ['Сделка 5 не найдена', 'Лид 5 не найден', 'Элемент «Заявки» 5 не найден']);

Check::group('возможности типа (1.9.1): особая логика сделки — не отдельный путь');

$flat = EntityType::dynamic(1033, 'CRM_8', 'Без воронок', false);
Check::same('откаты по стадиям — только сделка', [$deal->stageRollbacks, $lead->stageRollbacks, $smart->stageRollbacks], [true, false, false]);
Check::same('CLOSED в выборке — только сделка', [$deal->closedFlag, $lead->closedFlag, $smart->closedFlag], [true, false, false]);
Check::same('ключ воронки карточки: сделка — прежний DEAL_CATEGORY_ID', [$deal->cardCategoryExtra, $lead->cardCategoryExtra, $smart->cardCategoryExtra, $flat->cardCategoryExtra], ['DEAL_CATEGORY_ID', '', 'CATEGORY_ID', '']);
$sources = implode("\n", array_map('file_get_contents', array_merge(array_diff(glob($root.'/lib/analysis/*.php'), [$root.'/lib/analysis/entitytype.php']), glob($root.'/lib/analysis/*/*.php'), [$root.'/lib/deal/contextbuilder.php', $root.'/lib/deal/field/acktype.php', $root.'/lib/agent/analysisagent.php'])));
Check::same('блок анализа не ветвится по «сделка ли это»', preg_match('/->isDeal\(\)/', $sources), 0);

Check::group('ENTITY_ID полей');

Check::same('наши', array_map([EntityType::class, 'isAnalysisUfEntity'], ['CRM_DEAL', 'CRM_LEAD', 'CRM_7']), [true, true, true]);
Check::same('чужие', array_map([EntityType::class, 'isAnalysisUfEntity'], ['CRM_CONTACT', 'USER', 'CRM_7_SPD', null, 'CRM_']), [false, false, false, false, false]);
Check::same('разбор', [EntityType::parseUfEntity('CRM_DEAL'), EntityType::parseUfEntity('CRM_LEAD'), EntityType::parseUfEntity('CRM_7'), EntityType::parseUfEntity('CRM_COMPANY')], [
	['entityTypeId' => 2, 'dynamicTypeId' => 0],
	['entityTypeId' => 1, 'dynamicTypeId' => 0],
	['entityTypeId' => 0, 'dynamicTypeId' => 7],
	null,
]);

Check::group('ключ «тип:воронка»');

Check::same('туда', [EntityType::toKey(2, 5), EntityType::toKey(1, 0), EntityType::toKey(1032, -1)], ['2:5', '1:0', '1032:0']);
Check::same('обратно', [EntityType::parseKey('2:5'), EntityType::parseKey(' 1:0 '), EntityType::parseKey('0:1'), EntityType::parseKey('x'), EntityType::parseKey(5)], [[2, 5], [1, 0], null, null, null]);
Check::same('поддерживаемые типы', array_map([EntityType::class, 'isSupportedId'], [1, 2, 3, 4, 31, 128, 1032]), [true, true, false, false, true, true, true]);

Check::group('поля «AI: …»');

$codes = EntityFields::getCodes();
Check::same('у сделки — прежние коды 1.8.x и история', EntityFields::getCodes(), ['UF_SHEF_AI_RISK', 'UF_SHEF_AI_DATE', 'UF_SHEF_AI_WHY', 'UF_SHEF_AI_TODO', 'UF_SHEF_AI_ACK_SENIOR', 'UF_SHEF_AI_ACK_MANAGER', 'UF_SHEF_AI_DONE_SENIOR', 'UF_SHEF_AI_DONE_MANAGER', 'UF_SHEF_AI_HISTORY']);
Check::same('у сделки — CRM_DEAL', array_values(array_unique(array_column(EntityFields::getDefinitions($deal), 'ENTITY_ID'))), ['CRM_DEAL']);
Check::same('у лида — те же коды на CRM_LEAD', [array_keys(EntityFields::getDefinitions($lead)), array_values(array_unique(array_column(EntityFields::getDefinitions($lead), 'ENTITY_ID')))], [$codes, ['CRM_LEAD']]);
Check::same('у смарт-процесса — на CRM_{ID типа}', array_values(array_unique(array_column(EntityFields::getDefinitions($smart), 'ENTITY_ID'))), ['CRM_7']);
Check::same('описания сделки и лида отличаются только ENTITY_ID', array_map(static fn(array $d): array => ['ENTITY_ID' => 'X'] + $d, EntityFields::getDefinitions($lead)), array_map(static fn(array $d): array => ['ENTITY_ID' => 'X'] + $d, EntityFields::getDefinitions($deal)));
Check::same('поле отметки по роли', [EntityFields::ackCode('senior'), EntityFields::ackCode('manager')], ['UF_SHEF_AI_ACK_SENIOR', 'UF_SHEF_AI_ACK_MANAGER']);

Check::group('тексты дел и комментариев');

$verdict = Verdict::fromArray(['risk' => 80, 'needSenior' => true, 'why' => 'молчит', 'nextStep' => 'позвонить']);
Check::same('сделка — как в 1.8.x', Escalation::buildText($verdict), "AI-анализ сделки: риск потери 80%, нужен старший.\nПочему: молчит\nЧто сделать: позвонить");
Check::same('лид', Escalation::buildText($verdict, false, $lead->nounGenitive), "AI-анализ лида: риск потери 80%, нужен старший.\nПочему: молчит\nЧто сделать: позвонить");
Check::same('менеджеру по смарт-процессу', Escalation::buildManagerText($verdict, $smart->nounGenitive), "AI-анализ элемента «Заявки»: нет запланированных дел, риск 80%.\nЧто сделать: позвонить\nПочему: молчит");
Check::same('просрочка по лиду', Escalation::buildOverdueText('Иванов', 2, null, $verdict, 'лида'), "У менеджера Иванов просрочены дела по лиду: 2 шт. Проконтролировать.\nAI-анализ: риск потери 80%.\nПочему: молчит");
Check::same('просрочка по сделке — как в 1.8.x', str_starts_with(Escalation::buildOverdueText('Иванов', 2, null, $verdict), 'У менеджера Иванов просрочены дела по сделке: 2 шт.'), true);

Check::group('сводка');

$facts = static fn(string $noun): DealFacts => new DealFacts(5, 'Окна', 'Новая', 100.0, 'BYN', 3, 2, 0, 0, 0, 0, [], noun: $noun);
Check::same('сделка — «Сделка #5»', strtok((new DealFacts(5, 'Окна', 'Новая', 100.0, 'BYN', 3, 2, 0, 0, 0, 0, []))->toPromptText(), "\n"), 'Сделка #5: Окна');
Check::same('лид — «Лид #5»', strtok($facts('Лид')->toPromptText(), "\n"), 'Лид #5: Окна');

Check::group('профиль: где анализировать');

[$fields, $errors] = Profile::fromInput(['TITLE' => 'Лиды', 'ENTITY_KEY' => '1:0']);
Check::same('лид из формы', [$fields['ENTITY_TYPE_ID'], $fields['CATEGORY_ID'], $errors], [1, 0, []]);
[$fields, $errors] = Profile::fromInput(['TITLE' => 'Заявки', 'ENTITY_KEY' => '1032:7']);
Check::same('смарт-процесс и воронка', [$fields['ENTITY_TYPE_ID'], $fields['CATEGORY_ID'], $errors], [1032, 7, []]);
[$fields, $errors] = Profile::fromInput(['TITLE' => 'x', 'ENTITY_KEY' => '3:0']);
Check::same('контакт — ошибка', $errors, ['ENTITY_KEY']);
[$fields, $errors] = Profile::fromInput(['TITLE' => 'Старый', 'CATEGORY_ID' => '4']);
Check::same('прежняя форма без ключа — сделка', [$fields['ENTITY_TYPE_ID'], $fields['CATEGORY_ID'], $errors], [2, 4, []]);
Check::same('строка без ENTITY_TYPE_ID — сделка', Profile::fromRow(['ID' => 1])->entityTypeId, 2);
Check::same('строка лида', Profile::fromRow(['ENTITY_TYPE_ID' => '1'])->entityTypeId, 1);

Check::group('типы с профилями (агент и «Проверить и включить»)');

$p = static fn(int $id, int $type, bool $on = true): Profile => Profile::fromRow(['ID' => $id, 'ENTITY_TYPE_ID' => $type, 'IS_ENABLED' => $on ? 'Y' : 'N']);
$groups = \Shef\ToolsAiCrm\Analysis\Candidates::groupByType([$p(1, 2), $p(2, 1032), $p(3, 1), $p(4, 1, false), $p(5, 1032)]);
Check::same('сделки — тоже тип (1.9.1), выключенные — мимо, по типу', array_map(static fn(array $list): array => array_map(static fn(Profile $x): int => $x->id, $list), $groups), [1 => [3], 2 => [1], 1032 => [2, 5]]);

Check::group('история риска');

use Shef\ToolsAiCrm\Analysis\History;
$day = 86400;
$t = mktime(12, 0, 0, 10, 4, 2026);
$rows = [
	['at' => $t, 'risk' => 80, 'why' => 'молчит', 'marked' => false],
	['at' => $t - 7 * $day, 'risk' => 70, 'why' => '', 'marked' => false],
	['at' => $t - 14 * $day, 'risk' => 60, 'why' => 'цена', 'marked' => false],
	['at' => $t - 21 * $day, 'risk' => 50, 'why' => 'x', 'marked' => true],
];
Check::same('серия без отметок', [History::idleStreak($rows), History::idleStreak([]), History::idleStreak([['marked' => true]])], [3, 0, 0]);
Check::same('«оценить нечего» (-1) серию не считает и не прерывает', [
	History::idleStreak([['risk' => -1], ['risk' => 60], ['risk' => -1], ['risk' => 70], ['risk' => 80]]),
	History::idleStreak([['risk' => 60], ['risk' => -1, 'marked' => true], ['risk' => 70, 'marked' => true]]),
], [3, 1]);
Check::same('поле истории: -1 — «оценить нечего»', History::toFieldText([['at' => strtotime('2026-10-05 12:00'), 'risk' => -1], ['at' => strtotime('2026-10-01 12:00'), 'risk' => 60, 'marked' => true]]), "05.10.2026 — оценить нечего\n01.10.2026 — 60%, отмечено");
Check::same('модели: -1 — «оценить было нечего»', str_contains(History::toPromptText([['at' => strtotime('2026-10-05 12:00'), 'risk' => -1]]), '1) 05.10.2026: оценить было нечего'), true);
Check::same('модели — три последних и предупреждение', History::toPromptText($rows), "Прошлые анализы AI (от свежего к старому):\n1) 04.10.2026: риск 80%, отметки: нет. Почему: молчит\n2) 27.09.2026: риск 70%, отметки: нет. Почему: —\n3) 20.09.2026: риск 60%, отметки: нет. Почему: цена\nВнимание: 3 анализа подряд никто не отметил, что работа сделана — рекомендации не выполняются.");
Check::same('отметка была — без предупреждения', str_contains(History::toPromptText([$rows[0], $rows[3]]), 'Внимание'), false);
Check::same('анализов не было — пусто', History::toPromptText([]), '');
Check::same('поле карточки — компактно', History::toFieldText([$rows[0], $rows[3]]), "04.10.2026 — 80%\n13.09.2026 — 50%, отмечено");
Check::same('поле — не больше пяти', count(explode("\n", History::toFieldText(array_fill(0, 9, $rows[0])))), 5);
Check::same('отметки: стоит одна — было', [History::isMarked('3|1800000000', ''), History::isMarked('pending|3', null)], [true, false]);

Check::group('ответ модели: синоним и свои поля');

$v = Verdict::fromArray(['risk' => 40, 'why' => 'w', 'todo' => 'позвонить', 'mood' => 'злой', 'skip' => ['x']], ['mood', 'skip', 'none']);
Check::same('todo = nextStep, свои поля — строки', [$v->nextStep, $v->extra], ['позвонить', ['mood' => 'злой']]);

Check::group('шаблон промпта');

use Shef\ToolsAiCrm\Analysis\Prompt\PromptBuilder;
use Shef\ToolsAiCrm\Analysis\Prompt\PromptStore;
use Shef\ToolsAiCrm\Analysis\Prompt\PromptTemplate;
use Shef\ToolsAiCrm\Deal\HealthAnalyzer;

$profile = Profile::fromRow(['ID' => 3, 'TITLE' => 'Опт', 'LOW_BORDER' => 40, 'HIGH_BORDER' => 70]);
$m = PromptBuilder::build(PromptTemplate::defaults('deal'), $profile, 'СВОДКА', '', 'сделка');
Check::same('сделка, стандартный шаблон, без истории — как до 1.9.0', [$m['system'], $m['user'], $m['schema']], [HealthAnalyzer::getSystemPrompt(), 'СВОДКА', HealthAnalyzer::SCHEMA]);
$m = PromptBuilder::build(PromptTemplate::defaults('deal'), $profile, 'СВОДКА', 'ИСТОРИЯ', 'сделка');
Check::same('история — после сводки', $m['user'], "СВОДКА\n\nИСТОРИЯ");
$own = Profile::fromRow(['PROMPT' => 'Свой промпт для {сущность}']);
Check::same('промпт профиля главнее, требование JSON в конце', PromptBuilder::build(PromptTemplate::defaults('lead'), $own, 'С', '', 'лид')['system'], "Свой промпт для лид\n\n".HealthAnalyzer::JSON_RULE);
$custom = new PromptTemplate('lead', 'Оцени {сущность}. {шкала}. Профиль {профиль}. {неизвестно}', 'Данные: {сводка}', ['mood'], false, 2);
$m = PromptBuilder::build($custom, $profile, 'С', '', 'лид');
Check::same('свой шаблон: подстановки, JSON и свои поля', $m['system'], "Оцени лид. риск от 40% — низкий (дело ответственному), от 70% — высокий (старший). Профиль Опт. {неизвестно}\n\n".HealthAnalyzer::JSON_RULE."\nДополнительно в том же JSON — строковые поля: mood.");
Check::same('схема со своим полем', [$m['schema']['properties']['mood'] ?? null, in_array('mood', $m['schema']['required'], true), $m['schema']['additionalProperties']], [['type' => 'string'], true, false]);
Check::same('данные без {сводка} — сводка всё равно уходит', PromptBuilder::build(new PromptTemplate('deal', 'x', 'только текст', [], false), null, 'С', '', 'сделка')['user'], "только текст\n\nС");
Check::same('стандартные шаблоны есть у всех видов, с {сводка}', array_map(static fn(string $k): bool => str_contains(PromptTemplate::defaults($k)->user, '{сводка}') && PromptTemplate::defaults($k)->isDefault, PromptTemplate::keys()), [true, true, true]);
Check::same('свои поля: разбор', PromptTemplate::parseExtraFields('mood, deadline;mood'), [['mood', 'deadline'], []]);
Check::same('свои поля: ошибки', PromptTemplate::parseExtraFields('risk Mood ok')[1], ['risk: стандартное поле ответа', 'Mood: латиница, с маленькой буквы, до 31 символа']);
Check::same('свои поля: не больше пяти', count(PromptTemplate::parseExtraFields('a b c d e f')[0]), 5);
$row = PromptStore::fromRow('lead', ['SYSTEM_TEXT' => '', 'USER_TEXT' => 'Д: {сводка}', 'EXTRA_FIELDS' => 'mood', 'VERSION' => '3', 'CHANGED_BY' => '7']);
Check::same('строка таблицы: пустая часть — стандартная, версия и автор', [$row->system === PromptTemplate::defaults('lead')->system, $row->user, $row->extraFields, $row->isDefault, $row->version, $row->changedBy], [true, 'Д: {сводка}', ['mood'], false, 3, 7]);

Check::group('уровни шаблона промпта (1.9.1)');

use Shef\ToolsAiCrm\Analysis\Prompt\PromptLevel;
use Shef\ToolsAiCrm\Analysis\Prompt\PromptResolver;

$codes = static fn(array $levels): array => array_map(static fn(PromptLevel $l): string => $l->code(), $levels);
Check::same('коды уровней', [PromptLevel::of($deal)->code(), PromptLevel::of($deal, 5)->code(), PromptLevel::of($deal, 0)->code(), PromptLevel::of($lead, 3)->code(), PromptLevel::of($smart)->code(), PromptLevel::of($smart, 7)->code(), PromptLevel::of($flat, 7)->code()],
	['deal', 'deal.c5', 'deal.c0', 'lead', 'dynamic.1032', 'dynamic.1032.c7', 'dynamic.1033']);
Check::same('цепочка: воронка -> тип', [$codes(PromptLevel::of($deal, 5)->chain()), $codes(PromptLevel::of($smart, 7)->chain()), $codes(PromptLevel::of($lead)->chain())], [['deal.c5', 'deal'], ['dynamic.1032.c7', 'dynamic.1032'], ['lead']]);
Check::same('разбор кода туда-обратно', array_map(static fn(string $c): ?string => PromptLevel::parse($c)?->code(), ['deal', 'deal.c5', 'lead', 'dynamic.1032', 'dynamic.1032.c7']), ['deal', 'deal.c5', 'lead', 'dynamic.1032', 'dynamic.1032.c7']);
Check::same('мусор и старый общий код смарт-процессов — не уровень', array_map([PromptLevel::class, 'parse'], ['dynamic', 'lead.c1', 'dynamic.2', 'deal.cx', 'x', null]), [null, null, null, null, null, null]);
Check::same('разобранный уровень: тип и вид', [PromptLevel::parse('dynamic.1032.c7')?->entityTypeId, PromptLevel::parse('dynamic.1032.c7')?->kind, PromptLevel::parse('deal.c5')?->entityTypeId], [1032, 'dynamic', 2]);

$own = static fn(string $system): array => ['SYSTEM_TEXT' => $system, 'USER_TEXT' => '', 'EXTRA_FIELDS' => '', 'VERSION' => 2];
$rows = ['deal' => $own('Сделки общий'), 'deal.c5' => $own('Направление 5'), 'dynamic.1032' => $own('Заявки')];
$r = PromptResolver::resolve(PromptLevel::of($deal, 5), $rows);
Check::same('свой шаблон воронки главнее', [$r['template']->system, $r['source']?->code()], ['Направление 5', 'deal.c5']);
$r = PromptResolver::resolve(PromptLevel::of($deal, 3), $rows);
Check::same('у воронки своего нет — шаблон типа', [$r['template']->system, $r['source']?->code()], ['Сделки общий', 'deal']);
$r = PromptResolver::resolve(PromptLevel::of($lead), $rows);
Check::same('у типа своего нет — стандартный', [$r['template']->system === PromptTemplate::defaults('lead')->system, $r['template']->isDefault, $r['source']], [true, true, null]);
$r = PromptResolver::resolve(PromptLevel::of($smart, 7), $rows);
Check::same('смарт-процесс: воронка -> свой тип; чужой смарт-процесс не наследует', [$r['template']->system, $r['source']?->code(), PromptResolver::resolve(PromptLevel::of(EntityType::dynamic(1040, 'CRM_9')), $rows)['source']], ['Заявки', 'dynamic.1032', null]);
Check::same('сделка без своих шаблонов — ровно стандартный сделки (как до 1.9.1)', get_object_vars(PromptResolver::resolve(PromptLevel::of($deal, 5), [])['template']), get_object_vars(PromptTemplate::defaults('deal')));
Check::same('состояние уровня для страницы: свой / унаследован / стандартный', [
	PromptResolver::state(PromptLevel::of($deal, 5), $rows)['state'],
	PromptResolver::state(PromptLevel::of($deal, 3), $rows)['state'],
	PromptResolver::state(PromptLevel::of($deal, 3), $rows)['source']?->code(),
	PromptResolver::state(PromptLevel::of($lead), $rows)['state'],
], ['own', 'inherited', 'deal', 'default']);
unset($rows['deal.c5']);
Check::same('«вернуть стандартный» = удалить свой — действует уровень выше', PromptResolver::resolve(PromptLevel::of($deal, 5), $rows)['source']?->code(), 'deal');
Check::same('общий шаблон смарт-процессов: смарт-процессов нет — не удалять (текст некуда скопировать)', [PromptStore::canDropCommonDynamic([]), PromptStore::canDropCommonDynamic([0]), PromptStore::canDropCommonDynamic([1032])], [false, false, true]);
Check::same('общий шаблон смарт-процессов 1.9.0 — в типы без своего', PromptStore::planCommonDynamic([1032, 1040, 1040, 2], ['deal', 'dynamic.1032']), ['dynamic.1040']);

Check::group('воронка профиля у типа без воронок');

// Смарт-процесс с выключенными воронками: запись несёт воронку по умолчанию (7), профиль
// сохранён с 0 — подбор шёл по 7 и профиля не находил (ревью #50)
Check::same(
	'смарт-процесс без воронок, лид — 0; сделка и смарт-процесс с воронками — воронка записи',
	[
		\Shef\ToolsAiCrm\Deal\ContextBuilder::profileCategoryId(EntityType::dynamic(1032, 'CRM_7', 'Заявки', false), 7),
		\Shef\ToolsAiCrm\Deal\ContextBuilder::profileCategoryId(EntityType::lead(), 0),
		\Shef\ToolsAiCrm\Deal\ContextBuilder::profileCategoryId(EntityType::deal(), 14),
		\Shef\ToolsAiCrm\Deal\ContextBuilder::profileCategoryId(EntityType::dynamic(1032, 'CRM_7', 'Заявки', true), 7),
	],
	[0, 0, 14, 7]
);
$smart = \Shef\ToolsAiCrm\Deal\ProfilePicker::pick([Profile::fromRow(['ID' => 1, 'IS_ENABLED' => 'Y', 'CATEGORY_ID' => 0])], \Shef\ToolsAiCrm\Deal\ContextBuilder::profileCategoryId(EntityType::dynamic(1032, 'CRM_7', 'Заявки', false), 7), null);
Check::same('профиль смарт-процесса без воронок находится', $smart?->id, 1);

Check::group('поля «AI: …» — агент создаёт недостающие (#281)');
$agent = (string)file_get_contents($root.'/lib/agent/analysisagent.php');
Check::same('AnalysisAgent::process() зовёт EntityFields::ensure() до прохода по записям', [str_contains($agent, 'EntityFields::ensure($type)'), strpos($agent, 'EntityFields::ensure($type)') < strpos($agent, 'new Runner($type)')], [true, true]);

Check::group('подсказки полей «AI: …» в карточке (1.63.5)');
$defs = \Shef\ToolsAiCrm\Analysis\EntityFields::getDefinitions(\Shef\ToolsAiCrm\Analysis\EntityType::deal());
Check::same('у каждого поля — штатный HELP_MESSAGE ru и en', array_keys(array_filter($defs, static fn(array $d): bool => trim((string)($d['HELP_MESSAGE']['ru'] ?? '')) === '' || trim((string)($d['HELP_MESSAGE']['en'] ?? '')) === '')), []);
$src = (string)file_get_contents(dirname(__DIR__).'/lib/analysis/entityfields.php');
Check::group('подписи полей «AI: …»: Update ядра стирает все подписи — отдаём полный набор (1.64.3)');
$F = \Shef\ToolsAiCrm\Analysis\EntityFields::class;
$risk = $defs[$F::RISK];
// Поле после 1.63.5–1.64.2: названия стёрты, подсказка есть
$wiped = ['EDIT_FORM_LABEL' => ['ru' => '', 'en' => ''], 'LIST_COLUMN_LABEL' => [], 'LIST_FILTER_LABEL' => [], 'ERROR_MESSAGE' => [], 'HELP_MESSAGE' => $risk['HELP_MESSAGE']];
$fixed = $F::mergeLabels($wiped, $risk);
Check::same('стёртые названия восстанавливаются из описания', [$fixed['EDIT_FORM_LABEL']['ru'] ?? null, $fixed['LIST_COLUMN_LABEL']['ru'] ?? null, $fixed['LIST_FILTER_LABEL']['en'] ?? null], ['AI: риск, %', 'AI: риск, %', 'AI: risk, %']);
Check::same('набор полный — все подписи, которые переписывает Update ядра', array_keys($fixed), $F::LABEL_KEYS);
Check::same('подсказка при этом сохраняется', $fixed['HELP_MESSAGE']['ru'], $risk['HELP_MESSAGE']['ru']);
$admin = ['EDIT_FORM_LABEL' => ['ru' => 'Риск (наш)', 'en' => 'Risk'], 'LIST_COLUMN_LABEL' => ['ru' => 'Риск', 'en' => 'Risk'], 'LIST_FILTER_LABEL' => ['ru' => 'Риск', 'en' => 'Risk'], 'ERROR_MESSAGE' => [], 'HELP_MESSAGE' => ['ru' => '', 'en' => '']];
$merged = $F::mergeLabels($admin, $risk);
Check::same('подпись администратора не трогаем, пустую подсказку дописываем', [$merged['EDIT_FORM_LABEL']['ru'], $merged['HELP_MESSAGE']['ru']], ['Риск (наш)', $risk['HELP_MESSAGE']['ru']]);
$old = ['EDIT_FORM_LABEL' => ['ru' => 'ИИ: риск, %', 'en' => 'AI: risk, %'], 'LIST_COLUMN_LABEL' => ['ru' => 'ИИ: риск, %', 'en' => 'AI: risk, %'], 'LIST_FILTER_LABEL' => ['ru' => 'Риск (наш)', 'en' => 'AI: risk, %'], 'ERROR_MESSAGE' => [], 'HELP_MESSAGE' => $risk['HELP_MESSAGE']];
$renamed = $F::mergeLabels($old, $risk);
Check::same('прежняя стандартная подпись «ИИ: …» — на «AI: …» (#344), своя подпись администратора цела', [$renamed['EDIT_FORM_LABEL']['ru'], $renamed['LIST_COLUMN_LABEL']['ru'], $renamed['LIST_FILTER_LABEL']['ru'], $renamed['EDIT_FORM_LABEL']['en']], ['AI: риск, %', 'AI: риск, %', 'Риск (наш)', 'AI: risk, %']);
Check::same('всё заполнено — Update не нужен', $F::mergeLabels(['EDIT_FORM_LABEL' => $risk['EDIT_FORM_LABEL'], 'LIST_COLUMN_LABEL' => $risk['LIST_COLUMN_LABEL'], 'LIST_FILTER_LABEL' => $risk['LIST_FILTER_LABEL'], 'HELP_MESSAGE' => $risk['HELP_MESSAGE']], $risk), null);
Check::same('Update получает полный набор, а не один HELP_MESSAGE', [str_contains($src, "\$entity->Update((int)\$row['ID'], \$labels)"), str_contains($src, "\$entity->Update((int)\$row['ID'], ['HELP_MESSAGE' =>")], [true, false]);


Check::finish();
