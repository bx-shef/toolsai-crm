<?php declare(strict_types=1);

/**
 * Справка модуля в «Базе знаний 2.0» — без ядра.
 *
 * Что держит:
 *
 * * пакет shef.toolsaicrm:guide (kb/guide) собран из docs/guide и
 *   settings/faqdata.php этого дерева (php tools/kb-build.php) — устаревший
 *   пакет краснеет; метка kb/version.txt — этой версии;
 * * в статьях нет ссылок на docs/private, репозитории и docs/dev;
 * * у каждого ответа faqdata (поле doc) есть статья и заголовок с таким
 *   якорем, а заголовки внутри статьи не повторяются: «База знаний» строит
 *   якорь из текста заголовка и дописывает «-2» повтору.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/assert.php';
require_once $root.'/tools/kb-build.php';

/** Якорь заголовка, как его строит «База знаний» (shef.toolsaikb Note\Anchor::of). */
$anchor = static function(string $heading): string
{
	$translit = [
		'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'ye',
		'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
		'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
		'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ъ' => '',
		'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
	];

	return trim((string)preg_replace('/[^a-z0-9]+/', '-', strtr(mb_strtolower($heading), $translit)), '-');
};

Check::group('пакет справки kb/guide');

$built = kbBuild($root);
$have = [];
foreach(glob($root.'/kb/guide/*.md') ?: [] as $file)
{
	$have[basename($file)] = (string)file_get_contents($file);
}
ksort($have);
Check::same('kb/guide собран из этого дерева (иначе: php tools/kb-build.php)', array_keys($have) === array_keys($built) && $have === $built, true);
Check::same('оглавление, FAQ и главы; установки нет', [isset($built['index.md']), isset($built['faq.md']), isset($built['deal-analysis.md']), isset($built['install-ops.md'])], [true, true, true, false]);
preg_match("/'VERSION' => '([^']+)'/", (string)file_get_contents($root.'/install/version.php'), $v);
Check::same('метка kb/version.txt — этой версии модуля', explode(' ', trim((string)file_get_contents($root.'/kb/version.txt')))[0], $v[1]);
$private = array_keys(array_filter($built, static fn(string $md): bool => str_contains($md, 'docs/private') || str_contains($md, 'github.com/bx-shef') || str_contains($md, 'docs/dev')));
Check::same('в статьях нет ссылок на docs/private, docs/dev и репозитории', $private, []);
Check::same('ссылка на главу — относительная, на статью пакета', str_contains($built['index.md'], '](deal-analysis.md)'), true);

Check::group('ответы «?» ведут в существующие разделы справки');

$headings = [];
$repeats = [];
foreach($built as $name => $md)
{
	$fence = false;
	foreach(explode("\n", $md) as $line)
	{
		if(preg_match('/^(```|~~~)/', $line))
		{
			$fence = !$fence;
			continue;
		}
		if(!$fence && preg_match('/^#{1,6}\s+(.*)$/u', $line, $m))
		{
			$headings[$name][] = $anchor(trim($m[1]));
		}
	}
	foreach(array_count_values($headings[$name] ?? []) as $a => $count)
	{
		if($count > 1)
		{
			$repeats[] = $name.'#'.$a.' ×'.$count;
		}
	}
}
Check::same('заголовки внутри статьи не повторяются', $repeats, []);

$missing = [];
$checked = 0;
foreach(require $root.'/settings/faqdata.php' as $key => $item)
{
	if(!isset($item['doc']) || !is_string($item['doc']))
	{
		continue;
	}
	$target = \Shef\ToolsAiCrm\Settings\Faq::docTarget($item['doc']);
	$checked++;
	if($target === null || !isset($headings[$target[0]]))
	{
		$missing[] = $key.' -> нет статьи '.$item['doc'];
		continue;
	}
	if($target[1] !== null && !in_array($anchor($target[1]), $headings[$target[0]], true))
	{
		$missing[] = $key.' -> нет раздела '.$item['doc'];
	}
}
Check::same('ответов с doc проверено больше десяти', $checked > 10, true);
Check::same('у каждого doc есть статья и раздел с таким якорем', $missing, []);

Check::finish();
