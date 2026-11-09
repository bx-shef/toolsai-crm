<?php declare(strict_types=1);

/**
 * Общий слой стилей экранов (bx-shef/toolsai#481) — Ui\Tai и lib/ui/tai.css, без ядра.
 *
 * Что держит:
 *
 * * каждый токен --tai-*, который читает tai.css, в нём же объявлен; тёмная тема переопределяет
 *   все цветовые токены светлой (иначе в тёмной теме остался бы светлый цвет);
 * * экраны настроек (settings/*.php) берут цвета только из токенов: голого #hex в color/background/border нет;
 * * токены, которые читают экраны, объявлены в tai.css;
 * * мутация: копия слоя без одного токена краснеет в той же проверке, исходная — нет.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiCrm\Ui\Tai;

/** @return string[] токены, которые читают, но не объявляют */
$undeclared = static function(string $css, string $reads): array
{
	preg_match_all('/(--tai-[a-z0-9-]+)\s*:/', $css, $declared);
	preg_match_all('/var\((--tai-[a-z0-9-]+)/', $reads, $used);

	return array_values(array_diff(array_unique($used[1]), array_unique($declared[1])));
};

$css = Tai::style();

Check::group('токены');
Check::same('слой не пуст и скобки сходятся', [$css !== '', substr_count($css, '{') === substr_count($css, '}')], [true, true]);
Check::same('tai.css читает только объявленные токены', $undeclared($css, $css), []);

[$light, $dark] = [
	substr($css, strpos($css, '.shef-tai, .shef-tai-view'), strpos($css, '.bitrix24-dark-theme .shef-tai,') - strpos($css, '.shef-tai, .shef-tai-view')),
	substr($css, strpos($css, '.bitrix24-dark-theme .shef-tai,'), strpos($css, '/* endregion */', strpos($css, '.bitrix24-dark-theme .shef-tai,')) - strpos($css, '.bitrix24-dark-theme .shef-tai,')),
];
preg_match_all('/(--tai-(?:surface|text|accent|border|success|warning|danger|info)[a-z0-9-]*)\s*:/', $light, $lightTokens);
preg_match_all('/(--tai-[a-z0-9-]+)\s*:/', $dark, $darkTokens);
$notDark = array_values(array_diff(array_unique($lightTokens[1]), $darkTokens[1]));
// фон мягкой плашки и неподвижные значения тёмной темы не меняют только пары «значок статуса»: все цветовые токены обязаны быть
Check::same('тёмная тема переопределяет все цветовые токены', $notDark, []);

Check::group('экраны');
$files = array_merge(glob($root.'/settings/*.php') ?: [], [$root.'/lib/ui/settingsform.css']);
$hex = [];
$reads = '';
foreach($files as $file)
{
	$name = basename($file);
	$text = (string)file_get_contents($file);
	$reads .= $text;
	if(preg_match_all('/(?:color|background(?:-color)?|border(?:-[a-z]+)?)\s*:\s*(?:[0-9.]+px solid )?#[0-9a-fA-F]{3,6}\b/', $text, $m))
	{
		$hex[$name] = $m[0];
	}
}
Check::same('в экранах нет голых цветов', $hex, []);
Check::same('экраны читают только объявленные токены', $undeclared($css, $reads), []);

Check::group('мутация');
$mutant = (string)preg_replace('/--tai-danger-bg:[^;]*;/', '', $css);
Check::same('копия без токена краснеет', $undeclared($mutant, $css) === ['--tai-danger-bg'], true);
Check::same('исходная — нет', $undeclared($css, 'x{color:var(--tai-danger-bg)}'), []);

Check::finish();
