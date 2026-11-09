<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Log;

/**
 * Куда пишет лог модуля. Фабрика shef.problems вешает на логгер два
 * обработчика: файл sh_log/<тип>.log и журнал событий Битрикс24
 * (b_event_log). Модулю нужен только журнал: его видно в интерфейсе
 * («Настройки → Инструменты → Журнал событий»), и его чистит штатный агент
 * ядра по сроку event_log_cleanup_days. Файловые обработчики убираем.
 *
 * Чистая логика, без ядра.
 */
final class Handlers
{
	/**
	 * @param array<int, object> $handlers обработчики Monolog
	 * @return list<object> без записи в файл
	 */
	public static function withoutFiles(array $handlers): array
	{
		return array_values(array_filter(
			$handlers,
			static fn(object $handler): bool => !$handler instanceof \Monolog\Handler\StreamHandler
		));
	}
}
