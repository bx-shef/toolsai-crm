<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Analysis\Model;

use Bitrix\Main\Application;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;

/**
 * Последняя проверка записи — сделки, лида, элемента смарт-процесса.
 * С 1.9.1 одна таблица на все типы: проверки сделок из прежней
 * shef_toolsai_deal_check переносит migrateDealChecks() (ENTITY_TYPE_ID = 2),
 * после чего старая таблица удаляется.
 *
 * Одна строка на (ENTITY_TYPE_ID, ENTITY_ID): уникальный индекс держит
 * «без двойной обработки» и при гонке двух прогонов.
 */
class EntityCheckTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'shef_toolsaicrm_entity_check';
	}

	public static function getMap(): array
	{
		return [
			(new Fields\IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new Fields\IntegerField('ENTITY_TYPE_ID'))->configureRequired(),
			(new Fields\IntegerField('ENTITY_ID'))->configureRequired(),
			(new Fields\DatetimeField('CHECKED_AT'))->configureRequired(),
			(new Fields\IntegerField('RISK'))->configureDefaultValue(0),
			(new Fields\BooleanField('NEED_SENIOR'))->configureValues('N', 'Y')->configureDefaultValue('N'),
			(new Fields\BooleanField('SKIPPED'))->configureValues('N', 'Y')->configureDefaultValue('N'),
			(new Fields\StringField('WHY'))->configureSize(500)->configureNullable(),
			(new Fields\StringField('NEXT_STEP'))->configureSize(300)->configureNullable(),
			(new Fields\DatetimeField('ESCALATED_AT'))->configureNullable(),
			(new Fields\DatetimeField('MANAGER_TODO_AT'))->configureNullable(),
			(new Fields\IntegerField('PROFILE_ID'))->configureDefaultValue(0),
			(new Fields\DatetimeField('OVERDUE_NOTIFIED_AT'))->configureNullable(),
			(new Fields\DatetimeField('NOTE_COMMENTED_AT'))->configureNullable(),
			(new Fields\StringField('ACTION'))->configureSize(500)->configureNullable(),
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
				CHECKED_AT DATETIME NOT NULL,
				RISK INT(11) NOT NULL DEFAULT 0,
				NEED_SENIOR CHAR(1) NOT NULL DEFAULT \'N\',
				SKIPPED CHAR(1) NOT NULL DEFAULT \'N\',
				WHY VARCHAR(500) NULL,
				NEXT_STEP VARCHAR(300) NULL,
				ESCALATED_AT DATETIME NULL,
				MANAGER_TODO_AT DATETIME NULL,
				PROFILE_ID INT(11) NOT NULL DEFAULT 0,
				OVERDUE_NOTIFIED_AT DATETIME NULL,
				NOTE_COMMENTED_AT DATETIME NULL,
				ACTION VARCHAR(500) NULL,
				PRIMARY KEY (ID),
				UNIQUE KEY UX_SHEF_TOOLSAI_ENTITY (ENTITY_TYPE_ID, ENTITY_ID),
				KEY IX_SHEF_TOOLSAI_ENTITY_CHECKED (CHECKED_AT)
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

	public static function getByEntity(int $entityTypeId, int $entityId): ?array
	{
		$row = static::getList([
			'filter' => ['=ENTITY_TYPE_ID' => $entityTypeId, '=ENTITY_ID' => $entityId],
			'limit' => 1,
		])->fetch();

		return is_array($row) ? $row : null;
	}

	public static function save(int $entityTypeId, int $entityId, array $fields): void
	{
		$row = static::getByEntity($entityTypeId, $entityId);
		if($row === null)
		{
			static::add(['ENTITY_TYPE_ID' => $entityTypeId, 'ENTITY_ID' => $entityId] + $fields);

			return;
		}

		static::update((int)$row['ID'], $fields);
	}
}
