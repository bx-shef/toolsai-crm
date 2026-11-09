<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Main;

/**
 * Постоянные модуля: идентификатор, адреса, блокировки, коды переноса из shef.toolsai.
 *
 * Тип поля shef_toolsai_ack, коды UF_SHEF_AI_*, каталог робота
 * shtoolsaianalyzeactivity и коды настроек (DEAL_*, ANALYSIS_*, CALLSCRIPT_*) —
 * как были в shef.toolsai: на них лежат данные порталов и схемы бизнес-процессов.
 */
final class Constants
{
	/** Название страницы и блока настроек (префикс [SH] — модули линейки). */
	public const TITLE = '[SH] Tools AI: автооценка CRM';

	/** Идентификатор модуля. */
	public const MODULE_ID = 'shef.toolsaicrm';

	/** Движок: модель, расход, квота, лицензия, права — модуль shef.toolsai. */
	public const CORE_MODULE_ID = 'shef.toolsai';

	/** Первая версия shef.toolsai с публичным API, который нужен модулю. */
	public const CORE_MIN_VERSION = '1.96.0';

	/** Категория журнала расхода и квоты. */
	public const CATEGORY = 'crm_analysis';

	/** Публичная страница модуля: настройки и отчёты, меню разделов слева. */
	public const PUBLIC_URL = '/shef/toolsaicrm/';
	public const SETTINGS_FILE = '/shef/toolsaicrm/index.php';
	public const SETTINGS_MODULE_PAGE = '/settings/page.php';

	/** Компонент публичной страницы (ajax.php рядом — права и действия разделов). */
	public const COMPONENT_NAME = 'shef:toolsaicrm.settings';
	public const COMPONENT_FILE = '/local/components/shef/toolsaicrm.settings/component.php';
	public const COMPONENT_MODULE_PAGE = '/settings/component.php';
	public const COMPONENT_AJAX_FILE = '/local/components/shef/toolsaicrm.settings/ajax.php';
	public const COMPONENT_AJAX_MODULE_PAGE = '/settings/ajax.php';

	/**
	 * Робот БП «AI: оценить сейчас»: каталог и класс прежние — существующие роботы в схемах
	 * ссылаются на них. Заглушку пишет модуль (Main\PublicPage), как раньше писал shef.toolsai.
	 */
	public const ROBOT_ANALYZE_DIR = '/bitrix/activities/custom/shtoolsaianalyzeactivity';
	public const ROBOT_ANALYZE_MODULE_DIR = '/activities/analyze';

	/**
	 * Блокировка агента анализа типа: префикс + entityTypeId («shef.toolsaicrm.analysis.2» — сделки).
	 * Префикс повторён в install/index.php литералом.
	 */
	public const LOCK_GROUP_ANALYSIS_PREFIX = 'shef.toolsaicrm.analysis.';

	/** Префикс блокировок агента анализа в shef.toolsai: установщик гасит его агенты и ждёт. */
	public const CORE_LOCK_GROUP_ANALYSIS_PREFIX = 'shef.toolsai.analysis.';

	/** Агент анализа типа: имя в b_agent (тип подставляется). Повторено в install/index.php. */
	public const AGENT_ANALYSIS = '\Shef\ToolsAiCrm\Agent\AnalysisAgent::run(%d);';

	/** Отметка переноса данных из shef.toolsai: «время|строки» (установщик пишет, сверка читает). */
	public const OPTION_MIGRATED = 'SYS_migrated_from_toolsai';

	/** Отметка в shef.toolsai: «перенос выполнен», после неё его установщик убирает старое. */
	public const CORE_OPTION_MIGRATED = 'SYS_crm_moved';

	/** Включение записи модуля в CRM с событиями (роботы, БП, история). Своя настройка, как была DEAL_crmevents. */
	public const OPTION_CRMEVENTS = 'DEAL_crmevents';

	/** Страница настроек в административной части. */
	public static function settingsUrl(): string
	{
		return '/bitrix/admin/settings.php?mid='.self::MODULE_ID.'&lang='.(defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru');
	}
}
