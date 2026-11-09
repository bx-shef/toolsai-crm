<?php declare(strict_types=1);

/**
 * Агенты анализа по типам: Settings\AnalysisAgents — чистое планирование.
 *
 * Что держит:
 *
 * * имя агента разбирается строго: только модуль shef.toolsaicrm и числовой тип;
 *   старое имя shef.toolsai и мусор — не агент модуля (0);
 * * имя регистрации совпадает с Constants::AGENT_ANALYSIS и разбирается обратно;
 * * план: включённые типы — зарегистрировать (без повторов, по возрастанию),
 *   агент типа без включённого профиля — снять, чужое и мусор не трогать;
 * * обновление: интервал сверяется, первый запуск не дальше интервала от «сейчас».
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiCrm\Main\Constants;
use Shef\ToolsAiCrm\Settings\AnalysisAgents as A;

Check::boot();

Check::group('имя агента');

Check::same('сделки', A::parseName('\Shef\ToolsAiCrm\Agent\AnalysisAgent::run(2);'), 2);
Check::same('без ведущего слеша', A::parseName('Shef\ToolsAiCrm\Agent\AnalysisAgent::run(3);'), 3);
Check::same('смарт-процесс', A::parseName('\Shef\ToolsAiCrm\Agent\AnalysisAgent::run(1038);'), 1038);
Check::same('старое имя shef.toolsai — не наш агент', A::parseName('\Shef\ToolsAi\Agent\AnalysisAgent::run(2);'), 0);
Check::same('мусор вместо типа', [A::parseName('\Shef\ToolsAiCrm\Agent\AnalysisAgent::run(x);'), A::parseName('\Shef\ToolsAiCrm\Agent\AnalysisAgent::run();'), A::parseName('\Shef\ToolsAiCrm\Agent\AnalysisAgent::run(2); evil();'), A::parseName('')], [0, 0, 0, 0]);
Check::same('тип из десяти цифр — не агент', A::parseName('\Shef\ToolsAiCrm\Agent\AnalysisAgent::run(1234567890);'), 0);
Check::same('регистрируемое имя разбирается обратно', A::parseName(A::name(1038)), 1038);
Check::same('имя — по шаблону Constants', A::name(2), sprintf(Constants::AGENT_ANALYSIS, 2));
Check::same('имя точно как в договоре', A::name(2), '\Shef\ToolsAiCrm\Agent\AnalysisAgent::run(2);');

Check::group('план');

$existing = [
	10 => '\Shef\ToolsAiCrm\Agent\AnalysisAgent::run(2);',
	11 => '\Shef\ToolsAiCrm\Agent\AnalysisAgent::run(3);',
	12 => '\Shef\ToolsAiCrm\Agent\AnalysisAgent::run(1038);',
	13 => '\Shef\ToolsAiCrm\Foo::bar();',
];
Check::same('все типы есть — снимать нечего', A::plan([2, 3, 1038], $existing), ['ensure' => [2, 3, 1038], 'remove' => []]);
Check::same('у лидов профиль выключен — агент лидов снят', A::plan([2, 1038], $existing), ['ensure' => [2, 1038], 'remove' => [11]]);
Check::same('новый тип — регистрируется', A::plan([2, 3, 1038, 1040], $existing), ['ensure' => [2, 3, 1038, 1040], 'remove' => []]);
Check::same('профилей нет — снять все агенты анализа, чужое не трогать', A::plan([], $existing), ['ensure' => [], 'remove' => [10, 11, 12]]);
Check::same('повторы и мусор в типах', A::plan([3, '2', 3, 0, -5], $existing), ['ensure' => [2, 3], 'remove' => [12]]);

Check::group('обновление существующего агента');

Check::same('интервал тот же, запуск скоро — менять нечего', A::planUpdate(3600, 5000, 3600, 4000), []);
Check::same('интервал изменился', A::planUpdate(3600, 5000, 1800, 4000), ['AGENT_INTERVAL' => 1800]);
Check::same('запуск дальше нового интервала — подтягивается', A::planUpdate(3600, 100000, 3600, 4000), ['NEXT_EXEC' => 7600]);
Check::same('и интервал, и запуск', A::planUpdate(86400, 100000, 600, 4000), ['AGENT_INTERVAL' => 600, 'NEXT_EXEC' => 4600]);
Check::same('следующий запуск неизвестен — только интервал', A::planUpdate(60, null, 120, 4000), ['AGENT_INTERVAL' => 120]);
Check::same('запуск чуть дальше интервала — подтягивается (граница)', A::planUpdate(3600, 4000 + 3601, 3600, 4000), ['NEXT_EXEC' => 7600]);
Check::same('запуск ровно через интервал — не трогаем', A::planUpdate(600, 4600, 600, 4000), []);

Check::finish();
