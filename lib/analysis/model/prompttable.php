<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Analysis\Model;

use Bitrix\Main\Application;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;

/**
 * Свои промпты модуля (1.9.0). Строка на код; нет строки — стандартный
 * текст из кода. Код — с пространством имён и уровнем (1.9.1,
 * Prompt\PromptLevel): «analysis.deal», «analysis.deal.c5», «analysis.lead»,
 * «analysis.dynamic.1032», «analysis.dynamic.1032.c7»; таблица общая, чтобы на неё же легли
 * свои промпты BitrixGPT (bx-shef/toolsai#27).
 *
 * VERSION растёт на каждое сохранение; CHANGED_AT и CHANGED_BY — кто и
 * когда. «Вернуть стандартный» удаляет строку.
 */
class PromptTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'shef_toolsaicrm_prompt';
	}

	public static function getMap(): array
	{
		return [
			(new Fields\IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new Fields\StringField('CODE'))->configureRequired()->configureSize(100),
			(new Fields\TextField('SYSTEM_TEXT'))->configureNullable(),
			(new Fields\TextField('USER_TEXT'))->configureNullable(),
			(new Fields\StringField('EXTRA_FIELDS'))->configureSize(255)->configureDefaultValue(''),
			(new Fields\IntegerField('VERSION'))->configureDefaultValue(1),
			(new Fields\DatetimeField('CHANGED_AT'))->configureRequired(),
			(new Fields\IntegerField('CHANGED_BY'))->configureDefaultValue(0),
			// Реестр промптов (1.48.0, Prompt\RegistryService): правка стандартного промпта модуля или свой промпт
			(new Fields\StringField('TITLE'))->configureSize(120)->configureNullable(),
			(new Fields\StringField('KIND'))->configureSize(16)->configureNullable(),
			(new Fields\StringField('PARENT_CODE'))->configureSize(100)->configureNullable(),
			(new Fields\StringField('PLACES'))->configureSize(100)->configureNullable(),
			(new Fields\StringField('ENTITY'))->configureSize(40)->configureNullable(),
			(new Fields\TextField('EXAMPLES'))->configureNullable(),
			(new Fields\StringField('ACTIVE'))->configureSize(1)->configureNullable(),
			(new Fields\IntegerField('SORT'))->configureNullable(),
			(new Fields\StringField('MODULE_ID'))->configureSize(50)->configureNullable(),
			(new Fields\StringField('IS_CUSTOM'))->configureSize(1)->configureDefaultValue('N'),
			// Мастер библиотеки (1.54.0): иконка и доступ роли, вид текста, автор
			(new Fields\StringField('ICON'))->configureSize(40)->configureNullable(),
			(new Fields\TextField('ACCESS_CODES'))->configureNullable(),
			(new Fields\StringField('PROMPT_TYPE'))->configureSize(16)->configureNullable(),
			(new Fields\DatetimeField('CREATED_AT'))->configureNullable(),
			(new Fields\IntegerField('CREATED_BY'))->configureNullable(),
			(new Fields\StringField('DESCRIPTION'))->configureSize(255)->configureNullable(),
		];
	}

	public static function init(): void
	{
		$connection = Application::getConnection();
		if($connection->isTableExists(static::getTableName()))
		{
			static::upgrade();

			return;
		}

		$connection->queryExecute(sprintf(
			'CREATE TABLE %s (
				ID INT(11) NOT NULL AUTO_INCREMENT,
				CODE VARCHAR(100) NOT NULL,
				SYSTEM_TEXT TEXT NULL,
				USER_TEXT TEXT NULL,
				EXTRA_FIELDS VARCHAR(255) NOT NULL DEFAULT \'\',
				VERSION INT(11) NOT NULL DEFAULT 1,
				CHANGED_AT DATETIME NOT NULL,
				CHANGED_BY INT(11) NOT NULL DEFAULT 0,
				%s,
				PRIMARY KEY (ID),
				UNIQUE KEY UX_SHEF_TOOLSAI_PROMPT_CODE (CODE)
			)',
			static::getTableName(),
			implode(",\n\t\t\t\t", array_map(static fn(string $name, string $def): string => $name.' '.$def, array_keys(self::REGISTRY_COLUMNS), self::REGISTRY_COLUMNS))
		));
	}

	/** Версия набора столбцов: Settings\Maintenance::ensure() зовёт init() один раз на смену. */
	public const SCHEMA = '1.54.0';
	public const SCHEMA_OPTION = 'prompt_schema';

	/** Столбцы реестра промптов (1.48.0): у таблицы прежней версии — дописываются (upgrade()). */
	public const REGISTRY_COLUMNS = [
		'TITLE' => 'VARCHAR(120) NULL',
		'KIND' => 'VARCHAR(16) NULL',
		'PARENT_CODE' => 'VARCHAR(100) NULL',
		'PLACES' => 'VARCHAR(100) NULL',
		'ENTITY' => 'VARCHAR(40) NULL',
		'EXAMPLES' => 'TEXT NULL',
		'ACTIVE' => 'CHAR(1) NULL',
		'SORT' => 'INT(11) NULL',
		'MODULE_ID' => 'VARCHAR(50) NULL',
		'IS_CUSTOM' => 'CHAR(1) NOT NULL DEFAULT \'N\'',
		// 1.54.0: мастер библиотеки промптов
		'ICON' => 'VARCHAR(40) NULL',
		'ACCESS_CODES' => 'TEXT NULL',
		'PROMPT_TYPE' => 'VARCHAR(16) NULL',
		'CREATED_AT' => 'DATETIME NULL',
		'CREATED_BY' => 'INT(11) NULL',
		'DESCRIPTION' => 'VARCHAR(255) NULL',
	];

	/**
	 * Каких столбцов реестра нет у таблицы — по списку её столбцов (регистр
	 * не важен). Чистая (promptlibrary_test.php).
	 *
	 * @param string[] $existing
	 * @return array<string, string> имя => определение для ALTER TABLE ADD
	 */
	public static function missingColumns(array $existing): array
	{
		$existing = array_map('strtoupper', $existing);

		return array_filter(self::REGISTRY_COLUMNS, static fn(string $name): bool => !in_array($name, $existing, true), ARRAY_FILTER_USE_KEY);
	}

	public static function upgrade(): void
	{
		$connection = Application::getConnection();
		$existing = array_keys((array)$connection->getTableFields(static::getTableName()));
		foreach(static::missingColumns($existing) as $name => $definition)
		{
			$connection->queryExecute(sprintf('ALTER TABLE %s ADD COLUMN %s %s', static::getTableName(), $name, $definition));
		}
	}

	public static function drop(): void
	{
		$connection = Application::getConnection();
		if($connection->isTableExists(static::getTableName()))
		{
			$connection->queryExecute('DROP TABLE '.static::getTableName());
		}
	}

	public static function getByCode(string $code): ?array
	{
		$row = static::getList(['filter' => ['=CODE' => $code], 'limit' => 1])->fetch();

		return is_array($row) ? $row : null;
	}
}
