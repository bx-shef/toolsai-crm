<?php declare(strict_types=1);

/**
 * Раздел «Общие»: настройки агента анализа (включение, лимит за прогон, интервал,
 * запись в CRM с событиями) и ссылки на подключение модели и расход в shef.toolsai.
 *
 * Список настроек собирает options_conf.php через API shef.options, поля рисуют
 * сами опции shef.options (Ui\SettingsForm), сохранение — тот же цикл, что в
 * каноническом options.php линейки. Модель, ключи, расход и квота остаются в
 * shef.toolsai: здесь их нет.
 *
 * Права проверяет компонент (settings/component.php): только администратор
 * портала. Запись — POST с check_bitrix_sessid(). После записи агенты анализа
 * сверяются сразу: новый интервал начинает действовать без отдельной кнопки.
 */

use Bitrix\Main\Application;
use Bitrix\Main\Config;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\UI\Extension;
use Shef\Options\Main\Options;
use Shef\ToolsAiCrm\Analysis\Candidates;
use Shef\ToolsAiCrm\Container;
use Shef\ToolsAiCrm\Deal\Model\DealProfileTable;
use Shef\ToolsAiCrm\Main\Constants;
use Shef\ToolsAiCrm\Settings\AnalysisAgents;
use Shef\ToolsAiCrm\Settings\Faq;
use Shef\ToolsAiCrm\Settings\KbLink;
use Shef\ToolsAiCrm\Settings\OptionsInput;
use Shef\ToolsAiCrm\Ui\SettingsForm;

$mid = Constants::MODULE_ID;

if(!Loader::includeModule('shef.options'))
{
	$alert('Нужен модуль shef.options');
	return;
}

// shef.options может стоять и в /local/modules — путь у Loader, а не зашитый.
// getLocal() отдаёт уже полный путь с корнем сайта: корень второй раз не приписываем.
$configLocal = Loader::getLocal('modules/shef.options/optionsconfig.php');
$configClass = $configLocal !== false ? (string)$configLocal : '';
if($configClass === '' || !is_file($configClass))
{
	$alert('Не найден файл modules/shef.options/optionsconfig.php (ни в /local, ни в /bitrix)');
	return;
}
Loc::loadMessages($configClass);
require_once $configClass;

// Подписи настроек — языковой файл страницы модуля (lang/ru/options.php).
Loc::loadMessages($moduleDir.'/options.php');

// options_conf.php читает $self: ссылки ведут в слайдер, если страница открыта в нём.
$tabs = require $moduleDir.'/options_conf.php';
if($tabs instanceof Result)
{
	$alert('Настройки не собрались', 'danger', implode('<br>', array_map($h, $tabs->getErrorMessages())));
	return;
}
if(!is_array($tabs))
{
	$alert('Неверный список вкладок в options_conf.php');
	return;
}

$request = Application::getInstance()->getContext()->getRequest();
$pageTabs = array_filter($tabs, static fn($tab): bool => $tab instanceof Options\Tab);
if($request->isPost() && check_bitrix_sessid() && (string)$request->getPost('Update') !== '')
{
	// Только пришедшие поля: форма без JS ничего не затирает
	$values = SettingsForm::collect($pageTabs, $request->getPostList()->toArray());
	$invalid = OptionsInput::validate($values);
	if($invalid !== [])
	{
		$alert('Настройки не сохранены', 'danger', implode('<br>', array_map($h, array_values($invalid))));
	}
	elseif($values !== [])
	{
		foreach($values as $code => $value)
		{
			Config\Option::set($mid, $code, $value);
		}
		// Агенты анализа — по включённым профилям, с новым интервалом
		try
		{
			AnalysisAgents::ensure(Container::getConfig(), array_keys(Candidates::groupByType(DealProfileTable::getProfiles(true))));
		}
		catch(\Throwable $throwable)
		{
			Container::getLogger(\Shef\ToolsAiCrm\Config::class)?->warning('Агенты анализа не сверились после сохранения настроек: '.$throwable->getMessage());
		}
		LocalRedirect($self.'&saved=Y');
	}
	else
	{
		$alert('Форма не загрузилась полностью — ничего не сохранено. Обновите страницу и повторите.', 'danger');
	}
}

if($request->getQuery('saved') === 'Y')
{
	$alert('Настройки сохранены', 'success');
}
?>
<div class="shef-tai-section">
<p class="shef-tai-page-desc"><?=$h('Главные настройки автоанализа CRM. У каждой настройки значок «?» — коротко, что это и зачем.')?> <?=$help('section.options')?><?php if(!KbLink::isAvailable()): ?> <?=$h(KbLink::INSTALL_HINT.'.')?><?php endif; ?></p>

<?php
// Поля — штатные ui.form-elements, как в настройках портала.
Extension::load(SettingsForm::extensions());
$formSections = SettingsForm::fromTabs(
	$pageTabs,
	static fn(string $code): string => (string)Config\Option::get($mid, $code, ''),
	static fn(string $code): string => (string)(Faq::get('option.'.$code)['what'] ?? ''),
	// «?» у поля — та же ссылка на ответ, что у описания раздела
	static fn(string $code): string => Faq::icon('option.'.$code)
);
$formSections = SettingsForm::withMeta($formSections, [
	'DEAL' => ['--o-crm', Faq::kbUrl('/guide/deal-analysis')],
]);
?>
<style><?=SettingsForm::style()?></style>
<form method="post" action="<?=$h($self)?>" name="shef_toolsaicrm_options" id="shef_toolsaicrm_options">
	<?=bitrix_sessid_post()?>
	<input type="hidden" name="Update" value="Y" id="shef_toolsaicrm_options_update">
	<input type="hidden" name="<?=SettingsForm::RENDERED?>" value="" id="shef_toolsaicrm_options_rendered">
	<div id="shef-tai-options-form"></div>
</form>
<script><?=SettingsForm::script()?></script>
<script>
BX.ready(function()
{
	window.ShefUiSettingsForm && ShefUiSettingsForm.render(document.getElementById('shef-tai-options-form'), <?=json_encode($formSections, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>) && (document.getElementById('shef_toolsaicrm_options_rendered').value = 'Y');
});
</script>
<?php
$APPLICATION->IncludeComponent('bitrix:ui.button.panel', '', [
	'BUTTONS' => [
		['type' => 'custom', 'layout' => '<a href="'.$h($self).'" class="ui-btn ui-btn-lg ui-btn-no-caps --air --style-plain">Отмена</a>'],
		['type' => 'custom', 'layout' => '<button type="submit" form="shef_toolsaicrm_options" class="ui-btn ui-btn-lg ui-btn-no-caps --air --style-filled">Сохранить</button>'],
	],
]);
?>
</div>
