<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Ui;

/**
 * Общий слой стилей экранов (bx-shef/toolsai#481): токены --tai-* и компоненты дизайн-системы v2
 * одним файлом lib/ui/tai.css. Подключают настройки (settings/component.php), страница
 * «AI-инструменты» и вид «AI»; этот файл — копия слоя shef.toolsai (ef8da37c), модуль shef.toolsaicrm держит свою.
 *
 * Без ядра — тестируется taistyle_test.php.
 */
final class Tai
{
	/** Содержимое tai.css для тега style. */
	public static function style(): string
	{
		return (string)file_get_contents(__DIR__.'/tai.css');
	}
}
