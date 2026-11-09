<?php declare(strict_types=1);

/**
 * Настраиваемые параметры модуля shef.toolsaicrm.
 *
 * * requireModules -> обязательные модули линейки: autoload.php подключает их
 *   при каждой загрузке, установщик их удаление не пропустит: shef.options,
 *   shef.problems и движок shef.toolsai (модель, расход, квота, права — через
 *   его публичный API). Обратной зависимости нет: shef.toolsai от этого модуля
 *   не зависит, связь с ним — события интеграции.
 * * requirePhpExt -> обязательные расширения PHP
 * * registerAutoLoadClasses, registerNamespace -> пусто: свои классы ядро
 *   находит по соглашению (lib/ строчными), чужих библиотек нет
 * * installEvents -> постоянные подписки: регистрирует InstallEvents()
 *   установщика, снимает парный UnInstallEvents(); после обновления
 *   копированием их ставит Main\Maintenance. Блоки между комментариями
 *   «--- имя ---» ведут разные части модуля: правь только свой
 * * installDir -> пусто: в /bitrix/admin модуль ничего не копирует, а ПИШЕТ
 *   заглушки публичных страниц и робота БП (Main\PublicPage)
 * * controllers -> ajax: shef:toolsaicrm.<контроллер>.<действие> ->
 *   Shef\ToolsAiCrm\Controller\<Контроллер>::<действие>Action()
 */

return [
	'requireModules' => [
		'value' => [
			'shef.options',
			'shef.problems',
			'shef.toolsai',
		],
		'readonly' => true,
	],
	'requirePhpExt' => [
		'value' => [
			'mbstring',
			'json',
		],
		'readonly' => true,
	],
	'registerAutoLoadClasses' => [
		'value' => [],
		'readonly' => true,
	],
	'registerNamespace' => [
		'value' => [],
		'readonly' => true,
	],
	'options' => [
		'value' => [],
		'readonly' => true,
	],
	'installEvents' => [
		'value' => [
			// --- install: fields and call scripts ---
			// Поля «AI: …» и подбор скрипта звонка. Классы — ровно в том виде, как их пишут
			// Analysis\EntityFields::EVENTS и CallScript\Events::ADD (без ведущего «\»): одна запись
			// в b_module_to_module, а не две; держит tests/install_test.php. Тип поля
			// shef_toolsai_ack регистрирует этот модуль (прежние подписки shef.toolsai снимает
			// Main\Migration). isCompatible — false, как у них: обработчики берут поля по ссылке.
			[
				'isCompatible' => false,
				'from' => ['module' => 'main', 'event' => 'OnUserTypeBuildList'],
				'to' => ['module' => 'shef.toolsaicrm', 'class' => 'Shef\ToolsAiCrm\Deal\Field\AckType', 'function' => 'getUserTypeDescription', 'sort' => 100],
			],
			[
				'isCompatible' => false,
				'from' => ['module' => 'main', 'event' => 'onGetPublicEdit'],
				'to' => ['module' => 'shef.toolsaicrm', 'class' => 'Shef\ToolsAiCrm\Deal\Field\Handlers', 'function' => 'onGetPublicEdit', 'sort' => 100],
			],
			[
				'isCompatible' => false,
				'from' => ['module' => 'crm', 'event' => 'OnBeforeCrmDealUpdate'],
				'to' => ['module' => 'shef.toolsaicrm', 'class' => 'Shef\ToolsAiCrm\Deal\Field\Handlers', 'function' => 'onBeforeDealUpdate', 'sort' => 100],
			],
			[
				'isCompatible' => false,
				'from' => ['module' => 'crm', 'event' => 'OnBeforeCrmLeadUpdate'],
				'to' => ['module' => 'shef.toolsaicrm', 'class' => 'Shef\ToolsAiCrm\Deal\Field\Handlers', 'function' => 'onBeforeLeadUpdate', 'sort' => 100],
			],
			[
				'isCompatible' => false,
				'from' => ['module' => 'crm', 'event' => 'OnBeforeCrmDealAdd'],
				'to' => ['module' => 'shef.toolsaicrm', 'class' => 'Shef\ToolsAiCrm\Deal\Field\Handlers', 'function' => 'onBeforeDealAdd', 'sort' => 100],
			],
			[
				'isCompatible' => false,
				'from' => ['module' => 'crm', 'event' => 'onCrmDynamicItemAdd'],
				'to' => ['module' => 'shef.toolsaicrm', 'class' => 'Shef\ToolsAiCrm\Deal\Field\Handlers', 'function' => 'onDynamicItemAdd', 'sort' => 100],
			],
			[
				'isCompatible' => false,
				'from' => ['module' => 'crm', 'event' => 'OnBeforeCrmActivityAdd'],
				'to' => ['module' => 'shef.toolsaicrm', 'class' => 'Shef\ToolsAiCrm\CallScript\Events', 'function' => 'onBeforeActivityAdd', 'sort' => 100],
			],
			// --- /install: fields and call scripts ---
			// --- integrations ---
			// Подписки интеграций с соседями: REST и MCP, вид «AI», панель «ракеты», справка.
			// Правит владелец интеграций; нет соседа — событие никто не шлёт.
			// REST-метод отчёта «Оценка работы менеджеров» и манифест инструментов MCP
			// (область shef_toolsaicrm). Событие rest — старого вида (GetModuleEvents): isCompatible.
			[
				'isCompatible' => true,
				'from' => ['module' => 'rest', 'event' => 'OnRestServiceBuildDescription'],
				'to' => [
					'module' => 'shef.toolsaicrm',
					'class' => '\\Shef\\ToolsAiCrm\\Integration\\Rest\\Service',
					'function' => 'onRestServiceBuildDescription',
					'sort' => 100,
				],
			],
			// Карточки панели «ракеты» shef.toolsai (1.79.0+); плитки хаба «AI-инструменты» — из них же.
			[
				'isCompatible' => false,
				'from' => ['module' => 'shef.toolsai', 'event' => 'OnRocketCardsCollect'],
				'to' => [
					'module' => 'shef.toolsaicrm',
					'class' => '\\Shef\\ToolsAiCrm\\Internals\\Integration\\ToolsAi\\EventHandler\\OnRocketCardsCollectEventHandler',
					'function' => 'handle',
					'sort' => 100,
				],
			],
			// Вид «AI» shef.toolsai: действие «Анализ» над записью и состояние авто-анализа типа.
			[
				'isCompatible' => false,
				'from' => ['module' => 'shef.toolsai', 'event' => 'OnViewAnalyzeRecord'],
				'to' => [
					'module' => 'shef.toolsaicrm',
					'class' => '\\Shef\\ToolsAiCrm\\Internals\\Integration\\ToolsAi\\EventHandler\\OnViewAnalyzeRecordEventHandler',
					'function' => 'handle',
					'sort' => 100,
				],
			],
			[
				'isCompatible' => false,
				'from' => ['module' => 'shef.toolsai', 'event' => 'OnViewAnalysisState'],
				'to' => [
					'module' => 'shef.toolsaicrm',
					'class' => '\\Shef\\ToolsAiCrm\\Internals\\Integration\\ToolsAi\\EventHandler\\OnViewAnalysisStateEventHandler',
					'function' => 'handle',
					'sort' => 100,
				],
			],
			// Справка модуля в «Базе знаний» (shef.toolsaikb): пакет shef.toolsaicrm:guide.
			[
				'isCompatible' => false,
				'from' => ['module' => 'shef.toolsaikb', 'event' => 'OnKbSourcesCollect'],
				'to' => [
					'module' => 'shef.toolsaicrm',
					'class' => '\\Shef\\ToolsAiCrm\\Internals\\Integration\\Shef\\ToolsAiKb\\EventHandler\\OnKbSourcesCollectEventHandler',
					'function' => 'handle',
					'sort' => 100,
				],
			],
			// --- /integrations ---
		],
		'readonly' => true,
	],
	'installDir' => [
		'value' => [],
		'readonly' => true,
	],
	'controllers' => [
		'value' => [
			'defaultNamespace' => '\\Shef\\ToolsAiCrm\\Controller',
			'namespaces' => [],
		],
		'readonly' => true,
	],
];
