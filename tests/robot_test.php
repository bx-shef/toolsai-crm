<?php declare(strict_types=1);

/**
 * Робот «AI: оценить сейчас» (1.29.0, #73) — без ядра.
 *
 * Что держит: запуск при каждом вызове без паузы, отказ без лицензии; описание робота — для сделок,
 * лидов и смарт-процессов, тип robot_activity, возвращает риск, почему,
 * что сделать и итог; класс и файл совпадают с именем каталога (так ядро
 * ищет действие).
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiCrm\Main\Constants;
use Shef\ToolsAiCrm\Robot\AnalyzeNow;

Check::group('запуск при каждом вызове');
// Робот запускается каждый раз, когда его вызывает процесс: паузы между
// запусками нет, частоту задаёт процесс, расход — квота модуля.
$calls = 0;
$analyze = static function() use (&$calls): array { $calls++; return [5 => ['risk' => 40, 'action' => 'ok']]; };
$fresh = static fn(): array => ['CHECKED_AT' => new \Bitrix\Main\Type\DateTime(), 'RISK' => 40, 'SKIPPED' => 'N', 'WHY' => 'w', 'NEXT_STEP' => 'n'];
$yes = static fn(): bool => true;
$none = static fn(): string => '';
$no = static fn(): bool => false;
$first = AnalyzeNow::runWith(\Shef\ToolsAiCrm\Analysis\EntityType::DEAL, 5, $yes, $none, $no, $analyze, $fresh);
$second = AnalyzeNow::runWith(\Shef\ToolsAiCrm\Analysis\EntityType::DEAL, 5, $yes, $none, $no, $analyze, $fresh);
Check::same('два вызова подряд — два анализа', $calls, 2);
Check::same('второй вызов — анализ, не пропуск', [$first['status'], $second['status']], [AnalyzeNow::DONE, AnalyzeNow::DONE]);
Check::same('итог — из строки проверки', [$second['risk'], $second['why'], $second['nextStep']], [40, 'w', 'n']);
$calls = 0;
$off = AnalyzeNow::runWith(\Shef\ToolsAiCrm\Analysis\EntityType::DEAL, 5, static fn(): bool => false, static fn(): string => 'срок закончился', $no, $analyze, $fresh);
Check::same('без лицензии — без анализа', [$off['status'], $calls], [AnalyzeNow::OFF, 0]);
Check::same('без лицензии — причина', $off['message'], 'Анализ не запущен: срок закончился');
Check::same('занято агентом', AnalyzeNow::runWith(\Shef\ToolsAiCrm\Analysis\EntityType::DEAL, 5, $yes, $none, $no, static fn(): ?array => null, $fresh)['status'], AnalyzeNow::BUSY);

// #506: у каждого отказа своя причина — квота не выдаётся за «нет профиля»
$calls = 0;
$q = AnalyzeNow::runWith(\Shef\ToolsAiCrm\Analysis\EntityType::DEAL, 5, $yes, $none, $yes, $analyze, $fresh);
Check::same('квота исчерпана — без анализа, причина quota', [$q['status'], $q['reason'], $calls], [AnalyzeNow::OFF, AnalyzeNow::REASON_QUOTA, 0]);
Check::same('квота — сообщение о квоте и разделе', [$q['message'] === AnalyzeNow::quotaMessage('анализ не запущен'), str_starts_with($q['message'], 'Месячная квота AI исчерпана'), str_contains($q['message'], 'Расход и включение')], [true, true, true]);
$stopped = static fn(string $code): \Closure => static fn(): array => [5 => ['risk' => 0, 'skipped' => false, 'error' => 'прогон остановлен: x', 'stop' => $code, 'action' => '']];
$late = AnalyzeNow::runWith(\Shef\ToolsAiCrm\Analysis\EntityType::DEAL, 5, $yes, $none, $no, $stopped(\Shef\ToolsAiCrm\Analysis\Runner::STOP_QUOTA), $fresh);
Check::same('квота кончилась во время прогона (строка Runner с stop) — quota', [$late['reason'], $late['message']], [AnalyzeNow::REASON_QUOTA, AnalyzeNow::quotaMessage('анализ не запущен')]);
$lateLic = AnalyzeNow::runWith(\Shef\ToolsAiCrm\Analysis\EntityType::DEAL, 5, $yes, static fn(): string => 'срок закончился', $no, $stopped(\Shef\ToolsAiCrm\Analysis\Runner::STOP_LICENSE), $fresh);
Check::same('лицензия кончилась во время прогона — license', [$lateLic['reason'], $lateLic['message']], [AnalyzeNow::REASON_LICENSE, 'Анализ не запущен: срок закончился']);
$lateProv = AnalyzeNow::runWith(\Shef\ToolsAiCrm\Analysis\EntityType::DEAL, 5, $yes, $none, $no, $stopped(\Shef\ToolsAiCrm\Analysis\Runner::STOP_PROVIDER), $fresh);
Check::same('провайдер не отвечает — ошибка с причиной', [$lateProv['status'], $lateProv['message']], [AnalyzeNow::ERROR, 'Ошибка анализа: прогон остановлен: x']);
$prof = AnalyzeNow::runWith(\Shef\ToolsAiCrm\Analysis\EntityType::DEAL, 5, $yes, $none, $no, static fn(): array => [], $fresh);
Check::same('нет строки при квоте и лицензии — нет профиля', [$prof['status'], $prof['reason'], $prof['message']], [AnalyzeNow::OFF, AnalyzeNow::REASON_PROFILE, 'Нет включённого профиля анализа для этой записи.']);
Check::same('без лицензии — причина license', $off['reason'], AnalyzeNow::REASON_LICENSE);
Check::same('занято — причина busy', AnalyzeNow::runWith(\Shef\ToolsAiCrm\Analysis\EntityType::DEAL, 5, $yes, $none, $no, static fn(): ?array => null, $fresh)['reason'], AnalyzeNow::REASON_BUSY);
Check::same('лицензия проверяется раньше квоты', AnalyzeNow::runWith(\Shef\ToolsAiCrm\Analysis\EntityType::DEAL, 5, $no, $none, $yes, $analyze, $fresh)['reason'], AnalyzeNow::REASON_LICENSE);
Check::same('паузы между запусками в коде нет', class_exists('Shef\ToolsAi\Robot\Throttle'), false);
// Боевой run() — только передача в runWith(), без своих проверок
$an = (string)file_get_contents($root.'/lib/robot/analyzenow.php');
Check::same('run() — сразу runWith()', (bool)preg_match('/function run\(int \$entityTypeId, int \$id\): array\s*\{\s*return self::runWith\(/', $an), true);
// Действия роботов: от входа в try до вызова — ни одного выхода и пропуска
foreach(['analyze' => 'AnalyzeNow::run('] as $dir => $call)
{
	$src = (string)file_get_contents($root.'/activities/'.$dir.'/shtoolsai'.$dir.'activity.php');
	$ok = preg_match('/\btry\s*\{(.*?)'.preg_quote($call, '/').'/s', $src, $m) === 1;
	Check::same($dir.': вызов в try без return и if до него', [$ok, $ok && str_contains($m[1], 'return'), $ok && str_contains($m[1], 'if(')], [true, false, false]);
}

Check::group('описание робота');
define('B_PROLOG_INCLUDED', true);
$arActivityDescription = null;
require $root.'/activities/analyze/.description.php';
Check::same('робот', in_array('robot_activity', $arActivityDescription['TYPE'], true), true);
Check::same('возвращает', array_keys($arActivityDescription['RETURN']), ['Risk', 'Why', 'NextStep', 'Status']);
Check::same('документы', array_column($arActivityDescription['FILTER']['INCLUDE'], 1), ['CCrmDocumentDeal', 'CCrmDocumentLead', 'Bitrix\\Crm\\Integration\\BizProc\\Document\\Dynamic']);
$dir = basename(Constants::ROBOT_ANALYZE_DIR);
Check::same('каталог = класс без CBP в нижнем регистре', strtolower($arActivityDescription['CLASS']), $dir);
Check::same('файл класса — по имени каталога', is_file($root.Constants::ROBOT_ANALYZE_MODULE_DIR.'/'.$dir.'.php'), true);
Check::same('класс в файле', str_contains((string)file_get_contents($root.Constants::ROBOT_ANALYZE_MODULE_DIR.'/'.$dir.'.php'), 'class CBP'.$arActivityDescription['CLASS'].' extends CBPActivity'), true);



Check::finish();
