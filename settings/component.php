<?php declare(strict_types=1);

/**
 * Компонент shef:toolsaicrm.settings — страница модуля в публичной части
 * (/shef/toolsaicrm/): настройки и отчёты автоанализа CRM.
 *
 * Ядро ищет компонент в /local/components/shef/toolsaicrm.settings/: там
 * заглушка component.php в одну строку (Main\PublicPage), она подключает
 * этот файл. Ядро включает component.php внутри CBitrixComponent с
 * $arParams, $arResult и глобальными $APPLICATION, $USER — require из
 * заглушки их сохраняет. Шаблона нет: вывод здесь же.
 *
 * Что делает:
 *   - проверяет движок: модуль shef.toolsai должен стоять и быть не старше
 *     Constants::CORE_MIN_VERSION, иначе — внятное сообщение;
 *   - права (Settings\SectionAccess): администратор — всё; остальным — разделы по
 *     правам shef.toolsai (prompts.read, reports.all); нет права — карточка
 *     «Нет доступа»;
 *   - раздел из ?section= (Settings\Sections), меню разделов слева —
 *     штатный bitrix:ui.sidepanel.wrappermenu;
 *   - даёт разделу оформление: адрес ($self), экранирование ($h),
 *     сообщения ($alert, ui-alert), значок «?» ($help, Settings\Faq).
 *
 * Запись в разделах — POST с check_bitrix_sessid().
 *
 * @var \CBitrixComponent $this
 * @var array $arParams
 * @global \CMain $APPLICATION
 * @global \CUser $USER
 */

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\UI\Extension;
use Shef\ToolsAiCrm\Container;
use Shef\ToolsAiCrm\Main\Constants;
use Shef\ToolsAiCrm\Settings\BuildInfo;
use Shef\ToolsAiCrm\Settings\CoreCheck;
use Shef\ToolsAiCrm\Settings\Faq;
use Shef\ToolsAiCrm\Settings\SectionAccess;
use Shef\ToolsAiCrm\Settings\Sections;

defined('B_PROLOG_INCLUDED') && B_PROLOG_INCLUDED === true || die();

global $APPLICATION, $USER;

$moduleDir = dirname(__DIR__);

if(!Loader::includeModule(Constants::MODULE_ID))
{
	ShowError('Модуль '.Constants::MODULE_ID.' не подключился: проверьте его в списке модулей.');
	return;
}

// Движок: shef.toolsai нужной версии. Без него права, модель и расход недоступны.
$coreProblem = CoreCheck::check();
if($coreProblem !== null)
{
	ShowError($coreProblem);
	return;
}

// Карточка «Нет доступа» в стиле ядра (bitrix:ui.info.error)
$infoError = static function(string $title, string $description) use ($APPLICATION): void
{
	if(Loader::includeModule('ui') && $APPLICATION instanceof \CMain)
	{
		$APPLICATION->IncludeComponent('bitrix:ui.info.error', '', ['TITLE' => $title, 'DESCRIPTION' => $description]);

		return;
	}
	ShowError($title);
};
$noAccessTitle = 'Нет доступа';
$noAccessText = 'Обратитесь к администратору портала: права на разделы выдаются на странице прав модуля shef.toolsai.';

$rights = SectionAccess::current();
if(!$rights->hasAny())
{
	$infoError($noAccessTitle, $noAccessText);
	return;
}

if(!Loader::includeModule('crm'))
{
	ShowError('Для автоанализа CRM нужен модуль CRM.');
	return;
}

$request = Application::getInstance()->getContext()->getRequest();
$requested = $request->getQuery('section') ?? $request->getPost('section');
// Без раздела в адресе — первый доступный; раздел, на который нет права, — карточка
$section = $requested === null || $requested === '' ? ($rights->getDefaultSection() ?? Sections::DEFAULT) : Sections::resolve($requested);
$allowed = $rights->canSection($section);
// Только слайдер: страница вне слайдера сюда не доходит (settings/page.php,
// PREVENT_LOADING_WITHOUT_IFRAME), ссылки разделов — всегда с IFRAME=Y.
$iframe = true;
$sections = Sections::getList();

Extension::load(['ui.buttons', 'ui.alerts', 'ui.forms', 'ui.fonts.opensans', 'main.loader', 'ui.icon-set.outline', 'ui.notification', 'ui.dialogs.messagebox']);
// Заголовок слайдера — название раздела: название модуля и так стоит над меню слева
$APPLICATION->SetTitle($sections[$section]['title']);

// region Меню разделов ////
$menuItems = [];
foreach($sections as $code => $item)
{
	if(!$rights->canSection($code))
	{
		continue;
	}
	$menuItems[] = [
		'NAME' => $item['title'],
		'ACTIVE' => $code === $section,
		'ATTRIBUTES' => ['href' => Sections::url($code, [], $iframe)],
	];
}
$APPLICATION->IncludeComponent(
	'bitrix:ui.sidepanel.wrappermenu',
	'',
	['TITLE' => 'AI: автоанализ CRM', 'ITEMS' => $menuItems],
	$this
);
// Версия и сборка под меню: штатная область left-panel-after обёртки слайдера
$APPLICATION->AddViewContent('left-panel-after', BuildInfo::html(BuildInfo::current()));
// endregion ////

$h = static fn(mixed $value): string => htmlspecialcharsbx((string)$value);
$sectionUrl = static fn(string $code): string => Sections::url($code, [], $iframe);
$help = static fn(string $key): string => Faq::icon($key);
$alert = static function(string $message, string $type = 'danger', string $detailsHtml = '') use ($h): void
{
	$class = match($type)
	{
		'success' => 'ui-alert-success',
		'warning' => 'ui-alert-warning',
		'info' => 'ui-alert-primary',
		default => 'ui-alert-danger',
	};
	echo '<div class="ui-alert '.$class.'"><span class="ui-alert-message"><b>'.$h($message).'</b>'
		.($detailsHtml !== '' ? '<br>'.$detailsHtml : '').'</span></div>';
};
$hidden = '';
foreach(Sections::hiddenFields($section, $iframe) as $name => $value)
{
	$hidden .= '<input type="hidden" name="'.$h($name).'" value="'.$h($value).'">';
}
// Скрипт в try/catch: ошибка нашего кода уходит в консоль и не обрывает скрипты ядра
$wrapScript = static fn(string $js): string => '<script>try{'.$js."\n}catch(e){if(window.console){console.error('shef.toolsaicrm:',e);}}</script>";
?>
<style>
	.shef-tai { font-size: 14px; max-width: 1200px; }
	.shef-tai h3 { margin: 22px 0 8px; font-size: 16px; }
	.shef-tai-table { border-collapse: collapse; margin: 6px 0 12px; }
	.shef-tai-table td { border: 1px solid #e0e3e7; padding: 6px 10px; vertical-align: top; }
	.shef-tai-table-header td { background: #f5f7f8; font-weight: bold; }
	.shef-tai-form { border-collapse: collapse; width: 100%; max-width: 1000px; }
	.shef-tai-form td { padding: 8px 10px; vertical-align: top; border-bottom: 1px solid #eef0f2; }
	.shef-tai-l { width: 40%; }
	.shef-tai-q-cell { width: 24px; }
	.shef-tai-q { display: inline-block; width: 18px; height: 18px; line-height: 18px; border-radius: 50%;
		background: #e5f0fa; color: #2067b0; text-align: center; font-size: 12px; font-weight: bold;
		text-decoration: none; vertical-align: middle; }
	.shef-tai-q:hover { background: #2067b0; color: #fff; }
	.shef-tai-buttons { margin: 16px 0; display: flex; gap: 8px; flex-wrap: wrap; }
	.shef-tai textarea { max-width: 100%; }
	/* Переход по левому меню (settings/navloader.js): старый раздел затемнён, по центру загрузчик */
	.shef-tai { position: relative; }
	.shef-tai.--nav-loading > :not(.shef-tai-nav-cover) { opacity: .25; pointer-events: none; transition: opacity .15s; }
	.shef-tai-nav-cover { position: absolute; inset: 0; min-height: 240px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; z-index: 10; }
	.shef-tai-nav-cover[hidden] { display: none; }
	.shef-tai-nav-error { margin: 0; color: var(--ui-color-base-90, #525c69); text-align: center; max-width: 420px; }
</style>
<?php if($allowed && Sections::hasCards($section)): ?>
<style>
	/* Как страница настроек портала (ui.form-elements): заголовок и описание — на
	   сером фоне, каждый блок — своей белой карточкой */
	#workarea-content { background-color: transparent !important; }
	/* Левый край описания и карточек — по заголовку слайдера (без отступа белой подложки) */
	#workarea-content.ui-page-slider-workarea-content-padding { padding-left: 0; padding-right: 0; }
	/* Иконки ui.icon-set.outline в строках и кнопках. */
	.shef-tai-ic { --ui-icon-set__icon-size: 16px; --ui-icon-set__icon-color: currentColor; vertical-align: -3px; }
	.shef-tai-ic.--ok { --ui-icon-set__icon-color: #1fa37a; } .shef-tai-ic.--warn { --ui-icon-set__icon-color: #e89b06; } .shef-tai-ic.--err { --ui-icon-set__icon-color: #e0453a; }
	.shef-tai-page-desc { margin: 0 0 22px 0; font-size: var(--ui-font-size-lg, 15px); color: var(--ui-color-base-90, #525c69); }
	.shef-tai-card { background: var(--ui-color-palette-white-base, #fff); border-radius: var(--ui-border-radius-2xl, 18px); padding: 18px 20px 20px; margin-bottom: 16px; overflow-x: auto; box-shadow: 0 2px 4px 0 rgba(0, 0, 0, .02), 0 5px 16px 0 rgba(0, 0, 0, .03); }
	.shef-tai-card > h3:first-child { margin: 0 0 14px; padding-bottom: 10px; border-bottom: 1px solid var(--ui-color-base-7, #edeef0); font-size: var(--ui-font-size-xl, 17px); font-weight: var(--ui-font-weight-medium, 500); line-height: 23px; color: var(--ui-color-palette-black-base, #151515); }
	.shef-tai-card > :last-child { margin-bottom: 0; }
	.shef-tai-card p { color: var(--ui-color-palette-gray-90, #525c69); line-height: 19px; }
	/* Таблицы — светлые рамки, серая шапка */
	.shef-tai-card .shef-tai-table { border-collapse: separate; border-spacing: 0; border: 1px solid var(--ui-color-base-7, #edeef0); border-radius: 10px; overflow: hidden; }
	.shef-tai-card .shef-tai-table td { border: 0; border-bottom: 1px solid var(--ui-color-base-7, #edeef0); padding: 9px 12px; }
	.shef-tai-card .shef-tai-table tr:last-child td { border-bottom: 0; }
	.shef-tai-card .shef-tai-table-header td { background: var(--ui-color-bg-content-secondary, #f6f7f8); font-weight: var(--ui-font-weight-medium, 500); color: var(--ui-color-palette-gray-90, #525c69); }
	/* Поля без обёртки ui-ctl — по высоте и рамке как штатные */
	.shef-tai-card input[type=text]:not(.ui-ctl-element), .shef-tai-card input[type=number]:not(.ui-ctl-element), .shef-tai-card select:not(.ui-ctl-element) { height: 34px; padding: 0 10px; border: 1px solid var(--ui-color-palette-gray-20, #dfe0e3); border-radius: var(--ui-border-radius-sm, 8px); background: #fff; font: inherit; box-sizing: border-box; }
	.shef-tai-card textarea:not(.ui-ctl-element) { padding: 8px 10px; border: 1px solid var(--ui-color-palette-gray-20, #dfe0e3); border-radius: var(--ui-border-radius-sm, 8px); font: inherit; }
</style>
<?php endif; ?>
<div class="shef-tai">
<?php
// Истекла сессия (или форма открыта давно): разделы пишут только при check_bitrix_sessid(),
// и POST без неё молча пропадал — администратор не понимал, почему правка не сохранилась
if($request->isPost() && !check_bitrix_sessid())
{
	$alert('Сессия истекла — изменения не сохранены. Введённое осталось в форме, где это возможно: нажмите кнопку ещё раз.', 'warning');
}
// Лицензия shef.toolsai: не действует — анализ стоит, настройки и отчёты остаются
if($rights->isAdmin)
{
	try
	{
		$licenseReason = Container::licenseReason();
		if($licenseReason !== '')
		{
			$alert($licenseReason, 'warning', '<a href="'.$h(Sections::coreUrl('license', $iframe)).'">Лицензия в настройках shef.toolsai</a>');
		}
	}
	catch(\Throwable)
	{
	}
}
if(!$allowed)
{
	$infoError($noAccessTitle, 'Раздел «'.$sections[$section]['title'].'» вам недоступен. '.$noAccessText);
}
// Раздел — в своей области видимости: получает только то, что ему дали.
else (static function(string $__file, array $__vars): void
{
	global $APPLICATION, $USER;
	extract($__vars, EXTR_SKIP);
	include $__file;
})($moduleDir.$sections[$section]['file'], [
	'self' => Sections::url($section, [], $iframe),
	'section' => $section,
	'formPath' => Constants::PUBLIC_URL,
	'hidden' => $hidden,
	'h' => $h,
	'alert' => $alert,
	'help' => $help,
	'sectionUrl' => $sectionUrl,
	'moduleDir' => $moduleDir,
	'iframe' => $iframe,
	'request' => $request,
	// Права: раздел сам решает, что показать и что принять
	'rights' => $rights,
	'isAdmin' => $rights->isAdmin,
	'canEditPrompts' => $rights->canEditPrompts(),
]);
?>
</div>
<?= $wrapScript((string)file_get_contents(__DIR__.'/navloader.js')) ?>
<script>
// Кнопки разделов — штатный вид Air без капса: основная — залитая, с рамкой — контурная,
// ссылка — простая. Классы кнопок в разделах — старые ui-btn.
(function()
{
	var air = function(root)
	{
		(root || document).querySelectorAll('.shef-tai .ui-btn:not(.--air)').forEach(function(btn)
		{
			var c = btn.classList;
			var style = c.contains('ui-btn-link') ? '--style-plain' : (c.contains('ui-btn-primary') || c.contains('ui-btn-success') ? '--style-filled' : '--style-outline');
			c.add('--air', 'ui-btn-no-caps', style);
			if(c.contains('ui-btn-danger') || c.contains('ui-btn-danger-light')) { c.add('--style-outline-accent-1'); }
		});
	};
	document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', function() { air(); }) : air();
	window.ShefToolsAiButtons = air;
})();
</script>
<script><?php readfile(__DIR__.'/kbslider.js'); readfile(__DIR__.'/confirm.js'); ?></script>
<script>
// «?» и «Подробнее» — статья справки «Базы знаний 2.0» (Settings\KbLink) в узком
// слайдере поверх портала, с прокруткой к якорю (settings/kbslider.js).
// Ctrl/Cmd/Shift и средняя кнопка — как у браузера
document.addEventListener('click', function(event)
{
	var link = event.target.closest ? event.target.closest('a[data-kb]') : null;
	var bx = window.BX;
	try { bx = top.BX && top.BX.SidePanel ? top.BX : bx; } catch(e) {}
	if(!link || !bx || !bx.SidePanel || !window.ShefKbSlider || !window.ShefKbSlider.isPlainClick(event))
	{
		return;
	}
	event.preventDefault();
	window.ShefKbSlider.open(bx, link.getAttribute('data-kb'));
});
// Карточка записи CRM (a.shef-tai-crm-link) — штатным слайдером CRM поверх
// портала, а не внутри слайдера настроек
document.addEventListener('click', function(event)
{
	var link = event.target.closest ? event.target.closest('a.shef-tai-crm-link') : null;
	var bx = window.BX;
	try { bx = top.BX && top.BX.SidePanel ? top.BX : bx; } catch(e) {}
	if(!link || !bx || !bx.SidePanel || event.defaultPrevented || event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0)
	{
		return;
	}
	event.preventDefault();
	bx.SidePanel.Instance.open(link.getAttribute('href'));
});
</script>
