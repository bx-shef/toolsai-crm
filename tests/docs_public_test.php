<?php declare(strict_types=1);

/**
 * Публичная и внутренняя документация не смешиваются.
 *
 * Публичная (README, CHANGELOG, docs/README.md, docs/guide/) идёт в пакет
 * справки kb/guide («База знаний 2.0» портала). Внутренняя — docs/private/:
 * правила агентов, разбор ядра по строкам, планы, проверки на нашем портале.
 * Она не должна попасть в пакет справки, а публичные страницы не должны на неё
 * ссылаться: в справке такая ссылка была бы битой.
 *
 * Проверяется:
 *
 * 1. ни одна публичная страница не ссылается на docs/private/ (ни
 *    относительной ссылкой, ни адресом GitHub);
 * 2. сборщик пакета tools/kb-build.php берёт главы только из docs/guide/,
 *    исключает главу установки и не называет docs/private/;
 * 3. в собранном пакете kb/guide нет ни одного внутреннего документа: ни
 *    страницы с его заголовком, ни его штампа «внутренняя документация не публикуется».
 */

$root = dirname(__DIR__);

require_once $root.'/tests/assert.php';

const PRIVATE_DIR = 'docs/private/';

exec('git -c core.quotePath=false ls-files --cached --others --exclude-standard "*.md"', $markdown, $code);

$public = array_values(array_filter(
	$markdown,
	static fn(string $f): bool => in_array($f, ['README.md', 'CHANGELOG.md', 'docs/README.md'], true)
		|| (str_starts_with($f, 'docs/guide/') && !str_starts_with($f, PRIVATE_DIR))
));
$private = array_values(array_filter($markdown, static fn(string $f): bool => str_starts_with($f, PRIVATE_DIR)));

Check::group('наборы документов');

Check::same('git отдал список файлов', $code, 0);
Check::same('публичных документов больше десяти', count($public) > 10, true);
Check::same('внутренних документов больше одного', count($private) > 1, true);
Check::same('внутри docs/guide нет внутренних глав', array_values(array_filter(
	$public, static fn(string $f): bool => (bool)preg_match('#^docs/guide/9\d-#', $f)
)), []);

Check::group('публичные страницы не ссылаются на внутренние');

$bad = [];

foreach($public as $file)
{
	$text = (string)file_get_contents($root.'/'.$file);

	preg_match_all('/\]\(([^)\s]+)\)/u', $text, $links);

	foreach($links[1] as $target)
	{
		if(preg_match('#^https?://#', $target))
		{
			if(str_contains($target, '/'.PRIVATE_DIR))
			{
				$bad[] = $file.' -> '.$target;
			}
			continue;
		}

		// Относительная ссылка: разрешаем от каталога файла
		$path = preg_replace('/[#?].*$/', '', $target);
		$parts = [];
		foreach(explode('/', dirname($file).'/'.$path) as $part)
		{
			if($part === '' || $part === '.') { continue; }
			if($part === '..') { array_pop($parts); continue; }
			$parts[] = $part;
		}
		$resolved = implode('/', $parts);

		if(str_starts_with($resolved.'/', PRIVATE_DIR))
		{
			$bad[] = $file.' -> '.$target;
		}
	}
}

Check::same('ссылок из публичного во внутреннее нет', $bad, []);

Check::group('публичное — описательное');

// Инструктивное для нас — во внутренней части: ссылки на строки кода,
// команды нашего сервера, ссылки в приватный репозиторий модуля (у
// стороннего читателя они ведут в 404).
$instructive = [];
foreach($public as $file)
{
	$text = (string)file_get_contents($root.'/'.$file);
	if($file === 'CHANGELOG.md') { continue; } // история: старые записи не переписываем

	foreach([
		'/`[^`\n]*\.(?:php|js|ts):\d[^`\n]*`/u' => 'ссылка на строку кода',
		'/sudo -u /u' => 'команда сервера',
		'#/home/bitrix#u' => 'путь нашего сервера',
		'#php_command_line\.php#u' => 'PHP-консоль: инструкция для нас',
		'#github\.com/bx-shef/toolsai(?:-crm)?(?![\w-])#u' => 'ссылка в приватный репозиторий',
	] as $re => $what)
	{
		if(preg_match($re, $text, $m)) { $instructive[] = $file.': '.$what.' — '.$m[0]; }
	}
}
Check::same('в публичных документах нет инструктивного для нас', $instructive, []);

Check::group('пакет справки kb/guide берёт только публичное');

$kbBuild = (string)file_get_contents($root.'/tools/kb-build.php');
Check::same('главы — только из docs/guide, глава установки исключена', [str_contains($kbBuild, "\$guide = \$root.'/docs/guide';"), str_contains($kbBuild, "const KB_EXCLUDE = ['12-install-ops.md'];"), str_contains($kbBuild, 'docs/private/')], [true, true, false]);

Check::group('собранный пакет справки — без внутреннего');

// Заголовки внутренних документов: страница с таким заголовком на сайте = утечка
$titles = [];
foreach($private as $file)
{
	if(preg_match('/^#\s+(.+)$/mu', (string)file_get_contents($root.'/'.$file), $m))
	{
		$titles[$file] = trim($m[1]);
	}
}

$built = [];
foreach(glob($root.'/kb/guide/*.md') ?: [] as $f)
{
	$built['kb/guide/'.basename($f)] = (string)file_get_contents($f);
}
$leaks = [];
foreach($built as $f => $text)
{
	foreach($titles as $src => $title)
	{
		// заголовок страницы — в title: "…" (frontmatter) или «# …»
		if(str_contains($text, 'title: '.json_encode($title, JSON_UNESCAPED_UNICODE))
			|| str_contains($text, "\n# ".$title."\n")
			|| str_contains($text, '"title":'.json_encode($title, JSON_UNESCAPED_UNICODE)))
		{
			$leaks[] = $f.' <- '.$src;
		}
	}
	if(str_contains($text, 'Внутренняя документация (не публикуется)'))
	{
		$leaks[] = $f.' <- '.PRIVATE_DIR.'README.md';
	}
}

Check::same('внутренних документов в собранном пакете справки нет', $leaks, []);
Check::same('пакет справки проверен', count(array_filter(array_keys($built), static fn(string $f): bool => str_starts_with($f, 'kb/guide/'))) > 10, true);

$kbLinks = [];
foreach(glob($root.'/kb/guide/*.md') ?: [] as $f)
{
	if(preg_match('#docs/private/#u', (string)file_get_contents($f), $m)) { $kbLinks[] = basename($f); }
}
Check::same('kb/guide не ссылается на docs/private/', $kbLinks, []);

Check::finish();
