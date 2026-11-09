<?php declare(strict_types=1);

use Bitrix\Main\Localization\Loc;
use Shef\Options\Main\Options;
use Shef\ToolsAiCrm\Main\Constants;
use Shef\ToolsAiCrm\Main\TestUsersOption;

/**
 * Опции для страницы настроек (страницу рисует options.php из shef.options).
 *
 * языковой файл lang/ru/options.php
 *
 * Tab(prefix)->Option(code) ~> код свойства: prefix_code. Вкладка ANNOUNCE и
 * опция testusers дают ANNOUNCE_testusers — тот же код, что был в
 * shef.toolsai (значение перенесено до 1.2.0). Список объявлений
 * (ANNOUNCE_list, JSON) правится не здесь, а на своей странице — ссылка в
 * первой строке вкладки. Тестовые пользователи — штатный выбор сотрудников
 * (ui.entity-selector), значение — JSON-список; старое «1,15» тоже читается
 * (Announcements::testUsers) и в выборе показывается переведённым в JSON
 * (Main\TestUsersOption).
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

$options->addTab(
	(new Options\Tab('ANNOUNCE'))
		->setName(Loc::getMessage($options->moduleId.'_TAB_ANNOUNCE_NAME'))
		->setTitle(Loc::getMessage($options->moduleId.'_TAB_ANNOUNCE_TITLE'))
		->addOption(
			(new Options\RowInfo('List'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_ANNOUNCE_List', [
					'#URL#' => Constants::listUrl(),
				]))
				->setType(Options\TypeUIAlert::Note)
		)
		->addOption(
			(new TestUsersOption('testusers'))
				->setOnlyUsers(true)
				->setNotUseAllUsers(true)
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_ANNOUNCE_testusers'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_ANNOUNCE_testusers_descr'))
		)
);

return $options->get();
