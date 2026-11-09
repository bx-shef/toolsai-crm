<?php declare(strict_types=1);

/**
 * Строка версии под меню настроек : версия, сборка из kb/version.txt
 * (только при той же версии), дата; HTML экранирован, щелчок копирует.
 */

$root = dirname(__DIR__);
require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiCrm\Settings\BuildInfo as B;

Check::group('строка версии');
Check::same('версия, сборка, дата', B::line('shef.toolsaicrm', '1.63.0', '2026-10-19 00:00:00', "1.63.0 a1b2c3d\n"), 'shef.toolsaicrm 1.63.0 · сборка a1b2c3d · 19.10.2026');
Check::same('kb/ другой версии — без сборки', B::line('shef.toolsaicrm', '1.63.0', '2026-10-19 00:00:00', '1.62.3 5b053911'), 'shef.toolsaicrm 1.63.0 · 19.10.2026');
Check::same('нет файла — версия и дата', B::line('shef.toolsaicrm', '1.63.0', '2026-10-19', ''), 'shef.toolsaicrm 1.63.0 · 19.10.2026');
Check::same('мусор вместо хеша — без сборки', B::line('m', '1', '', '1 <script>'), 'm 1');
Check::same('HTML экранирован, копирование по щелчку', [str_contains(B::html('a <b>'), 'a &lt;b&gt;'), str_contains(B::html('x'), 'BX.clipboard.copy')], [true, true]);
$html = B::html('shef.toolsaicrm 1.67.0 · сборка a1b2c3d · 27.10.2026');
Check::same('край как у заголовка меню (6px), модуль и версия — строкой, сборка и дата — второй, копируется целиком', [
	str_contains($html, 'padding:0 6px'),
	str_contains($html, 'margin:12px 0 0 20px'),
	str_contains($html, '<span style="display:block">shef.toolsaicrm 1.67.0</span><span style="display:block">сборка a1b2c3d · 27.10.2026</span>'),
	str_contains($html, 'data-line="shef.toolsaicrm 1.67.0 · сборка a1b2c3d · 27.10.2026"'),
], [true, false, true, true]);
$arModuleVersion = [];
include $root.'/install/version.php';
Check::same('текущая — из version.php', str_starts_with(B::current(), 'shef.toolsaicrm '.$arModuleVersion['VERSION'].' '), true);
Check::same('выводится под меню штатно (left-panel-after)', str_contains((string)file_get_contents($root.'/settings/component.php'), "AddViewContent('left-panel-after', BuildInfo::html(BuildInfo::current()))"), true);

Check::finish();
