<?php

/**
 * Удаление модуля, шаг 1: что останется и что сломается (роботы БП), «Сохранить данные» — отмечено.
 * Без отметки таблицы и настройки модуля удаляются: других копий истории оценок, профилей и
 * промптов нет. Подключает DoUninstall() через CMain::IncludeAdminFile(): пролог и эпилог
 * административной страницы выводит ядро (как шаг удаления shef.toolsaimemory).
 */

use Bitrix\Main\Localization\Loc;

defined('B_PROLOG_INCLUDED') && B_PROLOG_INCLUDED === true || die();

Loc::loadMessages(__DIR__.'/index.php');

/** @var \CMain $APPLICATION */
global $APPLICATION;

\CAdminMessage::ShowMessage([
	'MESSAGE' => Loc::getMessage('SH_TOOLSAICRM_UNINSTALL_WARN'),
	'TYPE' => 'ERROR',
	'HTML' => true,
]);
?>
<form action="<?=$APPLICATION->GetCurPage()?>" method="get">
	<?=bitrix_sessid_post()?>
	<input type="hidden" name="lang" value="<?=LANGUAGE_ID?>">
	<input type="hidden" name="id" value="shef.toolsaicrm">
	<input type="hidden" name="uninstall" value="Y">
	<input type="hidden" name="step" value="2">
	<p>
		<label>
			<input type="checkbox" name="savedata" value="Y" checked>
			<?=Loc::getMessage('SH_TOOLSAICRM_UNINSTALL_SAVEDATA')?>
		</label>
	</p>
	<p>
		<input type="submit" value="<?=Loc::getMessage('SH_TOOLSAICRM_UNINSTALL_BUTTON')?>">
	</p>
</form>
