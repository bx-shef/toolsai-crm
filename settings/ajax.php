<?php declare(strict_types=1);

/**
 * ajax.php компонента shef:toolsaicrm.settings.
 *
 * В /local/components/shef/toolsaicrm.settings/ лежит заглушка в одну строку
 * (Main\PublicPage) — она ведёт сюда. Ядро подключает ajax.php компонента для
 * действий runComponentAction; страницы модуля пишут обычными формами (POST с
 * check_bitrix_sessid()), поэтому своих действий у компонента нет, а страница
 * прав живёт в shef.toolsai. Файл есть, чтобы заглушка вела не в пустоту.
 */

defined('B_PROLOG_INCLUDED') && B_PROLOG_INCLUDED === true || die();
