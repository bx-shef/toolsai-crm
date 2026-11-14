<?php declare(strict_types=1);

/**
 * Дочерний процесс для tests/installpage_test.php: настоящий ShowForm() установщика и шаг удаления
 * install/unstep1.php с двойниками ядра. ShowForm() и CMain::IncludeAdminFile() заканчиваются die(),
 * поэтому — отдельным процессом. Аргументы: <корень модуля> <docroot двойников> <ShowForm|Unstep1>
 * [OK|ERROR]; COLLIDE=имя,имя — глобальные с этими именами (параметры ShowForm они не перекрывают).
 */

[, $root, $doc, $method] = $argv; // тип формы берётся из $argv[4] при вызове: глобальные проверяет COLLIDE

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/stub/install.php';

use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;

$MESS = [];
require $root.'/lang/ru/install/index.php';
Loc::$messages = $MESS;

define('LANG', 'ru');
define('LANGUAGE_ID', 'ru');
define('B_PROLOG_INCLUDED', true);
define('BX_ROOT', '/bitrix');
$_SERVER['DOCUMENT_ROOT'] = $doc;

class CAdminMessage
{
	public static function ShowMessage(array $message): void
	{
		echo '<MSG type='.$message['TYPE'].'>'.$message['MESSAGE'].'</MSG>';
	}
}

function bitrix_sessid_post(): string
{
	return '<input type="hidden" name="sessid" value="x">';
}

class FakeUser
{
	public function IsAuthorized(): bool
	{
		return true;
	}
}

class FakeApp
{
	public function SetTitle(string $title): void
	{
		echo '<TITLE>';
	}

	public function GetCurPage(): string
	{
		return '/bitrix/admin/module_admin.php';
	}

	/** Логика CMain::IncludeAdminFile() ядра (main/classes/general/main.php:196). */
	public function IncludeAdminFile($strTitle, $filepath)
	{
		static $exclude = ['this' => 1, 'exclude' => 1, 'key' => 1, 'GLOBALS' => 1, 'strTitle' => 1, 'filepath' => 1];
		foreach($GLOBALS as $key => $value)
		{
			if(!isset($exclude[$key]))
			{
				global ${$key};
			}
		}
		$this->SetTitle($strTitle);
		include($_SERVER['DOCUMENT_ROOT'].BX_ROOT.'/modules/main/include/prolog_admin_after.php');
		include($filepath);
		include($_SERVER['DOCUMENT_ROOT'].BX_ROOT.'/modules/main/include/epilog_admin.php');
		die();
	}
}

// глобальная область, как у страницы ядра module_admin.php
$USER = new FakeUser();
$APPLICATION = new FakeApp();
foreach(array_filter(explode(',', (string)getenv('COLLIDE'))) as $name)
{
	$GLOBALS[$name] = 'GLOBAL-'.$name;
}
$key = 'GLOBAL_KEY'; // у страниц ядра в глобальной области бывают $key и $content
$content = 'GLOBAL_CONTENT';

Application::$documentRoot = $doc;
Application::$utf = true;
require_once $root.'/install/index.php';

$module = new shef_toolsaicrm();
if($method === 'ShowForm')
{
	(new ReflectionMethod($module, 'ShowForm'))->invoke($module, $argv[4] ?? 'OK', 'REPORT-TEXT');
}
else
{
	// шаг 1 удаления — как в DoUninstall(), без заглушек запроса
	$APPLICATION->IncludeAdminFile((string)Loc::getMessage('shef.toolsaicrm_MODULE_NAME'), $root.'/install/unstep1.php');
}
echo '<AFTER-DIE>'; // сюда попасть нельзя
