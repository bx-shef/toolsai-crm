<?php
/**
 * Страница модуля в публичной части: /shef/toolsaicrm/.
 *
 * Открывается заглушкой /shef/toolsaicrm/index.php (Main\PublicPage) — её
 * модуль пишет сам. Устроена как штатные страницы настроек CRM
 * (/crm/configs/sms/index.php): шапка портала, компонент в обёртке
 * bitrix:ui.sidepanel.wrapper, подвал. Только в слайдере (IFRAME=Y): без шапки
 * портала, с меню разделов слева. Права и всё остальное — в компоненте
 * shef:toolsaicrm.settings (settings/component.php).
 */

/** @global \CMain $APPLICATION */
require($_SERVER['DOCUMENT_ROOT'].'/bitrix/header.php');

$APPLICATION->SetTitle('AI: автоанализ CRM');

// Отчёты — с верхней панелью: в ней штатный фильтр периода; настройкам панель не нужна.
// Без движка (shef.toolsai) классов раздела нет — тогда панель тоже не нужна.
$withToolbar = \Bitrix\Main\Loader::includeModule('shef.toolsaicrm')
	&& \Shef\ToolsAiCrm\Settings\Sections::isReport(\Shef\ToolsAiCrm\Settings\Sections::resolve($_GET['section'] ?? ''));

$APPLICATION->IncludeComponent(
	'bitrix:ui.sidepanel.wrapper',
	'',
	[
		'POPUP_COMPONENT_NAME' => 'shef:toolsaicrm.settings',
		'POPUP_COMPONENT_TEMPLATE_NAME' => '',
		'POPUP_COMPONENT_PARAMS' => [],
		'USE_PADDING' => true,
		'USE_UI_TOOLBAR' => $withToolbar ? 'Y' : 'N',
		// Только в слайдере, как настройки портала: прямая ссылка открывает портал и
		// поверх — слайдер; вне слайдера страница уводит на себя же в слайдере.
		'PREVENT_LOADING_WITHOUT_IFRAME' => true,
		'PAGE_MODE' => false,
	]
);

require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
