<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\CallScript\Model;

use Bitrix\Main\Application;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;
use Shef\ToolsAiCrm\CallScript\Rule;
use Shef\ToolsAiCrm\Deal\Profile;

/**
 * Правила скриптов оценки звонков (#408, CallScript\Rule): тип записи,
 * воронка (-1 — любая), стадии или итог, ID скрипта CRM.
 */
class CallScriptRuleTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'shef_toolsaicrm_call_script_rule';
	}

	public static function getMap(): array
	{
		return [
			(new Fields\IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new Fields\BooleanField('IS_ENABLED'))->configureValues('N', 'Y')->configureDefaultValue('Y'),
			(new Fields\IntegerField('SORT'))->configureDefaultValue(Rule::DEFAULT_SORT),
			(new Fields\IntegerField('ENTITY_TYPE_ID'))->configureDefaultValue(2),
			(new Fields\IntegerField('CATEGORY_ID'))->configureDefaultValue(Rule::ANY_CATEGORY),
			(new Fields\StringField('STAGES'))->configureSize(Profile::STAGES_MAX)->configureDefaultValue(''),
			(new Fields\StringField('STAGE_SEMANTIC'))->configureSize(1)->configureDefaultValue(''),
			(new Fields\IntegerField('ASSESSMENT_ID'))->configureDefaultValue(0),
		];
	}

	/** Таблица, если её нет. Идемпотентно. */
	public static function init(): void
	{
		$connection = Application::getConnection();
		if($connection->isTableExists(static::getTableName()))
		{
			return;
		}
		$connection->queryExecute(sprintf(
			"CREATE TABLE %s (
				ID INT(11) NOT NULL AUTO_INCREMENT,
				IS_ENABLED CHAR(1) NOT NULL DEFAULT 'Y',
				SORT INT(11) NOT NULL DEFAULT %d,
				ENTITY_TYPE_ID INT(11) NOT NULL DEFAULT 2,
				CATEGORY_ID INT(11) NOT NULL DEFAULT -1,
				STAGES VARCHAR(%d) NOT NULL DEFAULT '',
				STAGE_SEMANTIC CHAR(1) NOT NULL DEFAULT '',
				ASSESSMENT_ID INT(11) NOT NULL DEFAULT 0,
				PRIMARY KEY (ID),
				KEY IX_SHEF_TOOLSAI_CSR_TYPE (ENTITY_TYPE_ID, IS_ENABLED)
			)",
			static::getTableName(),
			Rule::DEFAULT_SORT,
			Profile::STAGES_MAX
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
	 * @return Rule[]
	 */
	public static function getRules(bool $onlyEnabled = false): array
	{
		$rows = static::getList([
			'filter' => $onlyEnabled ? ['=IS_ENABLED' => 'Y'] : [],
			'order' => ['ENTITY_TYPE_ID' => 'ASC', 'CATEGORY_ID' => 'ASC', 'SORT' => 'ASC', 'ID' => 'ASC'],
		])->fetchAll();

		return array_map(static fn(array $row): Rule => Rule::fromRow($row), $rows);
	}
}
