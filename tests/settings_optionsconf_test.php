<?php declare(strict_types=1);

/**
 * Страница «Общие»: options_conf.php собирается против API shef.options 3.x.
 *
 * Что держит:
 *
 * * options_conf.php зовёт ShOptionsConfig так, как его понимает shef.options 3.x;
 * * у каждой подписи есть перевод (setName() и setTitle() принимают строку, и
 *   пропущенный ключ — TypeError, то есть неоткрывающаяся страница);
 * * коды опций — те, что читает Config и держат умолчания: переименуете опцию на
 *   странице и забудете в коде — модуль молча читает умолчание;
 * * ссылки: подключение модели и расход — в настройки shef.toolsai, профили — в
 *   свой раздел; в админку ссылок нет;
 * * умолчания — только для читаемых настроек, выключено по умолчанию то, что тратит квоту.
 *
 * API shef.options подменяет tests/stub/options.php, файлы модуля настоящие.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/stub/options.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Localization\Loc;
use Shef\Options\Main\Options;

Check::boot();
Loc::loadLangFile($root.'/lang/ru/options.php');

Check::group('options_conf.php собирается');

$tabs = require $root.'/options_conf.php';
Check::same('вернул список вкладок', is_array($tabs), true);
Check::same('одна вкладка — DEAL', array_map(static fn(Options\Tab $tab): string => $tab->getCode(), $tabs), ['DEAL']);

$names = [];
$codes = [];
$untitled = [];
$descriptions = '';
$infoRows = [];
foreach($tabs as $tab)
{
	$names[] = $tab->getName();
	$names[] = $tab->getTitle();
	foreach($tab->getOptionList() as $option)
	{
		$codes[] = $tab->getCode().'_'.$option->getCode();
		if($option instanceof Options\RowInfo)
		{
			$infoRows[] = $tab->getCode().'_'.$option->getCode();
		}
		$descriptions .= $option->getDescription();
		if(!$option instanceof Options\RowInfo && ($option->getTitle() === '' || $option->getDescription() === ''))
		{
			$untitled[] = $tab->getCode().'_'.$option->getCode();
		}
	}
}
Check::same('у вкладки есть название и заголовок', in_array('', $names, true), false);
Check::same('у каждой опции есть подпись и описание', $untitled, []);
Check::same('переводы подставились, а не ключи', str_contains(implode('', $names).$descriptions, 'shef.toolsaicrm_TAB_'), false);

Check::group('коды опций — те, что читает Config');

$config = (string)file_get_contents($root.'/lib/config.php');
preg_match_all("/'(DEAL_[a-z]+)'/", $config, $match);
$read = array_values(array_unique($match[1]));
$read[] = \Shef\ToolsAiCrm\Main\Constants::OPTION_CRMEVENTS;
$onPage = array_values(array_diff($codes, $infoRows));
sort($onPage);
$read = array_values(array_unique($read));
sort($read);
Check::same('всё, что читает Config (DEAL_*), есть на странице, и наоборот', $onPage, $read);
Check::same('строки-пояснения: модель и расход, профили', $infoRows, ['DEAL_Core', 'DEAL_Profiles']);

Check::group('ссылки');

Check::same('подключение модели — настройки shef.toolsai', str_contains($descriptions, '/shef/toolsai/?section=options'), true);
Check::same('расход и квота — настройки shef.toolsai', str_contains($descriptions, '/shef/toolsai/?section=quota'), true);
Check::same('профили и скрипты — свой раздел', str_contains($descriptions, '/shef/toolsaicrm/?section=profiles'), true);
Check::same('ссылок в админку нет', str_contains($descriptions, '/bitrix/admin/'), false);
Check::same('в «Общих» нет ни ключей, ни цен: нет полей подключения модели', array_values(preg_grep('/apikey|baseurl|price/i', $codes)), []);

Check::group('умолчания');

require $root.'/default_option.php';
// CALLSCRIPT_* читает CallScript\Events (подбор скрипта звонка), его настройки — на странице профилей
$ownPage = ['CALLSCRIPT_mode', 'CALLSCRIPT_fallback'];
Check::same('умолчания — только для читаемых настроек', array_values(array_diff(array_keys($shef_toolsaicrm_default_option), $read, $ownPage)), []);
Check::same('агент выключен, запись в CRM тихая, 50 за прогон, раз в час', array_intersect_key($shef_toolsaicrm_default_option, array_flip($read)), ['DEAL_enabled' => 'N', 'DEAL_crmevents' => 'N', 'DEAL_maxperrun' => '50', 'DEAL_interval' => '60']);
Check::same('умолчания Config и default_option совпадают', [\Shef\ToolsAiCrm\Config::DEFAULT_MAX_PER_RUN, \Shef\ToolsAiCrm\Config::DEFAULT_AGENT_INTERVAL_MINUTES], [50, 60]);

Check::finish();
