<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Internals\Integration\ToolsAi\EventHandler;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Shef\ToolsAiCrm\Main\Constants;
use Shef\ToolsAiCrm\Robot\AnalyzeNow;

/**
 * Обработчик shef.toolsai:OnViewAnalyzeRecord — действие «Анализ» вида «AI»:
 * анализирует запись сам (Robot\AnalyzeNow::run, тот же путь, что у робота БП
 * и агента) и возвращает итог в виде, который ждёт вид.
 *
 * Контракт события (shef.toolsai Public\Event\ViewAnalyzeRecordEvent): параметры
 * entityTypeId, id, note; ответ EventResult SUCCESS с ['status' => done|skipped|error,
 * 'reason' => ключ причины, 'message' => текст] от имени модуля. Права на запись
 * в CRM вид проверил до события.
 *
 * Причины — как были у вида до выноса анализа: busy (идёт прогон агента),
 * quota, license, unsupported (тип не анализируется), off (нет включённого
 * профиля), profileSkip (пропуск по правилам профиля), error. Пометку запуска
 * (note) публичный API журнала расхода не принимает — она не применяется.
 *
 * Сбой — ответ error, а не исключение: вид не должен падать из-за соседа.
 * Чистая часть — answer(), tests/viewevents_test.php.
 */
final class OnViewAnalyzeRecordEventHandler
{
	public static function handle(Event $event): EventResult
	{
		try
		{
			$result = AnalyzeNow::run((int)($event->getParameter('entityTypeId') ?? 0), (int)($event->getParameter('id') ?? 0));
		}
		catch(\Throwable)
		{
			$result = ['status' => AnalyzeNow::ERROR, 'reason' => '', 'message' => 'Ошибка анализа'];
		}

		return new EventResult(EventResult::SUCCESS, static::answer($result), Constants::MODULE_ID);
	}

	/**
	 * Итог AnalyzeNow::run() — ответ события.
	 *
	 * @param array{status?: string, reason?: string, message?: string} $result
	 * @return array{status: string, reason: string, message: string}
	 */
	public static function answer(array $result): array
	{
		$status = (string)($result['status'] ?? '');
		$message = (string)($result['message'] ?? '');
		$reason = (string)($result['reason'] ?? '');

		return match($status)
		{
			AnalyzeNow::DONE => ['status' => 'done', 'reason' => '', 'message' => $message],
			AnalyzeNow::BUSY => ['status' => 'skipped', 'reason' => 'busy', 'message' => $message],
			AnalyzeNow::SKIPPED => ['status' => 'skipped', 'reason' => 'profileSkip', 'message' => $message],
			// отказ робота: квота, лицензия и неподдерживаемый тип — свои причины, остальное — «нет профиля»
			AnalyzeNow::OFF => ['status' => 'skipped', 'reason' => match($reason)
			{
				AnalyzeNow::REASON_QUOTA => 'quota',
				AnalyzeNow::REASON_LICENSE => 'license',
				AnalyzeNow::REASON_UNSUPPORTED => 'unsupported',
				default => 'off',
			}, 'message' => $message],
			default => ['status' => 'error', 'reason' => 'error', 'message' => $message !== '' ? $message : 'Ошибка анализа'],
		};
	}
}
