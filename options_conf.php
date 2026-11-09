<?php declare(strict_types=1);

use Bitrix\Main\Localization\Loc;
use Shef\Options\Main\Options;
use Shef\ToolsAiCrm\Settings\Sections;

/**
 * Опции для страницы настроек (страницу рисует settings/options.php и options.php
 * из shef.options).
 *
 * языковой файл lang/ru/options.php
 *
 * Tab(prefix)->Option(code) ~> код свойства: prefix_code. Вкладка DEAL даёт
 * DEAL_enabled, DEAL_maxperrun, DEAL_interval, DEAL_crmevents — те же коды, что
 * были в shef.toolsai (значения перенесены установщиком). Свои настройки агента
 * по типам записей (ANALYSIS_interval_<тип>, ANALYSIS_maxperrun_<тип>) правятся
 * на странице «Авто-анализ CRM», скрипты звонков (CALLSCRIPT_*) — там же.
 */

$response = ShOptionsConfig::getInstance(
	moduleId: 'shef.toolsaicrm'
);
if(!$response->isSuccess())
{
	return $response;
}

/** @var ShOptionsConfig $options */
$options = $response->getData()['OPTIONS'];

// Ссылки ведут в слайдер, если и сама страница открыта в слайдере (раздел «Общие» задаёт $self)
$settingsUrl = isset($self) && is_string($self) ? $self : Sections::url(Sections::OPTIONS);
$inSlider = str_contains($settingsUrl, 'IFRAME=Y');

$options->addTab(
	(new Options\Tab('DEAL'))
		->setName(Loc::getMessage($options->moduleId.'_TAB_DEAL_NAME'))
		->setTitle(Loc::getMessage($options->moduleId.'_TAB_DEAL_TITLE'))
		->addOption(
			// Модель, расход и квота — в shef.toolsai: здесь только ссылки
			(new Options\RowInfo('Core'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_DEAL_Core', [
					'#MODEL_URL#' => Sections::coreUrl(Sections::CORE_SECTION_OPTIONS, $inSlider),
					'#QUOTA_URL#' => Sections::coreUrl(Sections::CORE_SECTION_QUOTA, $inSlider),
				]))
				->setType(Options\TypeUIAlert::Note)
		)
		->addOption(
			(new Options\Checkbox('enabled'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_DEAL_enabled'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_DEAL_enabled_descr'))
		)
		->addOption(
			(new Options\NumberInt('maxperrun'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_DEAL_maxperrun'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_DEAL_maxperrun_descr'))
		)
		->addOption(
			// Интервал агента, минут: после сохранения агенты сверяются сразу (settings/options.php)
			(new Options\NumberInt('interval'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_DEAL_interval'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_DEAL_interval_descr'))
		)
		->addOption(
			// Запись модуля в CRM (итоги анализа, отметки) — с роботами и БП или тихо
			(new Options\Checkbox('crmevents'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_DEAL_crmevents'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_DEAL_crmevents_descr'))
		)
		->addOption(
			// Направления, пороги, старший, промпт и скрипты звонков — на странице «Авто-анализ CRM»
			(new Options\RowInfo('Profiles'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_DEAL_Profiles', [
					'#URL#' => Sections::url(Sections::PROFILES, [], $inSlider),
				]))
		)
);

return $options->get();
