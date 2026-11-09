<?php declare(strict_types=1);

/**
 * Обработчики событий вида «AI» shef.toolsai: «Анализ» записи (OnViewAnalyzeRecord)
 * и состояние авто-анализа (OnViewAnalysisState). Ответ в формате публичного
 * API shef.toolsai: status/reason/message и enabled/settingsUrl.
 */

namespace
{
$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Shef\ToolsAiCrm\Internals\Integration\ToolsAi\EventHandler\OnViewAnalysisStateEventHandler;
use Shef\ToolsAiCrm\Internals\Integration\ToolsAi\EventHandler\OnViewAnalyzeRecordEventHandler;
use Shef\ToolsAiCrm\Robot\AnalyzeNow;

/** Событие с getParameter() ядра (в заглушке его нет). */
class ParamEvent extends Event
{
	public function getParameter(string $key): mixed
	{
		return $this->parameters[$key] ?? null;
	}
}

Check::group('«Анализ»: итог AnalyzeNow -> ответ события');
$answer = static fn(string $status, string $reason = '', string $message = 'Текст'): array => OnViewAnalyzeRecordEventHandler::answer(['status' => $status, 'reason' => $reason, 'message' => $message]);
Check::same('done — без причины', $answer(AnalyzeNow::DONE), ['status' => 'done', 'reason' => '', 'message' => 'Текст']);
Check::same('busy — пропуск, причина busy', $answer(AnalyzeNow::BUSY, AnalyzeNow::REASON_BUSY), ['status' => 'skipped', 'reason' => 'busy', 'message' => 'Текст']);
Check::same('skipped — по правилам профиля', $answer(AnalyzeNow::SKIPPED)['reason'], 'profileSkip');
Check::same('off: квота', $answer(AnalyzeNow::OFF, AnalyzeNow::REASON_QUOTA)['reason'], 'quota');
Check::same('off: лицензия', $answer(AnalyzeNow::OFF, AnalyzeNow::REASON_LICENSE)['reason'], 'license');
Check::same('off: тип не анализируется', $answer(AnalyzeNow::OFF, AnalyzeNow::REASON_UNSUPPORTED)['reason'], 'unsupported');
Check::same('off: нет профиля — «off»', $answer(AnalyzeNow::OFF, AnalyzeNow::REASON_PROFILE)['reason'], 'off');
Check::same('off — всегда status skipped', $answer(AnalyzeNow::OFF, AnalyzeNow::REASON_QUOTA)['status'], 'skipped');
Check::same('error', $answer(AnalyzeNow::ERROR, '', 'Ошибка анализа: сбой'), ['status' => 'error', 'reason' => 'error', 'message' => 'Ошибка анализа: сбой']);
Check::same('error без текста — текст по умолчанию', $answer(AnalyzeNow::ERROR, '', '')['message'], 'Ошибка анализа');
Check::same('чужой статус — error', $answer('weird')['status'], 'error');
Check::same('пустой итог — error', OnViewAnalyzeRecordEventHandler::answer([])['status'], 'error');

Check::group('«Анализ»: обработчик');
$result = OnViewAnalyzeRecordEventHandler::handle(new ParamEvent('shef.toolsai', 'OnViewAnalyzeRecord', ['entityTypeId' => 0, 'id' => 5, 'note' => '']));
Check::same('SUCCESS от имени модуля', [$result->getType(), $result->getModuleId()], [EventResult::SUCCESS, 'shef.toolsaicrm']);
Check::same('тип 0 — «не поддерживается», без обращения к порталу', array_intersect_key($result->getParameters(), ['status' => 1, 'reason' => 1]), ['status' => 'skipped', 'reason' => 'unsupported']);
$result = OnViewAnalyzeRecordEventHandler::handle(new ParamEvent('shef.toolsai', 'OnViewAnalyzeRecord', []));
Check::same('без параметров — тоже ответ', array_keys($result->getParameters()), ['status', 'reason', 'message']);
$allowed = ['done', 'skipped', 'error'];
Check::same('status — из контракта вида', in_array($result->getParameters()['status'], $allowed, true), true);
Check::same('reason — латиница, до 32 знаков (контракт вида)', (bool)preg_match('/^[A-Za-z0-9_]{1,32}$/', $result->getParameters()['reason']), true);

Check::group('Состояние анализа');
$state = OnViewAnalysisStateEventHandler::answer(true);
Check::same('включён', $state['enabled'], true);
Check::same('адрес — раздел «Авто-анализ CRM»', $state['settingsUrl'], '/shef/toolsaicrm/?section=profiles');
Check::same('выключен', OnViewAnalysisStateEventHandler::answer(false)['enabled'], false);
Check::same('адрес по контракту вида: путь с одной «/», без кавычек и пробелов', (bool)preg_match('~^/[^/\s"\'<>\\\\]~', $state['settingsUrl']), true);
$result = OnViewAnalysisStateEventHandler::handle(new ParamEvent('shef.toolsai', 'OnViewAnalysisState', ['entityTypeId' => 0]));
Check::same('тип 0 — выключен', [$result->getType(), $result->getParameters()['enabled'], $result->getModuleId()], [EventResult::SUCCESS, false, 'shef.toolsaicrm']);
$result = OnViewAnalysisStateEventHandler::handle(new ParamEvent('shef.toolsai', 'OnViewAnalysisState', ['entityTypeId' => 2]));
Check::same('сбой чтения профилей — выключен, не исключение', $result->getParameters()['enabled'], false);

Check::finish();
}
