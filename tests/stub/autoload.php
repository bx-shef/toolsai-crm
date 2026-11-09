<?php

/**
 * Автозагрузка для тестов без портала.
 *
 * Правило то же, по которому классы ищет портал: Shef\ToolsAiCrm\Foo\Bar
 * -> lib/foo/bar.php, путь СТРОЧНЫМИ. Держит это соглашение
 * tests/autoload_test.php. Классы модуля подключаются настоящие.
 */

require_once __DIR__.'/bitrix.php';

spl_autoload_register(static function(string $class): void
{
	$prefix = 'Shef\\ToolsAiCrm\\';
	if(!str_starts_with($class, $prefix))
	{
		return;
	}

	$path = dirname(__DIR__, 2).'/lib/'.mb_strtolower(str_replace('\\', '/', substr($class, strlen($prefix)))).'.php';
	if(is_file($path))
	{
		require_once $path;
	}
});
