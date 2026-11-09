<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Deal\Model;

use Bitrix\Main\Application;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;
use Shef\ToolsAiCrm\Deal\Profile;

/**
 * Профили анализа сделок (Deal\Profile): направление, типы клиента, промпт,
 * шкала, старший. Редактируются в разделе настроек «Профили и агенты
 * анализа» (settings/profiles.php).
 *
 * CLIENT_TYPES — коды Deal\ClientType через запятую, пусто — любой клиент.
 * PROMPT пустой — общий промпт HealthAnalyzer::getSystemPrompt().
 *
 * С 1.9.0 профиль — для любой сущности блока анализа: ENTITY_TYPE_ID
 * (2 — сделка, по умолчанию; 1 — лид; смарт-процесс — его entityTypeId),
 * CATEGORY_ID — воронка этой сущности (у лида 0). Профили сделок,
 * созданные раньше, получают 2 — и работают как прежде.
 */
class DealProfileTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'shef_toolsaicrm_deal_profile';
	}

	public static function getMap(): array
	{
		return [
			(new Fields\IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new Fields\StringField('TITLE'))->configureRequired()->configureSize(255),
			(new Fields\BooleanField('IS_ENABLED'))->configureValues('N', 'Y')->configureDefaultValue('Y'),
			(new Fields\IntegerField('SORT'))->configureDefaultValue(Profile::DEFAULT_SORT),
			// Тип сущности (1.9.0): 2 — сделка, 1 — лид, иначе смарт-процесс.
			(new Fields\IntegerField('ENTITY_TYPE_ID'))->configureDefaultValue(2),
			(new Fields\IntegerField('CATEGORY_ID'))->configureDefaultValue(0),
			(new Fields\StringField('CLIENT_TYPES'))->configureSize(50)->configureDefaultValue(''),
			(new Fields\TextField('PROMPT'))->configureNullable(),
			(new Fields\IntegerField('IDLE_DAYS'))->configureDefaultValue(Profile::DEFAULT_IDLE_DAYS),
			(new Fields\IntegerField('REANALYZE_DAYS'))->configureDefaultValue(Profile::DEFAULT_REANALYZE_DAYS),
			(new Fields\IntegerField('LOW_BORDER'))->configureDefaultValue(Profile::DEFAULT_LOW_BORDER),
			(new Fields\IntegerField('HIGH_BORDER'))->configureDefaultValue(Profile::DEFAULT_HIGH_BORDER),
			(new Fields\IntegerField('SENIOR_ID'))->configureDefaultValue(0),
			// Анализировать только живые сделки. С версии 1.4.0.
			(new Fields\IntegerField('ACTIVE_DAYS'))->configureDefaultValue(Profile::DEFAULT_ACTIVE_DAYS),
			// «Тотальный контроль»: старший при риске >= HIGH всегда. С версии 1.8.0.
			(new Fields\BooleanField('TOTAL_CONTROL'))->configureValues('N', 'Y')->configureDefaultValue('N'),
			// Тип клиента лида (1.11.0, Deal\LeadClientType): '' — любой.
			(new Fields\StringField('LEAD_REPEAT'))->configureSize(10)->configureDefaultValue(''),
			(new Fields\StringField('LEAD_LEGAL'))->configureSize(10)->configureDefaultValue(''),
			// «Не передавать переписку модели» (1.67.3): блока «Переписка» в сводке нет.
			(new Fields\BooleanField('NO_CONVERSATION'))->configureValues('N', 'Y')->configureDefaultValue('N'),
			// Стадии и итог (#408): коды стадий через запятую ('' — любая), итог S/F ('' — любой).
			(new Fields\StringField('STAGES'))->configureSize(Profile::STAGES_MAX)->configureDefaultValue(''),
			(new Fields\StringField('STAGE_SEMANTIC'))->configureSize(1)->configureDefaultValue(''),
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
				TITLE VARCHAR(255) NOT NULL,
				IS_ENABLED CHAR(1) NOT NULL DEFAULT \'Y\',
				SORT INT(11) NOT NULL DEFAULT %d,
				ENTITY_TYPE_ID INT(11) NOT NULL DEFAULT 2,
				CATEGORY_ID INT(11) NOT NULL DEFAULT 0,
				CLIENT_TYPES VARCHAR(50) NOT NULL DEFAULT \'\',
				PROMPT TEXT NULL,
				IDLE_DAYS INT(11) NOT NULL DEFAULT %d,
				REANALYZE_DAYS INT(11) NOT NULL DEFAULT %d,
				LOW_BORDER INT(11) NOT NULL DEFAULT %d,
				HIGH_BORDER INT(11) NOT NULL DEFAULT %d,
				SENIOR_ID INT(11) NOT NULL DEFAULT 0,
				ACTIVE_DAYS INT(11) NOT NULL DEFAULT %d,
				TOTAL_CONTROL CHAR(1) NOT NULL DEFAULT \'N\',
				LEAD_REPEAT VARCHAR(10) NOT NULL DEFAULT \'\',
				LEAD_LEGAL VARCHAR(10) NOT NULL DEFAULT \'\',
				NO_CONVERSATION CHAR(1) NOT NULL DEFAULT \'N\',
				STAGES VARCHAR(%d) NOT NULL DEFAULT \'\',
				STAGE_SEMANTIC CHAR(1) NOT NULL DEFAULT \'\',
				PRIMARY KEY (ID),
				KEY IX_SHEF_TOOLSAI_PROFILE_CAT (CATEGORY_ID, IS_ENABLED)
			)',
			static::getTableName(),
			Profile::DEFAULT_SORT,
			Profile::DEFAULT_IDLE_DAYS,
			Profile::DEFAULT_REANALYZE_DAYS,
			Profile::DEFAULT_LOW_BORDER,
			Profile::DEFAULT_HIGH_BORDER,
			Profile::DEFAULT_ACTIVE_DAYS,
			Profile::STAGES_MAX
		));
	}

	/**
	 * Таблица из версии до 1.4.0 — дописать ACTIVE_DAYS, до 1.8.0 —
	 * TOTAL_CONTROL. Идемпотентно.
	 * Существующие профили получают умолчание: ACTIVE_DAYS 60 дней (мёртвые
	 * сделки перестают анализироваться сразу), тотальный контроль выключен
	 * (поведение не меняется).
	 */
	public static function upgrade(): void
	{
		$connection = Application::getConnection();
		$existing = array_change_key_case((array)$connection->getTableFields(static::getTableName()), CASE_UPPER);
		if(!array_key_exists('ACTIVE_DAYS', $existing))
		{
			$connection->queryExecute(sprintf('ALTER TABLE %s ADD COLUMN ACTIVE_DAYS INT(11) NOT NULL DEFAULT %d', static::getTableName(), Profile::DEFAULT_ACTIVE_DAYS));
		}
		if(!array_key_exists('TOTAL_CONTROL', $existing))
		{
			$connection->queryExecute(sprintf("ALTER TABLE %s ADD COLUMN TOTAL_CONTROL CHAR(1) NOT NULL DEFAULT 'N'", static::getTableName()));
		}
		// 1.9.0: тип сущности. Существующие профили — сделки (2), как и были.
		if(!array_key_exists('ENTITY_TYPE_ID', $existing))
		{
			$connection->queryExecute(sprintf('ALTER TABLE %s ADD COLUMN ENTITY_TYPE_ID INT(11) NOT NULL DEFAULT 2', static::getTableName()));
		}
		// 1.11.0: тип клиента лида. Существующие профили — «любой», как и были.
		foreach(['LEAD_REPEAT', 'LEAD_LEGAL'] as $column)
		{
			if(!array_key_exists($column, $existing))
			{
				$connection->queryExecute(sprintf("ALTER TABLE %s ADD COLUMN %s VARCHAR(10) NOT NULL DEFAULT ''", static::getTableName(), $column));
			}
		}
		// 1.67.3: «Не передавать переписку модели». Существующие профили — передают, как и были.
		if(!array_key_exists('NO_CONVERSATION', $existing))
		{
			$connection->queryExecute(sprintf("ALTER TABLE %s ADD COLUMN NO_CONVERSATION CHAR(1) NOT NULL DEFAULT 'N'", static::getTableName()));
		}
		// #408: стадии и итог. Существующие профили — любая стадия, как и были.
		if(!array_key_exists('STAGES', $existing))
		{
			$connection->queryExecute(sprintf("ALTER TABLE %s ADD COLUMN STAGES VARCHAR(%d) NOT NULL DEFAULT ''", static::getTableName(), Profile::STAGES_MAX));
		}
		if(!array_key_exists('STAGE_SEMANTIC', $existing))
		{
			$connection->queryExecute(sprintf("ALTER TABLE %s ADD COLUMN STAGE_SEMANTIC CHAR(1) NOT NULL DEFAULT ''", static::getTableName()));
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

	/**
	 * Все профили, разобранные.
	 *
	 * @param int|null $entityTypeId только профили этого типа сущности; null — все
	 * @return Profile[]
	 */
	public static function getProfiles(bool $onlyEnabled = false, ?int $entityTypeId = null): array
	{
		$filter = $onlyEnabled ? ['=IS_ENABLED' => 'Y'] : [];
		if($entityTypeId !== null)
		{
			$filter['=ENTITY_TYPE_ID'] = $entityTypeId;
		}
		$rows = static::getList([
			'filter' => $filter,
			'order' => ['ENTITY_TYPE_ID' => 'ASC', 'CATEGORY_ID' => 'ASC', 'SORT' => 'ASC', 'ID' => 'ASC'],
		])->fetchAll();

		return array_map(static fn(array $row): Profile => Profile::fromRow($row), $rows);
	}
}
