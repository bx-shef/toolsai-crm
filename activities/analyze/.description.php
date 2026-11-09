<?php
/**
 * Робот «AI: оценить сейчас» Заглушка в
 * /bitrix/activities/custom/shtoolsaianalyzeactivity/ ведёт сюда (Main\PublicPage).
 */
if(!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

$arActivityDescription = [
	'NAME' => 'AI: оценить сейчас',
	'DESCRIPTION' => 'Анализ записи моделью (модуль shef.toolsaicrm) сразу, не дожидаясь агента: риск, почему, что сделать.',
	'TYPE' => ['activity', 'robot_activity'],
	'CLASS' => 'ShToolsAiAnalyzeActivity',
	'JSCLASS' => 'BizProcActivity',
	'CATEGORY' => [
		'ID' => 'other',
		'OWN_ID' => 'crm',
		'OWN_NAME' => 'CRM',
	],
	'RETURN' => [
		'Risk' => ['NAME' => 'Риск, %', 'TYPE' => 'int'],
		'Why' => ['NAME' => 'Почему', 'TYPE' => 'text'],
		'NextStep' => ['NAME' => 'Что сделать', 'TYPE' => 'text'],
		'Status' => ['NAME' => 'Итог запуска', 'TYPE' => 'string'],
	],
	'FILTER' => [
		'INCLUDE' => [
			['crm', 'CCrmDocumentDeal'],
			['crm', 'CCrmDocumentLead'],
			['crm', 'Bitrix\\Crm\\Integration\\BizProc\\Document\\Dynamic'],
		],
	],
	'ROBOT_SETTINGS' => [
		'CATEGORY' => 'other',
		'TITLE' => 'AI: оценить сейчас',
	],
];
