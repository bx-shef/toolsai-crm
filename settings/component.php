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
use Shef\ToolsAiCrm\Ui\Tai;

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
<style><?= Tai::style() ?></style>
<style>
	/* Переход по левому меню (settings/navloader.js): старый раздел затемнён, по центру загрузчик */
	.shef-tai { position: relative; }
	.shef-tai.--nav-loading > :not(.shef-tai-nav-cover) { opacity: .25; pointer-events: none; transition: opacity .15s; }
	.shef-tai-nav-cover { position: absolute; inset: 0; min-height: 240px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; z-index: 10; }
	.shef-tai-nav-cover[hidden] { display: none; }
</style>
<?php if($allowed && Sections::hasCards($section)): ?>
<style>
	/* Как страница настроек портала (ui.form-elements): заголовок и описание — на
	   сером фоне, каждый блок — своей карточкой (вид карточки — tai.css) */
	#workarea-content { background-color: transparent !important; }
	/* Левый край описания и карточек — по заголовку слайдера (без отступа белой подложки) */
	#workarea-content.ui-page-slider-workarea-content-padding { padding-left: 0; padding-right: 0; }
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
<script><?= Tai::hintScript() ?></script>
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
