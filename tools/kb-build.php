<?php declare(strict_types=1);

/**
 * Пакет справки shef.toolsaicrm:guide: Markdown для «Базы знаний 2.0»
 * портала. Статьи импортирует shef.toolsaikb (событие OnKbSourcesCollect, каталог
 * kb/guide модуля), показывают штатные страницы note.
 *
 *   php tools/kb-build.php            пересобрать kb/guide из docs/guide и settings/faqdata.php
 *                                     и метку kb/version.txt (версия и коммит — Settings\BuildInfo)
 *   php tools/kb-build.php --check    сверить kb/guide со сборкой (код выхода 1 — устарел)
 *
 * Что попадает в пакет — только публичная справка: главы docs/guide (кроме главы
 * установки), оглавление (index.md) и «Вопросы и ответы» (faq.md) из faqdata.php.
 * docs/private и прочие файлы репозитория — текстом без ссылки.
 *
 * Ссылки между главами — относительные (`deal-analysis.md#якорь`): импорт shef.toolsaikb
 * переписывает их в адреса документов. Свои якоря (`<a id>`, `{#id}`) «База знаний»
 * не понимает, якорь — текст заголовка; поэтому заголовки вопросов FAQ уникальны
 * (Settings\Faq::headings()).
 */

$root = dirname(__DIR__);
require_once $root.'/lib/main/constants.php';
require_once $root.'/lib/settings/sections.php';
require_once $root.'/lib/settings/faq.php';

use Shef\ToolsAiCrm\Settings\Faq;

/** Главы, которых в пакете нет: установка — в документах репозитория. */
const KB_EXCLUDE = ['12-install-ops.md'];

/**
 * @return array<string, string> путь в пакете => текст
 */
function kbBuild(string $root): array
{
	$guide = $root.'/docs/guide';
	$files = array_values(array_filter(scandir($guide) ?: [], static fn(string $f): bool => (bool)preg_match('/^\d+-.+\.md$/', $f) && !in_array($f, KB_EXCLUDE, true)));
	sort($files);
	$map = ['README.md' => 'index.md'];
	foreach($files as $f)
	{
		$map[$f] = preg_replace('/^\d+-/', '', $f);
	}

	$out = [];
	foreach($map as $src => $dst)
	{
		$out[$dst] = kbPage((string)file_get_contents($guide.'/'.$src), $map);
	}
	$out['faq.md'] = kbFaq();
	ksort($out);

	return $out;
}

/** @param array<string, string> $map файл главы => путь в пакете */
function kbPage(string $md, array $map): string
{
	$md = str_replace("\r\n", "\n", $md);
	$md = preg_replace('/^---\n[\s\S]*?\n---\n/', '', $md);
	$md = preg_replace('/^>\s*Последняя сверка:.*\n\n?/m', '', $md);
	// Строка таблицы со ссылкой на исключённую главу — прочь
	$md = implode("\n", array_filter(explode("\n", $md), static function(string $line): bool {
		if(!str_starts_with($line, '|'))
		{
			return true;
		}
		foreach(KB_EXCLUDE as $f)
		{
			if(str_contains($line, ']('.$f) || str_contains($line, '](./'.$f))
			{
				return false;
			}
		}

		return true;
	}));
	// Относительные ссылки: глава — на статью пакета, остальное — текстом
	$md = preg_replace_callback('/\[([^\]]*)\]\((?!https?:|mailto:|#|\/)([^)\s#]+)(#[^)\s]*)?\)/u', static function(array $m) use ($map): string {
		$file = basename(preg_replace('/:\d+(-\d+)?$/', '', $m[2]));
		$inGuide = !str_contains($m[2], '/') || str_starts_with($m[2], './') || preg_match('~^(\.\./)*docs/guide/~', $m[2]) || str_starts_with($m[2], '../guide/');
		if($inGuide && isset($map[$file]))
		{
			return '['.$m[1].']('.$map[$file].($m[3] ?? '').')';
		}
		if(in_array($file, KB_EXCLUDE, true))
		{
			return 'глава «Установка и эксплуатация» в документах репозитория модуля';
		}

		return $m[1];
	}, $md);
	// Ссылки на GitHub-репозитории линейки — текстом
	$md = preg_replace('~\[([^\]]*)\]\(https://github\.com/bx-shef/[^)\s]*\)~u', '$1', $md);

	return rtrim($md)."\n";
}

function kbFaq(): string
{
	$out = ['# Вопросы и ответы по настройкам', '', 'Коротко о каждой настройке модуля: что это и зачем. «Подробнее» в подсказке значка «?» открывает здесь нужный ответ.', ''];
	$headings = Faq::headings();
	foreach(Faq::bySection() as $section => $items)
	{
		$out[] = '## '.Faq::sectionHeading($section);
		$out[] = '';
		foreach($items as $key => $item)
		{
			$target = isset($item['doc']) && is_string($item['doc']) ? Faq::docTarget($item['doc']) : null;
			$more = $target !== null && $target[0] !== 'faq.md' ? ' [Подробнее]('.$target[0].($target[1] !== null ? '#'.$target[1] : '').')' : '';
			$out[] = '### '.$headings[$key];
			$out[] = '';
			$out[] = '**Что это.** '.trim($item['what']);
			$out[] = '';
			$out[] = '**Зачем.** '.trim($item['why']).$more;
			$out[] = '';
		}
	}

	return rtrim(implode("\n", $out))."\n";
}

if(realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__))
{
	$check = in_array('--check', $argv, true);
	$dir = $root.'/kb/guide';
	$built = kbBuild($root);
	$have = [];
	foreach(glob($dir.'/*.md') ?: [] as $f)
	{
		$have[basename($f)] = (string)file_get_contents($f);
	}
	ksort($have);
	if($check)
	{
		if($have !== $built)
		{
			fwrite(STDERR, "kb/guide устарел: php tools/kb-build.php\n");
			exit(1);
		}
		echo "kb/guide: ".count($built)." статей, совпадает со сборкой\n";
		exit(0);
	}
	@mkdir($dir, 0775, true);
	foreach(array_diff_key($have, $built) as $name => $_)
	{
		unlink($dir.'/'.$name);
	}
	foreach($built as $name => $text)
	{
		file_put_contents($dir.'/'.$name, $text);
	}
	// Метка сборки для строки версии в настройках (Settings\BuildInfo): «<версия> <коммит>»
	preg_match("/'VERSION' => '([^']+)'/", (string)file_get_contents($root.'/install/version.php'), $v);
	$hash = trim((string)shell_exec('git -C '.escapeshellarg($root).' rev-parse --short HEAD 2>/dev/null'));
	file_put_contents($root.'/kb/version.txt', ($v[1] ?? '').($hash !== '' ? ' '.$hash : '')."\n");
	echo "kb/guide: ".count($built)." статей\n";
}
