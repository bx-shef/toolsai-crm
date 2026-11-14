<?php declare(strict_types=1);

/**
 * Страницы установщика — поведение, не текст (bx-shef/toolsai-crm#9).
 *
 * Что держит: настоящий ShowForm() (итог, отказ) и шаг удаления install/unstep1.php выполняются в
 * дочернем процессе (tests/stub/installpage_child.php) с двойниками пролога и эпилога ядра. Двойник
 * эпилога, как epilog_admin_before.php:14, зовёт $USER->IsAuthorized() из области подключения:
 * пролог и эпилог в разных областях — Error, как в #9. Порядок страницы: заголовок, пролог,
 * сообщение, эпилог, die(); глобальные с именами параметров ShowForm их не перекрывают.
 */

$root = dirname(__DIR__);
require_once $root.'/tests/assert.php';

$doc = sys_get_temp_dir().'/shef-toolsaicrm-installpage-'.getmypid();
$inc = $doc.'/bitrix/modules/main/include';
mkdir($inc, 0777, true);
file_put_contents($inc.'/prolog_admin_after.php', <<<'PHP'
<?php
// как prolog_admin_after.php: $USER и $APPLICATION — из области подключения
if(!is_object($USER ?? null) || !is_object($APPLICATION ?? null)) { throw new LogicException('пролог: нет $USER/$APPLICATION'); }
echo '<PROLOG>';
PHP);
file_put_contents($inc.'/epilog_admin.php', <<<'PHP'
<?php
// как epilog_admin_before.php:14 — $USER из области подключения
if($USER->IsAuthorized()) { echo '<EPILOG key='.$GLOBALS['key'].'>'; }
echo '</html>';
PHP);

$run = static function(array $args, string $collide) use ($root, $doc): array {
	$env = getenv();
	$env['COLLIDE'] = $collide;
	$process = proc_open(
		array_merge([PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1', $root.'/tests/stub/installpage_child.php', $root, $doc], $args),
		[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
		$pipes,
		null,
		$env
	);
	$out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);

	return [proc_close($process), $out];
};

$cases = [
	'итог установки' => [['ShowForm', 'OK'], '', 'OK'],
	'отказ' => [['ShowForm', 'ERROR'], '', 'ERROR'],
	'глобальные с именами параметров ShowForm' => [['ShowForm', 'OK'], 'message,type,buttonName,keys,i', 'OK'],
	'шаг 1 удаления' => [['Unstep1'], '', 'ERROR'],
];
foreach($cases as $title => [$args, $collide, $type])
{
	Check::group('страница установщика: '.$title);
	[$code, $out] = $run($args, $collide);
	Check::same('процесс завершился штатно (die), без фатальной ошибки', $code, 0);
	Check::same('в выводе нет Error, Warning, Notice, Deprecated', preg_match('/Fatal|Warning|Notice|Deprecated|Error|LogicException/', $out), 0);
	Check::same('порядок: заголовок, пролог, сообщение, эпилог, конец страницы', preg_match('/^<TITLE><PROLOG><MSG type='.$type.'>.*<EPILOG key=GLOBAL_KEY><\/html>$/s', $out), 1);
	Check::same('после die() ничего', str_contains($out, '<AFTER-DIE>'), false);
	Check::same('пролог и конец страницы — по одному', [substr_count($out, '<PROLOG>'), substr_count($out, '</html>')], [1, 1]);
	Check::same('форма на месте', [str_contains($out, '<form action="/bitrix/admin/module_admin.php" method="get">'), str_contains($out, 'name="lang" value="ru"')], [true, true]);
	if($args[0] === 'ShowForm')
	{
		Check::same('текст сообщения — свой, глобальные его не подменили', [str_contains($out, 'REPORT-TEXT'), str_contains($out, 'GLOBAL-')], [true, false]);
	}
	else
	{
		Check::same('форма удаления: sessid, step=2, uninstall=Y, id, «Сохранить данные» отмечено', [str_contains($out, 'name="sessid"'), str_contains($out, 'name="step" value="2"'), str_contains($out, 'name="uninstall" value="Y"'), str_contains($out, 'name="id" value="shef.toolsaicrm"'), str_contains($out, 'name="savedata" value="Y" checked')], [true, true, true, true, true]);
	}
}

array_map('unlink', glob($inc.'/*.php'));
rmdir($inc);
rmdir($doc.'/bitrix/modules/main');
rmdir($doc.'/bitrix/modules');
rmdir($doc.'/bitrix');
rmdir($doc);

Check::finish();
