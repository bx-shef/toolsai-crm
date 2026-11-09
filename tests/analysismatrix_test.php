<?php declare(strict_types=1);

/**
 * Матрица «тип записи × источник × выход» анализа (1.69.1): сделка, лид и
 * элемент смарт-процесса получают одну и ту же сводку — звонки с резюме и
 * оценкой, письма и чаты в блоке «Переписка» — и одни и те же выходы:
 * «Статистика» (Stats\AnalysisOwners, Stats\ManagerScores) считает все типы,
 * необработанные обращения клиента не выдают себя за запланированные дела
 * (ContextBuilder::isPlannable()).
 */

$root = dirname(__DIR__);
require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

// Константы ядра CRM, которых нет в заглушках (crm/classes/general/crm_activity.php)
if(!class_exists('CCrmActivityType'))
{
	eval('final class CCrmActivityType { public const Meeting = 1; public const Call = 2; public const Task = 3; public const Email = 4; public const Provider = 6; }');
	eval('final class CCrmActivityDirection { public const Undefined = 0; public const Incoming = 1; public const Outgoing = 2; }');
}

use Shef\ToolsAiCrm\Analysis\EntityType;
use Shef\ToolsAiCrm\Deal\ContextBuilder;
use Shef\ToolsAiCrm\Deal\Conversation;
use Shef\ToolsAiCrm\Deal\DealFacts;
use Shef\ToolsAiCrm\Stats\AnalysisOwners;
use Shef\ToolsAiCrm\Stats\ManagerScores;

date_default_timezone_set('UTC');
$at = (int)strtotime('2026-10-27 12:00 UTC');

$types = [
	'сделка' => EntityType::deal(),
	'лид' => EntityType::lead(),
	'смарт-процесс' => EntityType::dynamic(1038, 'CRM_4', 'Заявки на ремонт'),
];
$conversation = Conversation::render(
	[
		['at' => $at, 'direction' => Conversation::DIRECTION_IN, 'subject' => 'Протечка', 'text' => 'Назовите хотя бы день выезда.'],
		['at' => $at + 60, 'direction' => Conversation::DIRECTION_OUT, 'subject' => 'Re: Протечка', 'text' => 'мастеров нет'],
	],
	[['at' => $at + 120, 'subject' => 'Чат открытой линии', 'text' => "[27.10 12:00] Бот (Помощник): Здравствуйте!\n[27.10 12:01] Клиент: когда мастер?\n[27.10 14:00] Менеджер (Дмитрий): не знаю"]]
);
$facts = static fn(EntityType $type, string $conversation): DealFacts => new DealFacts(
	dealId: 6, title: 'Ремонт насосной', stage: 'Начало', opportunity: 0.0, currency: '',
	daysSinceCreated: 0, daysSinceLastActivity: 4, stageRollbacks: 0, callsTotal: 1, callsIncoming: 1,
	outgoingWithoutAnswer: 0, recentNotes: [], assignedById: 7,
	callNotes: ['27.10.2026, входящий: Резюме: клиент просит срок. Оценка по скрипту: 22%'],
	noun: $type->noun, conversation: $conversation,
);

Check::group('сводка: звонки, письма и чаты — у каждого типа');
foreach($types as $name => $type)
{
	$text = $facts($type, $conversation)->toPromptText();
	Check::same($name.': название типа, звонок с оценкой, письмо от клиента и ответ, чат с ботом', [
		str_starts_with($text, $type->noun.' #6: Ремонт насосной'),
		str_contains($text, 'Оценка по скрипту: 22%'),
		str_contains($text, 'Письмо от клиента «Протечка»'),
		str_contains($text, 'Письмо менеджера клиенту «Re: Протечка»'),
		str_contains($text, 'Бот (Помощник)'),
		str_contains($text, Conversation::OPEN) && str_contains($text, Conversation::CLOSE),
	], [true, true, true, true, true, true]);
	$only = new DealFacts(dealId: 1, title: 'x', stage: '', opportunity: 0.0, currency: '', daysSinceCreated: 9, daysSinceLastActivity: 9,
		stageRollbacks: 0, callsTotal: 0, callsIncoming: 0, outgoingWithoutAnswer: 0, recentNotes: [], noun: $type->noun, conversation: $conversation);
	Check::same($name.': одна переписка — есть что оценить, без неё («Не передавать переписку») — нечего', [$only->hasNothingToAssess(), $only->withoutConversation()->hasNothingToAssess()], [false, true]);
	Check::same($name.': пропуск по тишине называет тип', str_contains((string)$facts($type, '')->skipReason(10), mb_strtolower(mb_substr($type->noun, 0, 1)).mb_substr($type->noun, 1).' в работе'), true);
}

Check::group('запланированные дела: необработанное обращение клиента — не дело менеджера');
$call = \CCrmActivityType::Call;
$email = \CCrmActivityType::Email;
$in = \CCrmActivityDirection::Incoming;
$out = \CCrmActivityDirection::Outgoing;
Check::same('входящий звонок, письма в обе стороны, чат открытой линии — нет', [
	ContextBuilder::isPlannable($call, $in, 'VOXIMPLANT_CALL'),
	ContextBuilder::isPlannable($email, $in, 'CRM_EMAIL'),
	ContextBuilder::isPlannable($email, $out, 'CRM_EMAIL'),
	ContextBuilder::isPlannable(\CCrmActivityType::Provider, $in, 'IMOPENLINES_SESSION'),
], [false, false, false, false]);
Check::same('запланированный исходящий звонок, встреча, задача, «дело» — да', [
	ContextBuilder::isPlannable($call, $out, 'VOXIMPLANT_CALL'),
	ContextBuilder::isPlannable(\CCrmActivityType::Meeting, 0, 'CRM_MEETING'),
	ContextBuilder::isPlannable(\CCrmActivityType::Task, 0, 'CRM_TASKS_TASK'),
	ContextBuilder::isPlannable(\CCrmActivityType::Provider, 0, 'CRM_TODO'),
], [true, true, true, true]);
$source = (string)file_get_contents($root.'/lib/deal/contextbuilder.php');
Check::same('сборка сводки спрашивает isPlannable() до счёта запланированных и читает PROVIDER_ID', [
	str_contains($source, "'DIRECTION', 'PROVIDER_ID'"),
	strpos($source, 'static::isPlannable(') < strpos($source, '$openActivities++'),
], [true, true]);

Check::group('«Статистика»: все типы с профилем, ответственный — из таблицы записи');
$tables = [2 => 'b_crm_deal', 1 => 'b_crm_lead', 1038 => 'b_crm_dynamic_items_1038'];
Check::same('условие на типы', AnalysisOwners::scope($tables), 'ENTITY_TYPE_ID IN (2,1,1038)');
Check::same('без типов — сделки, как до 1.69.1', AnalysisOwners::scope([]), 'ENTITY_TYPE_ID IN (2)');
Check::same('join на каждый тип — по типу и первичному ключу', AnalysisOwners::joins($tables, 'h'),
	' LEFT JOIN b_crm_deal o2 ON h.ENTITY_TYPE_ID = 2 AND o2.ID = h.ENTITY_ID'
	.' LEFT JOIN b_crm_lead o1 ON h.ENTITY_TYPE_ID = 1 AND o1.ID = h.ENTITY_ID'
	.' LEFT JOIN b_crm_dynamic_items_1038 o1038 ON h.ENTITY_TYPE_ID = 1038 AND o1038.ID = h.ENTITY_ID');
Check::same('ответственный — первый найденный', AnalysisOwners::owner($tables), 'COALESCE(o2.ASSIGNED_BY_ID, o1.ASSIGNED_BY_ID, o1038.ASSIGNED_BY_ID, 0)');
Check::same('в SQL — только таблицы CRM и целые типы', AnalysisOwners::filter([2 => 'b_crm_deal', 3 => 'b_crm_deal; DROP TABLE x', 0 => 'b_crm_lead', 1040 => 'b_user']), [2 => 'b_crm_deal']);
Check::same('оценка менеджеров — по строкам всех типов', ManagerScores::aggregate([
	['GRP' => 7, 'EXTRA' => '{"managerScore":90}'],  // сделка
	['GRP' => 7, 'EXTRA' => '{"managerScore":10}'],  // лид
	['GRP' => 9, 'EXTRA' => '{"managerScore":20}'],  // смарт-процесс
]), [['user' => 9, 'count' => 1, 'avg' => 20.0, 'min' => 20], ['user' => 7, 'count' => 2, 'avg' => 50.0, 'min' => 10]]);

Check::finish();
