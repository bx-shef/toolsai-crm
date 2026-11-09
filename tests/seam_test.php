<?php declare(strict_types=1);

/**
 * Шов к shef.toolsai: модуль ходит в движок только через публичный API.
 *
 * Что держит:
 *
 * * в коде модуля (lib/, activities/, корневые файлы) нет обращений к классам
 *   shef.toolsai (Shef\ToolsAi\…) — ни `use`, ни `new`, ни `::`, ни имени
 *   класса в строке, — кроме разрешённого списка SEAM_ALLOWED: публичный API
 *   (LlmService, LlmResponse, AccessService) и две таблицы архива, которые
 *   читает лента касаний (ChatAssessmentTable — оценки чатов, EmailTable —
 *   письма); исключение — SEAM_LEGACY: lib/main/migration.php называет
 *   строками подписки shef.toolsai, которые надо снять;
 * * в установщике, настройках и публичных страницах (install/, settings/,
 *   public/) shef.toolsai может быть назван только строкой — именем старого
 *   агента или обработчика, который надо снять, — но не классом в коде;
 * * комментарии не в счёт; пространство Shef\ToolsAiCrm\ — своё, не чужое;
 * * сам разбор проверен на образцах: нарушение находится, разрешённое и
 *   своё — нет.
 *
 * Чистый PHP, без зависимостей и без заглушек портала.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/assert.php';

/** Классы shef.toolsai, в которые модулю можно ходить. */
const SEAM_ALLOWED = [
	'Shef\\ToolsAi\\Public\\Service\\LlmService',
	'Shef\\ToolsAi\\Public\\Service\\LlmResponse',
	'Shef\\ToolsAi\\Public\\Service\\AccessService',
	'Shef\\ToolsAi\\Chat\\Model\\ChatAssessmentTable',
	'Shef\\ToolsAi\\Email\\Model\\EmailTable',
];

/** Файлы lib/, где старые подписки и агенты shef.toolsai названы строками (их снимает установщик). */
const SEAM_LEGACY = ['lib/main/migration.php'];

/**
 * Обращения к чужим классам shef.toolsai в исходнике PHP.
 *
 * @param bool $stringsOk имя в строке допустимо (установщик пишет имена старых агентов и обработчиков)
 * @return list<array{line: int, name: string}>
 */
function seamViolations(string $source, bool $stringsOk = false): array
{
	$bad = [];
	$check = static function(string $name, int $line) use (&$bad): void
	{
		$name = ltrim(str_replace('\\\\', '\\', $name), '\\');
		if(!preg_match('/^Shef\\\\ToolsAi(\\\\|$)/', $name))
		{
			return;
		}
		foreach(SEAM_ALLOWED as $allowed)
		{
			if(strcasecmp($name, $allowed) === 0)
			{
				return;
			}
		}
		$bad[] = ['line' => $line, 'name' => $name];
	};

	foreach(token_get_all($source) as $token)
	{
		if(!is_array($token))
		{
			continue;
		}
		[$id, $text, $line] = $token;
		if($id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED)
		{
			$check($text, $line);
		}
		elseif(($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE) && !$stringsOk)
		{
			if(preg_match_all('/Shef\\\\{1,2}ToolsAi(?:\\\\{1,2}[A-Za-z0-9_]+)+/', $text, $found))
			{
				foreach($found[0] as $name)
				{
					$check($name, $line);
				}
			}
		}
	}

	return $bad;
}

/** @return list<string> пути PHP-файлов (относительно корня) */
function seamFiles(string $root, array $dirs, array $rootGlobs): array
{
	$files = [];
	foreach($dirs as $dir)
	{
		if(!is_dir($root.'/'.$dir))
		{
			continue;
		}
		foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS)) as $file)
		{
			if(str_ends_with($file->getFilename(), '.php'))
			{
				$files[] = str_replace('\\', '/', substr((string)$file, strlen($root) + 1));
			}
		}
	}
	foreach($rootGlobs as $glob)
	{
		foreach(glob($root.'/'.$glob) ?: [] as $file)
		{
			$files[] = basename($file);
		}
	}
	sort($files);

	return $files;
}

Check::group('разбор: образцы');

$sample = static fn(string $code, bool $strings = false): array => array_column(seamViolations("<?php\n".$code, $strings), 'name');
Check::same('use внутреннего класса — нарушение', $sample("use Shef\\ToolsAi\\Quota\\Ledger;"), ['Shef\\ToolsAi\\Quota\\Ledger']);
Check::same('вызов с полным именем — нарушение', $sample("\\Shef\\ToolsAi\\Container::getConfig();"), ['Shef\\ToolsAi\\Container']);
Check::same('new с полным именем — нарушение', $sample("new \\Shef\\ToolsAi\\Provider\\ProviderException('x');"), ['Shef\\ToolsAi\\Provider\\ProviderException']);
Check::same('имя класса в строке — нарушение', $sample("\$c = 'Shef\\\\ToolsAi\\\\Quota\\\\Meter';"), ['Shef\\ToolsAi\\Quota\\Meter']);
Check::same('имя класса в двойных кавычках — нарушение', $sample("\$c = \"Shef\\\\ToolsAi\\\\Config\";"), ['Shef\\ToolsAi\\Config']);
Check::same('имя в строке в установщике — допустимо', $sample("\$name = '\\\\Shef\\\\ToolsAi\\\\Agent\\\\AnalysisAgent::run(%';", true), []);
Check::same('но класс в коде установщика — нарушение', $sample("use Shef\\ToolsAi\\Quota\\Ledger;", true), ['Shef\\ToolsAi\\Quota\\Ledger']);
Check::same('LlmService и LlmResponse — разрешены', $sample("use Shef\\ToolsAi\\Public\\Service\\LlmService;\nuse Shef\\ToolsAi\\Public\\Service\\LlmResponse;"), []);
Check::same('таблицы архива чатов и писем — разрешены', $sample("use Shef\\ToolsAi\\Chat\\Model\\ChatAssessmentTable;\nuse Shef\\ToolsAi\\Email\\Model\\EmailTable;"), []);
Check::same('другой публичный сервис — не разрешён', $sample("use Shef\\ToolsAi\\Public\\Service\\RecordService;"), ['Shef\\ToolsAi\\Public\\Service\\RecordService']);
Check::same('своё пространство — не чужое', $sample("use Shef\\ToolsAiCrm\\Core\\Model;\n\$c = 'Shef\\\\ToolsAiCrm\\\\Container';"), []);
Check::same('комментарии не в счёт', $sample("// Shef\\ToolsAi\\Container\n/** @see \\Shef\\ToolsAi\\Quota\\Ledger */\n\$a = 1;"), []);
Check::same('номер строки нарушения', array_column(seamViolations("<?php\n\n\nnew \\Shef\\ToolsAi\\Config();"), 'line'), [4]);

Check::group('код модуля: только публичный API shef.toolsai');

$all = seamFiles($root, ['lib', 'activities'], ['*.php']);
$strict = array_values(array_diff($all, SEAM_LEGACY));
Check::same('файлы найдены (lib/ не пуст)', count($strict) > 50 && in_array('lib/container.php', $strict, true), true);
$bad = [];
foreach($strict as $path)
{
	foreach(seamViolations((string)file_get_contents($root.'/'.$path)) as $violation)
	{
		$bad[] = $path.':'.$violation['line'].' '.$violation['name'];
	}
}
Check::same('нет обращений к внутренним классам shef.toolsai (кроме разрешённых)', $bad, []);

$uses = [];
foreach($strict as $path)
{
	foreach(token_get_all((string)file_get_contents($root.'/'.$path)) as $token)
	{
		if(is_array($token) && ($token[0] === T_NAME_QUALIFIED || $token[0] === T_NAME_FULLY_QUALIFIED) && preg_match('/^\\\\?Shef\\\\ToolsAi\\\\/', $token[1]))
		{
			$uses[ltrim($token[1], '\\')] = true;
		}
	}
}
$used = array_keys($uses);
sort($used);
Check::same('движок задействован: сервис LlmService точно используется', in_array('Shef\\ToolsAi\\Public\\Service\\LlmService', $used, true), true);
Check::same('используется только разрешённое', array_values(array_diff($used, SEAM_ALLOWED)), []);

Check::group('установщик, настройки, публичные страницы, перенос: shef.toolsai — только строкой');

$soft = array_merge(seamFiles($root, ['install', 'settings', 'public'], []), array_values(array_intersect($all, SEAM_LEGACY)));
$bad = [];
foreach($soft as $path)
{
	foreach(seamViolations((string)file_get_contents($root.'/'.$path), true) as $violation)
	{
		$bad[] = $path.':'.$violation['line'].' '.$violation['name'];
	}
}
Check::same('классы shef.toolsai в коде (use, new, ::) не используются (файлов: '.count($soft).')', $bad, []);

Check::finish();
