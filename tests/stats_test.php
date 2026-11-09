<?php declare(strict_types=1);

/**
 * Оценка звонков и менеджеров в «Статистике» — чистая логика.
 *
 * Что держит:
 *
 * * RESULT оценки звонка: criteria в корне (так пишет ядро —
 *   Json::encode(ScoreCallPayload)) или в call_review; status только bool;
 *   мусор — пусто;
 * * оценка менеджеров: по ответственным, худшие первыми; один запрос по
 *   всем типам записи.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiCrm\Stats\ScoreResult;


Check::group('RESULT оценки звонка');

$core = json_encode([
	'criteria' => [
		['criterion' => 'Поздоровался', 'status' => true, 'explanation' => '…'],
		['criterion' => 'Назвал цену', 'status' => false],
		['criterion' => 'Не оценён', 'status' => null],
		['criterion' => '', 'status' => false],
		['criterion' => 'Строка вместо bool', 'status' => 'false'],
		'мусор',
	],
	'overallSummary' => 'итог',
], JSON_UNESCAPED_UNICODE);
Check::same('как пишет ядро — criteria в корне', ScoreResult::parseCriteria($core), [
	['criterion' => 'Поздоровался', 'status' => true],
	['criterion' => 'Назвал цену', 'status' => false],
]);
Check::same(
	'сырой ответ модели — call_review.criteria',
	ScoreResult::parseCriteria('{"call_review":{"criteria":[{"criterion":"  Договорился\n о звонке ","status":false}]}}'),
	[['criterion' => 'Договорился о звонке', 'status' => false]]
);
foreach(['', '{', 'null', '"x"', '{"criteria":"x"}', '{"recommendations":"только советы"}'] as $bad)
{
	Check::same('мусор — пусто: '.$bad, ScoreResult::parseCriteria($bad), []);
}
Check::same('не строка — пусто', ScoreResult::parseCriteria(null), []);

Check::group('оценка работы менеджеров (1.67.0)');

Check::same('по ответственным, худшие первыми; -1, мусор и чужой JSON — мимо', \Shef\ToolsAiCrm\Stats\ManagerScores::aggregate([
	['GRP' => 7, 'EXTRA' => '{"managerScore":80,"managerReview":"ок"}'],
	['GRP' => 7, 'EXTRA' => '{"managerScore":60}'],
	['GRP' => 9, 'EXTRA' => '{"managerScore":30,"mood":"x"}'],
	['GRP' => 9, 'EXTRA' => '{"managerScore":-1}'],
	['GRP' => 9, 'EXTRA' => '{"managerScore":"50"}'],
	['GRP' => 5, 'EXTRA' => 'не json'],
	['GRP' => 5, 'EXTRA' => null],
	['GRP' => 5, 'EXTRA' => '{"managerScore":101}'],
]), [
	['user' => 9, 'count' => 1, 'avg' => 30.0, 'min' => 30],
	['user' => 7, 'count' => 2, 'avg' => 70.0, 'min' => 60],
]);



Check::group('оценка менеджеров: один запрос для страницы и MCP — сделки, лиды и смарт-процессы');
$sql = \Shef\ToolsAiCrm\Stats\ManagerScores::historySql([2 => 'b_crm_deal', 1 => 'b_crm_lead', 1038 => 'b_crm_dynamic_items_1038'], 'shef_toolsaicrm_analysis_history', "'2026-10-01 00:00:00'", "'2026-11-01 00:00:00'", 0, 20000);
Check::same('все типы анализа', str_contains($sql, 'h.ENTITY_TYPE_ID IN (2,1,1038)'), true);
Check::same('ответственный — по типу записи', str_contains($sql, 'COALESCE(o2.ASSIGNED_BY_ID, o1.ASSIGNED_BY_ID, o1038.ASSIGNED_BY_ID, 0) AS GRP'), true);
Check::same('период и лимит', str_contains($sql, "h.CREATED_AT >= '2026-10-01 00:00:00' AND h.CREATED_AT < '2026-11-01 00:00:00'") && str_ends_with(trim($sql), 'LIMIT 20000'), true);
Check::same('без сотрудника — без фильтра', str_contains($sql, 'ASSIGNED_BY_ID = '), false);
$one = \Shef\ToolsAiCrm\Stats\ManagerScores::historySql([2 => 'b_crm_deal', 1 => 'b_crm_lead'], 'h_t', "'a'", "'b'", 7, 10);
Check::same('сотрудник — по ответственному любого типа', str_contains($one, 'AND COALESCE(o2.ASSIGNED_BY_ID, o1.ASSIGNED_BY_ID, 0) = 7'), true);

Check::finish();
