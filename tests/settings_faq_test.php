<?php declare(strict_types=1);

/**
 * Справка «?» страницы модуля: Settings\Faq, settings/faqdata.php, Settings\KbLink.
 *
 * Что держит:
 *
 * * у каждого раздела, настройки «Общих», поля профиля и каждого «?» в разделах
 *   есть ответ «что это» и «зачем»; лишних ответов (к настройке, которой нет) тоже нет;
 * * ответ заполнен и лежит в существующем разделе; адрес статьи справки разбирается строго;
 * * значок «?» экранирует текст и ведёт на якорь; нет справки — нет значка;
 * * адрес сервиса справки — только документ «Базы знаний» своего портала.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiCrm\Settings\Faq;
use Shef\ToolsAiCrm\Settings\KbLink;
use Shef\ToolsAiCrm\Settings\Sections;

Check::boot();

Check::group('у каждой настройки есть ответ');

// Настройки «Общих» — из options_conf.php: вкладка и код опции, как их хранит Option (ВКЛАДКА_код).
// Строки-пояснения (RowInfo) — не настройки.
$source = (string)file_get_contents($root.'/options_conf.php');
preg_match_all("/new Options\\\\(\\w+)\\('(\\w+)'\\)/", $source, $match, PREG_SET_ORDER);
$optionKeys = [];
$tab = '';
foreach($match as [, $class, $code])
{
	if($class === 'Tab')
	{
		$tab = $code;
		continue;
	}
	if($class !== 'RowInfo')
	{
		$optionKeys[] = 'option.'.$tab.'_'.$code;
	}
}
Check::same('настройки «Общих»', $optionKeys, ['option.DEAL_enabled', 'option.DEAL_maxperrun', 'option.DEAL_interval', 'option.DEAL_crmevents']);

// Поля профиля — подписи SH_TOOLSAICRM_PROFILES_F_* в форме.
$profilesPage = (string)file_get_contents($root.'/settings/profiles.php');
preg_match_all("/<td class=\"shef-tai-l\"[^>]*><\\?=\\\$h\\(Loc::getMessage\\('SH_TOOLSAICRM_PROFILES_F_([A-Z_]+)'\\)\\)\\?>:/", $profilesPage, $fields);
$profileKeys = array_map(static fn(string $field): string => 'profile.'.strtolower($field), $fields[1]);
Check::same('полей профиля найдено', count($profileKeys), 16);

$sectionKeys = array_map(static fn(string $code): string => 'section.'.$code, array_keys(Sections::getList()));

// Каждый «?» в разделах — $help('ключ').
$helpKeys = [];
foreach(glob($root.'/settings/*.php') as $file)
{
	preg_match_all("/\\\$help\\('([a-z_.]+)'\\)/", (string)file_get_contents($file), $found);
	$helpKeys = array_merge($helpKeys, $found[1]);
}
$helpKeys = array_values(array_unique($helpKeys));
// В статистике «?» собирается из раздела: $help('section.'.$section)
$dynamic = array_map(static fn(string $code): string => 'section.'.$code, array_keys(Sections::STATS_PAGES));

$needed = array_values(array_unique(array_merge($sectionKeys, $optionKeys, $profileKeys, $helpKeys, $dynamic)));
$missing = array_values(array_filter($needed, static fn(string $key): bool => !Faq::has($key)));
Check::same('нет настройки без ответа', $missing, []);

$extra = array_values(array_diff(array_keys(Faq::getAll()), $needed));
Check::same('нет ответа к настройке, которой нет', $extra, []);
Check::same('скрипты звонков — ответ есть', Faq::has('section.callscripts'), true);

Check::group('ответы заполнены');

$bad = [];
foreach(Faq::getAll() as $key => $item)
{
	if(
		!isset(Sections::getList()[$item['section'] ?? ''])
		|| trim((string)($item['title'] ?? '')) === ''
		|| mb_strlen(trim((string)($item['what'] ?? ''))) < 10
		|| mb_strlen(trim((string)($item['why'] ?? ''))) < 5
	)
	{
		$bad[] = $key;
	}
}
Check::same('у каждого ответа раздел, название, «что это» и «зачем»', $bad, []);
Check::same('настройки — в «Общих», поля профиля — в «Авто-анализе CRM»', [
	array_values(array_unique(array_map(static fn(string $key): string => Faq::get($key)['section'], $optionKeys))),
	array_values(array_unique(array_map(static fn(string $key): string => Faq::get($key)['section'], $profileKeys))),
], [['options'], ['profiles']]);
Check::same('группы FAQ — в порядке меню', array_keys(Faq::bySection()), array_keys(Sections::getList()));
$badDocs = array_values(array_filter(array_keys(Faq::getAll()), static fn(string $key): bool => Faq::docTarget((string)(Faq::get($key)['doc'] ?? '')) === null));
Check::same('у каждого ответа статья справки задана верно', $badDocs, []);
Check::same('адрес статьи: /guide/slug#якорь', [Faq::docTarget('/guide/stats#анализ-записей'), Faq::docTarget('/guide/x'), Faq::docTarget('/shef/x'), Faq::docTarget('/guide/Bad')], [['stats.md', 'анализ-записей'], ['x.md', null], null, null]);

Check::group('значок «?» — статья «Вопросы и ответы» в «Базе знаний»');

Check::same('якорь ключа — без опасных символов', Faq::anchor('option.DEAL_enabled'), 'faq-option-DEAL_enabled');
Check::same('пакет справки — этого модуля', KbLink::PACKAGE, 'shef.toolsaicrm:guide');

// Нет shef.toolsaikb — значок с коротким ответом есть, «Подробнее» нет (toolsai#734, toolsai-crm#10)
KbLink::setResolver(null);
$bare = Faq::icon('option.DEAL_enabled');
$enabled = Faq::get('option.DEAL_enabled');
$attr = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
Check::same('нет shef.toolsaikb — «?» есть: кнопка, название, «что это» и «зачем» из faqdata, без «Подробнее»', [
	str_starts_with($bare, '<button type="button" class="shef-tai-q" aria-haspopup="dialog" aria-expanded="false"'),
	str_contains($bare, 'data-hint-title="'.$attr($enabled['title']).'"'),
	str_contains($bare, 'data-hint-what="'.$attr($enabled['what']).'"'),
	str_contains($bare, 'data-hint-why="'.$attr($enabled['why']).'"'),
	str_contains($bare, 'data-hint-more'),
], [true, true, true, true, false]);
Check::same('нет shef.toolsaikb — «Подробнее» простым текстом', [Faq::kbLink('/guide/engine', 'Как <это>'), Faq::kbUrl('/guide/engine'), KbLink::isAvailable()], ['Как &lt;это&gt;', null, false]);
$moreOf = static fn(string $icon): array => [$icon !== '', str_contains($icon, 'data-hint-more')];
KbLink::setResolver(static fn(string $path, ?string $heading): ?string => null);
Check::same('нет документа — «?» есть, «Подробнее» нет', $moreOf(Faq::icon('option.DEAL_enabled')), [true, false]);
KbLink::setResolver(static function(string $path, ?string $heading): ?string { throw new \RuntimeException('db'); });
Check::same('сбой сервиса — «?» есть, «Подробнее» нет, исключения нет', $moreOf(Faq::icon('option.DEAL_enabled')), [true, false]);
$filtered = [];
foreach(['//evil.example/x', "/\\evil.example", 'https://x', '/bitrix/admin/x', '/note/document/1/x', '/note/document/5/#a]b', '/note/document/5/#klyuch', '/note/document/5/'] as $candidate)
{
	KbLink::setResolver(static fn(string $path, ?string $heading): ?string => $candidate);
	$filtered[$candidate] = KbLink::url('faq.md', 'x');
}
Check::same('адрес сервиса — только /note/document/<id>/ и якорь note', $filtered, [
	'//evil.example/x' => null, "/\\evil.example" => null, 'https://x' => null, '/bitrix/admin/x' => null,
	'/note/document/1/x' => null, '/note/document/5/#a]b' => null, '/note/document/5/#klyuch' => '/note/document/5/#klyuch', '/note/document/5/' => '/note/document/5/',
]);

$calls = [];
KbLink::setResolver(static function(string $path, ?string $heading) use (&$calls): ?string {
	$calls[] = [$path, $heading];

	return '/note/document/'.($path === 'faq.md' ? 5 : 9).'/'.($heading !== null ? '#a'.count($calls) : '');
});
$icon = Faq::icon('option.DEAL_enabled');
Check::same('«Подробнее» у «?» — статья faq.md и заголовок вопроса', [$calls[0] ?? null, str_contains($icon, 'data-hint-more="/note/document/5/#a1"')], [['faq.md', 'Включить анализ'], true]);
Check::same('тексты подсказки — в атрибутах значка (на сенсорном экране title не виден), системного title нет', [str_contains($icon, 'data-hint-title="Включить анализ"'), str_contains($icon, 'data-hint-what="Главный выключатель'), str_contains($icon, 'data-hint-why="Анализ тратит'), str_contains($icon, ' title=')], [true, true, true, false]);
Check::same('нет ответа — нет значка', Faq::icon('option.NOPE'), '');
Faq::icon('option.DEAL_enabled');
Check::same('ответ сервиса кешируется на запрос', count($calls), 1);
$hintJs = (string)file_get_contents($root.'/lib/ui/hint.js');
Check::same('страница модуля подключает подсказку «?» (hint.js), в файле нет закрывающего script', [str_contains((string)file_get_contents($root.'/settings/component.php'), 'Tai::hintScript()'), \Shef\ToolsAiCrm\Ui\Tai::hintScript() === $hintJs, $hintJs !== '' && stripos($hintJs, '</script') === false], [true, true, true]);
Check::same('«Подробнее» подсказки — тот же a[data-kb], что у статьи справки; тексты подсказки — не innerHTML', [str_contains($hintJs, "'data-kb'"), str_contains($hintJs, 'innerHTML')], [true, false]);
// Тексты ответа и адрес — в атрибуты только через экранирование (после проверок счётчика вызовов сервиса выше)
KbLink::setResolver(null);
$data = new \ReflectionProperty(Faq::class, 'data');
$loaded = $data->getValue();
$data->setValue(null, ['option.XSS' => ['section' => 'options', 'title' => 'A"><b>', 'what' => "x' onmouseover='1", 'why' => '<script>1</script>']]);
$xss = Faq::icon('option.XSS');
$data->setValue(null, $loaded);
Check::same('атрибуты значка экранируются — кавычка и теги не выходят из атрибута', [str_contains($xss, '<b>'), str_contains($xss, '<script>'), str_contains($xss, "onmouseover='"), str_contains($xss, 'data-hint-title="A&quot;&gt;&lt;b&gt;"')], [false, false, false, true]);
KbLink::setResolver(null);

Check::group('заголовки вопросов');

$headings = Faq::headings();
$sectionHeadings = array_map([Faq::class, 'sectionHeading'], array_keys(Faq::bySection()));
Check::same('заголовки вопросов уникальны и не совпадают с группами разделов', [count(array_unique($headings)), array_values(array_intersect($headings, $sectionHeadings))], [count(Faq::getAll()), []]);

Check::finish();
