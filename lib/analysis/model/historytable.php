<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Analysis\Model;

use Bitrix\Main\Application;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;
use Bitrix\Main\Type\DateTime;

/**
 * История риска (1.9.0): строка на каждый анализ с обращением к модели —
 * сделки, лида, смарт-процесса.
 *
 *   MARKED — стояла ли хоть одна отметка («Отработано» / «Риск под
 *   контролем») к моменту этого анализа: так модель видит «три анализа
 *   подряд — ничего не сделано» (Analysis\History).
 *   EXTRA  — свои поля ответа модели из шаблона промпта (JSON).
 *
 * Чистит агент: старше History::KEEP_DAYS дней (cleanup()).
 */
class HistoryTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'shef_toolsaicrm_analysis_history';
	}

	public static function getMap(): array
	{
		return [
			(new Fields\IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new Fields\IntegerField('ENTITY_TYPE_ID'))->configureRequired(),
			(new Fields\IntegerField('ENTITY_ID'))->configureRequired(),
			(new Fields\DatetimeField('CREATED_AT'))->configureRequired(),
			(new Fields\IntegerField('RISK'))->configureDefaultValue(0),
			(new Fields\StringField('WHY'))->configureSize(255)->configureNullable(),
			(new Fields\StringField('ACTION'))->configureSize(500)->configureNullable(),
			(new Fields\IntegerField('COST_MICRO'))->configureDefaultValue(0),
			(new Fields\BooleanField('MARKED'))->configureValues('N', 'Y')->configureDefaultValue('N'),
			(new Fields\TextField('EXTRA'))->configureNullable(),
		];
	}

	/** Создать таблицу, если нет. Идемпотентно: установщик и «Проверить и включить». */
	public static function init(): void
	{
		$connection = Application::getConnection();
		if($connection->isTableExists(static::getTableName()))
		{
			return;
		}

		$connection->queryExecute(sprintf(
			'CREATE TABLE %s (
				ID INT(11) NOT NULL AUTO_INCREMENT,
				ENTITY_TYPE_ID INT(11) NOT NULL,
				ENTITY_ID INT(11) NOT NULL,
				CREATED_AT DATETIME NOT NULL,
				RISK INT(11) NOT NULL DEFAULT 0,
				WHY VARCHAR(255) NULL,
				ACTION VARCHAR(500) NULL,
				COST_MICRO BIGINT NOT NULL DEFAULT 0,
				MARKED CHAR(1) NOT NULL DEFAULT \'N\',
				EXTRA TEXT NULL,
				PRIMARY KEY (ID),
				KEY IX_SHEF_TOOLSAI_HISTORY_ENTITY (ENTITY_TYPE_ID, ENTITY_ID, CREATED_AT),
				KEY IX_SHEF_TOOLSAI_HISTORY_CREATED (CREATED_AT)
			)',
			static::getTableName()
		));
	}

	public static function drop(): void
	{
		$connection = Application::getConnection();
		if($connection->isTableExists(static::getTableName()))
		{
			$connection->queryExecute('DROP TABLE '.static::getTableName());
		}
	}

	/**
	 * Последние анализы записи, от свежего к старому.
	 *
	 * @return array<int, array{at: int, risk: int, why: string, action: string, marked: bool, extra: array}>
	 */
	public static function getLast(int $entityTypeId, int $entityId, int $limit): array
	{
		$rows = static::getList([
			'filter' => ['=ENTITY_TYPE_ID' => $entityTypeId, '=ENTITY_ID' => $entityId],
			'order' => ['CREATED_AT' => 'DESC', 'ID' => 'DESC'],
			'limit' => $limit,
		])->fetchAll();

		return array_map(static fn(array $row): array => [
			'at' => $row['CREATED_AT'] instanceof DateTime ? $row['CREATED_AT']->getTimestamp() : 0,
			'risk' => (int)$row['RISK'],
			'why' => (string)($row['WHY'] ?? ''),
			'action' => (string)($row['ACTION'] ?? ''),
			'marked' => ($row['MARKED'] ?? 'N') === 'Y',
			'extra' => is_array($extra = json_decode((string)($row['EXTRA'] ?? ''), true)) ? $extra : [],
		], $rows);
	}

	/** Удалить записи старше $days дней. Зовёт агент анализа раз в прогон. */
	public static function cleanup(int $days): void
	{
		$connection = Application::getConnection();
		if(!$connection->isTableExists(static::getTableName()))
		{
			return;
		}
		$connection->queryExecute(sprintf(
			'DELETE FROM %s WHERE CREATED_AT < %s',
			static::getTableName(),
			$connection->getSqlHelper()->convertToDbDateTime(DateTime::createFromTimestamp(time() - $days * 86400))
		));
	}
}
