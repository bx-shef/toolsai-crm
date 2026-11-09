<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Main;

/**
 * Всё, что переносу данных из shef.toolsai нужно от ядра: таблицы, опции, агенты,
 * подписки на события, блокировки. Логика переноса (Main\Migration) знает только
 * этот интерфейс и потому проверяется без базы; настоящая реализация — Main\CoreMigrationPort.
 */
interface MigrationPort
{
	public function tableExists(string $table): bool;

	/**
	 * @return list<string> столбцы таблицы, ЗАГЛАВНЫМИ
	 */
	public function columns(string $table): array;

	/**
	 * Конец следующего пакета: наибольший ID среди $limit строк источника с ID >= $fromId
	 * (при $codePrefix — только строки с CODE на этот префикс). null — строк больше нет.
	 */
	public function batchEnd(string $table, ?string $codePrefix, int $fromId, int $limit): ?int;

	/**
	 * Пакет: INSERT IGNORE ... SELECT строк источника с ID от $fromId до $toId. Ключ CODE —
	 * только строки, которых в приёмнике ещё нет по этому коду.
	 *
	 * @param list<string> $columns
	 * @return int сколько строк вставлено
	 */
	public function copyRange(string $source, string $target, array $columns, string $key, ?string $codePrefix, int $fromId, int $toId): int;

	/**
	 * Сверка: строки источника (с префиксом CODE, если он задан) против приёмника по ключу и
	 * по СОДЕРЖИМОМУ общих столбцов $columns.
	 *
	 * @param list<string> $columns общие столбцы (ключ среди них)
	 * @return array{source: int, missing: int, sumSource: string, sumTarget: string}
	 *         source — строк в источнике; missing — из них без пары в приёмнике;
	 *         sumSource / sumTarget — сумма CRC32 строки общих столбцов (значения через «|», NULL
	 *         отличается от пустой строки) по строкам источника и по найденным парам:
	 *         равны, только если у каждой пары содержимое совпало
	 */
	public function compare(string $source, string $target, string $key, ?string $codePrefix, array $columns): array;

	/**
	 * Настройки модуля, записанные в базе (имя => значение).
	 *
	 * @return array<string, string>
	 */
	public function options(string $moduleId): array;

	public function setOption(string $moduleId, string $name, string $value): void;

	public function deleteOption(string $moduleId, string $name): void;

	/**
	 * Агенты модуля: ID => имя.
	 *
	 * @return array<int, string>
	 */
	public function agents(string $moduleId): array;

	public function deleteAgent(int $agentId): void;

	public function unregisterEvent(string $fromModule, string $event, string $toModule, string $class, string $method): void;

	/** Идёт ли ещё прогон старого агента анализа (его блокировка жива). */
	public function oldRunInProgress(): bool;

	public function sleep(int $seconds): void;

	public function now(): int;
}
