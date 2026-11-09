<?php declare(strict_types=1);

/**
 * Контроль сделки 1.8.0 (bx-shef/toolsai#40, #41): чистая логика.
 *
 * Что держит:
 *
 * * стоимость меньше цента не превращается в «0.00», итоги — два знака;
 * * сброс отметок «отработано / под контролем» — только при смене зоны
 *   шкалы (появился LOW, ушёл ниже LOW, пересёк HIGH);
 * * колонка «Эскалация»: все действия, а если ничего — причина;
 * * тексты дела старшему с пометкой и комментария «старшего не привлекаем»;
 * * поля сделки: коды, типы, только чтение в списке, фильтр по риску;
 * * отметка: формат значения, кто может нажать, HTML кнопки;
 * * защита полей: какие наши поля пришли в обновлении;
 * * откат снимает всех агентов модуля.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

// Базовый тип поля ядра — только то, что нужно AckType.
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

use Shef\ToolsAiCrm\Deal\AckReset;
use Shef\ToolsAiCrm\Deal\ActionSummary;
use Shef\ToolsAiCrm\Deal\Escalation;
use Shef\ToolsAiCrm\Deal\Field\AckType;
use Shef\ToolsAiCrm\Deal\Field\AckValue;
use Shef\ToolsAiCrm\Analysis\EntityFields;
use Shef\ToolsAiCrm\Analysis\EntityType;
use Shef\ToolsAiCrm\Deal\Field\Handlers;
use Shef\ToolsAiCrm\Deal\Profile;
use Shef\ToolsAiCrm\Deal\Verdict;


Check::group('сброс отметок');

Check::same('зоны', [AckReset::zone(39, 40, 70), AckReset::zone(40, 40, 70), AckReset::zone(69, 40, 70), AckReset::zone(70, 40, 70)], [0, 1, 1, 2]);
Check::same('первый анализ — не сбрасываем', AckReset::shouldReset(null, 90, 40, 70), false);
Check::same('внутри LOW — нет', AckReset::shouldReset(45, 65, 40, 70), false);
Check::same('внутри HIGH — нет', AckReset::shouldReset(75, 99, 40, 70), false);
Check::same('ниже LOW оба раза — нет', AckReset::shouldReset(10, 30, 40, 70), false);
Check::same('появился LOW — да', AckReset::shouldReset(30, 40, 40, 70), true);
Check::same('ушёл ниже LOW — да', AckReset::shouldReset(50, 39, 40, 70), true);
Check::same('пересёк HIGH вверх — да', AckReset::shouldReset(60, 70, 40, 70), true);
Check::same('пересёк HIGH вниз — да', AckReset::shouldReset(80, 69, 40, 70), true);
Check::same('из HIGH сразу ниже LOW — да', AckReset::shouldReset(90, 5, 40, 70), true);

Check::group('колонка «Эскалация»');

$profile = Profile::fromRow(['LOW_BORDER' => 40, 'HIGH_BORDER' => 70, 'SENIOR_ID' => 7]);
$total = Profile::fromRow(['LOW_BORDER' => 40, 'HIGH_BORDER' => 70, 'SENIOR_ID' => 7, 'TOTAL_CONTROL' => 'Y']);
$v = static fn(int $risk, bool $senior = false): Verdict => Verdict::fromArray(['risk' => $risk, 'needSenior' => $senior]);
$at = mktime(12, 0, 0, 9, 30, 2026);

Check::same('все действия по порядку', ActionSummary::build(['manager_todo', 'overdue_todo', 'comment', 'todo', 'note'], $v(90, true), $profile, 3, 0), 'дело менеджеру, дело старшему о просрочке, комментарий, дело старшему, комментарий «старшего не привлекаем»');
Check::same('старший не задан — причина как есть', ActionSummary::build(['comment', 'старший не задан — дела нет'], $v(90, true), $profile, 3, 0), 'комментарий, старший не задан — дела нет');
$err = ActionSummary::error('дело менеджеру', ' Не задан срок ');
Check::same('сбой: строка', $err, 'ошибка: дело менеджеру не поставлено — Не задан срок');
Check::same('сбой без причины', ActionSummary::error('дело старшему', ''), 'ошибка: дело старшему не поставлено');
Check::same('сбой длинный — обрезан', mb_strlen(ActionSummary::error('дело', str_repeat('я', 1000))), 300);
Check::same('сбоев нет — как было', ActionSummary::withErrors('дело менеджеру', []), 'дело менеджеру');
Check::same('сбой после сделанного', ActionSummary::withErrors('дело менеджеру', [$err]), 'дело менеджеру; '.$err);
Check::same('сбой вместо «дела не поставились»', ActionSummary::withErrors('ничего: дела не поставились', [$err]), $err);
Check::same('сбой рядом с причиной', ActionSummary::withErrors('ничего: есть запланированные дела', [$err]), 'ничего: есть запланированные дела; '.$err);
Check::same('ниже LOW', ActionSummary::build([], $v(10), $profile, 3, 0), 'ничего: риск ниже порога 40%');
Check::same('есть запланированные дела', ActionSummary::build([], $v(50), $profile, 3, 2), 'ничего: есть запланированные дела');
Check::same('дело менеджеру уже ставилось', ActionSummary::build([], $v(50), $profile, 3, 0, $at), 'ничего: дело менеджеру уже ставилось 30.09.2026');
Check::same('нет ответственного', ActionSummary::build([], $v(50), $profile, 0, 0), 'ничего: нет ответственного');
Check::same(
	'HIGH, старший не нужен, комментарий был недавно, дела есть',
	ActionSummary::build([], $v(90), $profile, 3, 1),
	'ничего: старший не нужен по мнению модели; есть запланированные дела'
);
Check::same('тотальный контроль — «старший не нужен» не причина', ActionSummary::build([], $v(90), $total, 3, 1, null, $at), 'ничего: старшему уже ставилось 30.09.2026; есть запланированные дела');
Check::same('пропуск', ActionSummary::build([], Verdict::skipped('в работе'), $profile, 3, 0), 'ничего: пропуск — в работе');
Check::same('пропуск 1.14.7 — без двойного «пропуск»', ActionSummary::build([], Verdict::skipped('Пропуск: сделка в работе — последняя активность сегодня'), $profile, 3, 0), 'ничего: пропуск: сделка в работе — последняя активность сегодня');
Check::same('оценить нечего — в действии', ActionSummary::build([], Verdict::nothingToAssess('Оценить нечего: нет звонков'), $profile, 3, 0), 'ничего: оценить нечего: нет звонков');

Check::group('риск -1 «оценить нечего» и отметки (1.14.7)');
Check::same('прошлый риск по проверке', [
	AckReset::previousRisk(null),
	AckReset::previousRisk(['SKIPPED' => 'N', 'RISK' => 70]),
	AckReset::previousRisk(['SKIPPED' => 'Y', 'RISK' => -1]),
	AckReset::previousRisk(['SKIPPED' => 'Y', 'RISK' => 0]),
], [null, 70, -1, null]);
Check::same('-1 — зона «ниже порога»', AckReset::zone(-1, 50, 70), AckReset::ZONE_NONE);
Check::same('70 -> -1 — сброс отметок; -1 -> 70 — сброс; -1 -> 30 и -1 -> -1 — нет', [
	AckReset::shouldReset(70, -1, 50, 70),
	AckReset::shouldReset(-1, 70, 50, 70),
	AckReset::shouldReset(-1, 30, 50, 70),
	AckReset::shouldReset(-1, -1, 50, 70),
], [true, true, false, false]);
Check::same('-1: отметки чистятся, «ждёт отметки» не ставится', EntityFields::buildAckValues(true, false, 'pending|9', '3|1800000000', 9, 3), ['UF_SHEF_AI_ACK_SENIOR' => '', 'UF_SHEF_AI_DONE_SENIOR' => 0, 'UF_SHEF_AI_ACK_MANAGER' => '', 'UF_SHEF_AI_DONE_MANAGER' => 0]);
Check::same('-1: полей ещё нет — ничего не пишем', EntityFields::buildAckValues(false, false, null, '', 9, 3), []);
Check::same('поля «оценить нечего»: риск -1, почему, «что сделать» пусто', EntityFields::buildAnalysisValues(Verdict::NOTHING_RISK, 'Оценить нечего', '', 'сейчас'), ['UF_SHEF_AI_RISK' => -1, 'UF_SHEF_AI_DATE' => 'сейчас', 'UF_SHEF_AI_WHY' => 'Оценить нечего', 'UF_SHEF_AI_TODO' => '']);
Check::same('поле риска пускает -1 (пусто — не анализировалась)', EntityFields::getDefinitions(EntityType::deal())['UF_SHEF_AI_RISK']['SETTINGS']['MIN_VALUE'], -1);

Check::group('тексты');

Check::same(
	'комментарий «старшего не привлекаем»',
	Escalation::buildNoSeniorText(Verdict::fromArray(['risk' => 85, 'why' => 'молчит', 'nextStep' => 'позвонить'])),
	"AI-анализ: риск 85%. Старшего не привлекаем — по оценке модели вмешательство старшего исход не изменит.\nПочему: молчит\nЧто сделать: позвонить"
);
Check::same(
	'дело старшему по тотальному контролю — с пометкой',
	Escalation::buildText(Verdict::fromArray(['risk' => 85, 'why' => 'молчит']), true),
	"AI-анализ сделки: риск потери 85%, тотальный контроль (старший по мнению модели не нужен).\nПочему: молчит\nЧто сделать: —"
);
Check::same('обычное дело старшему — как раньше', str_starts_with(Escalation::buildText(Verdict::fromArray(['risk' => 85])), 'AI-анализ сделки: риск потери 85%, нужен старший.'), true);

Check::group('поля сделки');

$definitions = EntityFields::getDefinitions(EntityType::deal());
Check::same('коды', array_keys($definitions), ['UF_SHEF_AI_RISK', 'UF_SHEF_AI_DATE', 'UF_SHEF_AI_WHY', 'UF_SHEF_AI_TODO', 'UF_SHEF_AI_ACK_SENIOR', 'UF_SHEF_AI_ACK_MANAGER', 'UF_SHEF_AI_DONE_SENIOR', 'UF_SHEF_AI_DONE_MANAGER', 'UF_SHEF_AI_HISTORY']);
Check::same('типы', array_column($definitions, 'USER_TYPE_ID'), ['integer', 'datetime', 'string', 'string', 'shef_toolsai_ack', 'shef_toolsai_ack', 'boolean', 'boolean', 'string']);
Check::same('все — сделки', array_unique(array_column($definitions, 'ENTITY_ID')), ['CRM_DEAL']);
Check::same('в списке не редактируются', array_unique(array_column($definitions, 'EDIT_IN_LIST')), ['N']);
Check::same('в фильтре — риск и дата', array_keys(array_filter($definitions, static fn(array $d): bool => $d['SHOW_FILTER'] !== 'N')), ['UF_SHEF_AI_RISK', 'UF_SHEF_AI_DATE', 'UF_SHEF_AI_DONE_SENIOR', 'UF_SHEF_AI_DONE_MANAGER']);
Check::same('имена не длиннее 50 (CUserTypeEntity)', max(array_map('strlen', array_keys($definitions))) <= 50, true);
Check::same('роли кнопок', [$definitions['UF_SHEF_AI_ACK_SENIOR']['SETTINGS']['ROLE'], $definitions['UF_SHEF_AI_ACK_MANAGER']['SETTINGS']['ROLE']], ['senior', 'manager']);
Check::same('подписи по-русски', $definitions['UF_SHEF_AI_RISK']['EDIT_FORM_LABEL']['ru'], 'AI: риск, %');
Check::same('у полей без своего типа редактор подменяется', EntityFields::getPlainCodes(), ['UF_SHEF_AI_RISK', 'UF_SHEF_AI_DATE', 'UF_SHEF_AI_WHY', 'UF_SHEF_AI_TODO', 'UF_SHEF_AI_DONE_SENIOR', 'UF_SHEF_AI_DONE_MANAGER', 'UF_SHEF_AI_HISTORY']);
Check::same(
	'значения после анализа',
	EntityFields::buildAnalysisValues(80, 'молчит', 'позвонить', 'T'),
	['UF_SHEF_AI_RISK' => 80, 'UF_SHEF_AI_DATE' => 'T', 'UF_SHEF_AI_WHY' => 'молчит', 'UF_SHEF_AI_TODO' => 'позвонить']
);
Check::same('риск ≥ LOW, полей ещё нет — ждут отметки адресатов', EntityFields::buildAckValues(false, true, null, '', 9, 3), ['UF_SHEF_AI_ACK_SENIOR' => 'pending|9', 'UF_SHEF_AI_DONE_SENIOR' => 0, 'UF_SHEF_AI_ACK_MANAGER' => 'pending|3', 'UF_SHEF_AI_DONE_MANAGER' => 0]);
Check::same('отмечено, зона та же — не трогаем', EntityFields::buildAckValues(false, true, '9|1800000000', '3|1800000000', 9, 3), []);
Check::same('сброс при риске ≥ LOW — снова ждут', EntityFields::buildAckValues(true, true, '9|1800000000', '3|1800000000', 9, 3), ['UF_SHEF_AI_ACK_SENIOR' => 'pending|9', 'UF_SHEF_AI_DONE_SENIOR' => 0, 'UF_SHEF_AI_ACK_MANAGER' => 'pending|3', 'UF_SHEF_AI_DONE_MANAGER' => 0]);
Check::same('риск ниже LOW — пусто', EntityFields::buildAckValues(true, false, '9|1800000000', 'pending|3', 9, 3), ['UF_SHEF_AI_ACK_SENIOR' => '', 'UF_SHEF_AI_DONE_SENIOR' => 0, 'UF_SHEF_AI_ACK_MANAGER' => '', 'UF_SHEF_AI_DONE_MANAGER' => 0]);
Check::same('ниже LOW и уже пусто — не трогаем', EntityFields::buildAckValues(false, false, '', null, 9, 3), []);
Check::same('сменился ответственный — новый адресат', EntityFields::buildAckValues(false, true, 'pending|9', 'pending|3', 9, 4), ['UF_SHEF_AI_ACK_MANAGER' => 'pending|4', 'UF_SHEF_AI_DONE_MANAGER' => 0]);
Check::same('флажок «отметил»: отмечено — 1, ждёт/пусто/мусор — 0', [EntityFields::doneFlag('3|1800000000'), EntityFields::doneFlag('pending|3'), EntityFields::doneFlag(''), EntityFields::doneFlag(null)], [1, 0, 0, 0]);
$dealflag = (string)file_get_contents($root.'/lib/controller/dealflag.php');
Check::same('кнопка ставит и флажок', str_contains($dealflag, 'EntityFields::DONE_FLAGS[$code] => EntityFields::doneFlag($value)'), true);
Check::same('защита: наши поля в обновлении', Handlers::getTouched(['TITLE' => 'x', 'UF_SHEF_AI_RISK' => 1, 'UF_SHEF_AI_ACK_MANAGER' => '', 'UF_OTHER' => 2]), ['UF_SHEF_AI_RISK', 'UF_SHEF_AI_ACK_MANAGER']);
Check::same('защита: чужих нет — ничего', Handlers::getTouched(['TITLE' => 'x']), []);
Check::same('защита: пишет модуль — пропускаем', EntityFields::isInternalWrite(), false);

Check::group('отметка');

Check::same('формат', AckValue::format(7, 1_800_000_000), '7|1800000000');
Check::same('разбор', AckValue::parse('7|1800000000'), ['userId' => 7, 'at' => 1_800_000_000, 'byAdmin' => false]);
Check::same('пусто и мусор — не отмечено', [AckValue::parse(''), AckValue::parse(null), AckValue::parse('0|5'), AckValue::parse('7|x'), AckValue::parse(['7|1'])], [null, null, null, null, null]);
Check::same('роль', [AckValue::role('senior'), AckValue::role('manager'), AckValue::role('admin'), AckValue::role(null)], ['senior', 'manager', null, null]);
Check::same(
	'нажать может только адресат',
	[
		AckValue::canMark('senior', 7, 3, 7),
		AckValue::canMark('senior', 3, 3, 7),
		AckValue::canMark('manager', 3, 3, 7),
		AckValue::canMark('manager', 7, 3, 7),
		AckValue::canMark('senior', 0, 0, 0),
		AckValue::canMark('manager', 0, 0, 7),
		AckValue::canMark('other', 7, 7, 7),
	],
	[true, false, true, false, false, false, false]
);
Check::same('надписи кнопок', [AckValue::buttonTitle('senior'), AckValue::buttonTitle('manager')], ['Риск под контролем', 'Отработано']);
Check::same('ждёт отметки', [AckValue::pending(9), AckValue::pending(0)], ['pending|9', 'pending|0']);
Check::same(
	'состояния: ждёт, отмечено (формат 1.8.0), пусто, мусор',
	[AckValue::state('pending|9'), AckValue::state('7|1800000000'), AckValue::state(''), AckValue::state('pending|x'), AckValue::state('pending')],
	[['state' => 'pending', 'userId' => 9, 'at' => 0, 'byAdmin' => false], ['state' => 'done', 'userId' => 7, 'at' => 1_800_000_000, 'byAdmin' => false], null, null, null]
);
Check::same('«ждёт» — не отмечено', AckValue::parse('pending|9'), null);
$pending = AckValue::state('pending|7');
$done = AckValue::state('7|1800000000');
Check::same(
	'кому кнопка, кому текст',
	[
		AckValue::decide('senior', $pending, 7, 3, 7),
		AckValue::decide('senior', $pending, 1, 3, 7),
		AckValue::decide('manager', $pending, 3, 3, 7),
		AckValue::decide('manager', $pending, 3, 3, 7, false),
		AckValue::decide('senior', $done, 7, 3, 7),
		AckValue::decide('senior', null, 7, 3, 7),
	],
	['button', 'pending', 'button', 'pending', 'done', 'empty']
);
Check::same(
	'тексты',
	[
		AckValue::text('senior', 'pending', 'Иванов Пётр', 0),
		AckValue::text('manager', 'pending', '', 0),
		AckValue::text('senior', 'done', 'Иванов Пётр', mktime(9, 5, 0, 10, 4, 2026)),
		AckValue::text('manager', 'done', 'Петров Иван', mktime(9, 5, 0, 10, 4, 2026)),
	],
	['Ждёт отметки: Иванов Пётр', 'Ждёт отметки', 'Под контролем: Иванов Пётр, 04.10.2026 09:05', 'Отработано: Петров Иван, 04.10.2026 09:05']
);
$button = AckType::buildHtml('senior', 15, 'button', '', 0);
Check::same('кнопка: действие модуля, сделка, роль', [str_contains($button, 'shef:toolsaicrm.dealflag.mark'), str_contains($button, 'dealId:15'), str_contains($button, 'role:&#039;senior&#039;'), str_contains($button, 'Риск под контролем'), str_contains($button, 'onclick="')], [true, true, true, true, true]);
Check::same('кнопка заметная: primary, sm', [str_contains($button, 'class="ui-btn ui-btn-primary ui-btn-sm"'), str_contains($button, 'ui-btn-light-border')], [true, false]);
Check::same('под кнопкой пояснение старшему', [str_contains($button, 'shef-toolsai-ack-hint'), str_contains($button, 'Нажмите, если риски по сделке устранены'), str_contains($button, '«AI: старший отметил»')], [true, true, true]);

Check::group('пояснение старшего (1.46.0)');
Check::same('старшему — окно с пояснением (1.63.5: ui.dialogs.messagebox, не BX.PopupWindow), менеджеру — сразу', [str_contains($button, 'MB.create({useAirDesign:true,title:'), str_contains($button, 'BX.PopupWindow'), str_contains($button, 'reason:r'), str_contains(AckType::buildHtml(AckValue::ROLE_MANAGER, 15, AckValue::VIEW_BUTTON, '', 0), 'MB.create')], [true, false, true, false]);
Check::same('без пояснения или коротко — отказ старшему', [AckValue::reasonError(AckValue::ROLE_SENIOR, '') !== null, AckValue::reasonError(AckValue::ROLE_SENIOR, 'ок, всё хорошо') !== null, AckValue::reasonError(AckValue::ROLE_SENIOR, str_repeat('я', AckValue::REASON_MAX + 1)) !== null], [true, true, true]);
Check::same('достаточно — пропускаем; менеджеру пояснение не нужно', [AckValue::reasonError(AckValue::ROLE_SENIOR, 'Клиент оплатил счёт 12.10, отгрузка назначена'), AckValue::reasonError(AckValue::ROLE_MANAGER, '')], [null, null]);
$node = trim((string)shell_exec('command -v node 2>/dev/null'));
if($node !== '' && preg_match('/onclick="([^"]*)"/', $button, $onclick))
{
	$file = tempnam(sys_get_temp_dir(), 'ack').'.js';
	file_put_contents($file, '(function(){'.html_entity_decode($onclick[1], ENT_QUOTES).'})');
	exec(escapeshellarg($node).' --check '.escapeshellarg($file).' 2>&1', $nodeOut, $nodeCode);
	Check::same('JS кнопки старшего разбирается (node --check)', $nodeCode, 0);
	@unlink($file);
}
Check::same('комментарий в ленту: кто и почему', AckValue::reasonComment('Иван Петров', "Оплата  пришла,\tотгрузка 15.10", true), "AI: риск под контролем — Иван Петров (за старшего).\nПочему риски устранены: Оплата пришла, отгрузка 15.10");
$mButton = AckType::buildHtml('manager', 7, 'button', '', 0);
Check::same('пояснение менеджеру', [str_contains($mButton, 'Нажмите, когда разберётесь с риском'), str_contains($mButton, '«AI: менеджер отметил»'), str_contains($mButton, 'Отработано')], [true, true, true]);
Check::same('без подтверждения, блокировка, ошибка рядом', [str_contains($button, 'confirm('), str_contains($button, 'alert('), str_contains($button, 'b.disabled=true'), str_contains($button, 'shef-toolsai-ack-error'), str_contains($button, 'e.textContent=')], [false, false, true, true, true]);
Check::same('не адресат — без пояснения', str_contains(AckType::buildHtml('manager', 15, 'pending', 'Иванов Пётр', 0), 'shef-toolsai-ack-hint'), false);
Check::same('не адресат — текст без кнопки', [str_contains(AckType::buildHtml('manager', 15, 'pending', 'Иванов Пётр', 0), '<button'), str_contains(AckType::buildHtml('manager', 15, 'pending', 'Иванов Пётр', 0), 'Ждёт отметки: Иванов Пётр')], [false, true]);
$marked = AckType::buildHtml('manager', 15, 'done', 'Иванов <b>Пётр</b>', mktime(9, 5, 0, 10, 4, 2026));
Check::same('отмечено — кто и когда, без кнопки, имя экранировано', [str_contains($marked, '<button'), str_contains($marked, 'Отработано: Иванов &lt;b&gt;Пётр&lt;/b&gt;, 04.10.2026 09:05')], [false, true]);

Check::group('администратор ставит отметки (1.8.2)');

Check::same('админ видит обе кнопки, даже не адресат; без прав админа — текст',
	[AckValue::decide('senior', $pending, 1, 3, 7, true, true), AckValue::decide('manager', $pending, 1, 3, 7, true, true), AckValue::decide('manager', $pending, 1, 3, 7, true, false), AckValue::decide('manager', $done, 1, 3, 7, true, true), AckValue::decide('manager', $pending, 1, 3, 7, false, true)],
	['button', 'button', 'pending', 'done', 'pending']);
Check::same('значение при нажатии: адресат, админ за адресата, чужой',
	[AckValue::markValue('manager', 3, 3, 7, false, 100), AckValue::markValue('manager', 1, 3, 7, true, 100), AckValue::markValue('senior', 7, 3, 7, true, 100), AckValue::markValue('manager', 1, 3, 7, false, 100), AckValue::markValue('bad', 1, 3, 7, true, 100), AckValue::markValue('senior', 0, 3, 7, true, 100)],
	['3|100', '1|100|admin', '7|100', null, null, null]);
Check::same('отметка админа читается и считается отмеченной', [AckValue::parse('1|100|admin'), EntityFields::doneFlag('1|100|admin'), AckValue::parse('1|100|x')], [['userId' => 1, 'at' => 100, 'byAdmin' => true], 1, null]);
Check::same('текст: за менеджера / за старшего',
	[AckValue::text('manager', 'done', 'Админов Иван', mktime(9, 5, 0, 10, 4, 2026), true), AckValue::text('senior', 'done', 'Админов Иван', mktime(9, 5, 0, 10, 4, 2026), true)],
	['Отработано: Админов Иван, 04.10.2026 09:05 (за менеджера)', 'Под контролем: Админов Иван, 04.10.2026 09:05 (за старшего)']);
$adminButton = AckType::buildHtml('manager', 7, 'button', '', 0, true);
Check::same('кнопка админа: своё пояснение, primary', [str_contains($adminButton, 'Вы администратор: отметка будет поставлена от вашего имени.'), str_contains($adminButton, 'ui-btn-primary'), str_contains($adminButton, 'Нажмите, когда')], [true, true, false]);
$controller = (string)file_get_contents($root.'/lib/controller/dealflag.php');
Check::same('контроллер: права через markValue с isAdmin, без своей проверки', [str_contains($controller, 'AckValue::markValue($role, $userId, $assigned, $senior, $isAdmin, time())'), str_contains($controller, '->isAdmin()'), str_contains($controller, 'AckValue::canMark(')], [true, true, false]);

Check::group('агенты без подмены пользователя (1.64.6, #291, решение владельца по options#48)');
foreach(['analysisagent'] as $agentFile)
{
	$agentSrc = (string)file_get_contents($root.'/lib/agent/'.$agentFile.'.php');
	Check::same($agentFile.': без FixUser, без initUser/closeUser', [str_contains($agentSrc, 'use FixUser;'), str_contains($agentSrc, 'initUser()'), str_contains($agentSrc, 'closeUser()')], [false, false, false]);
}
foreach(['lib/deal/escalation.php'] as $todoFile)
{
	$todoSrc = (string)file_get_contents($root.'/'.$todoFile);
	Check::same($todoFile.': дело агента — без проверки прав «текущего» и с явным автором', [str_contains($todoSrc, '$todo->setCheckPermissions(false);'), str_contains($todoSrc, '$todo->setAuthorId(\Shef\ToolsAiCrm\Container::getAgentUserId() ?: 1);')], [true, true]);
}

Check::finish();
