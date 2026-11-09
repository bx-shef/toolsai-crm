<?php declare(strict_types=1);

/**
 * Страница модуля в публичной части (/shef/toolsaicrm/): разделы, права, движок,
 * проверка ввода — чистая логика, без ядра.
 *
 * Что держит:
 *
 * * разделы: порядок меню, файл каждого раздела лежит, адрес всегда с «?section=»,
 *   чужое значение — раздел по умолчанию, отчёты и блоки статистики;
 * * права (Settings\SectionAccess): администратор — всё; reports.all — две статистики;
 *   prompts.read — промпты анализа; «Общие» и «Авто-анализ CRM» — только администратор;
 *   нет права — нет разделов; «править промпты» — отдельное действие;
 * * движок: shef.toolsai не установлен или старше Constants::CORE_MIN_VERSION — сообщение;
 * * проверка «Общих»: лимит и интервал в границах Config;
 * * адреса страниц лежат там, куда ведёт Constants (settings/page.php и т.д.);
 * * страницы модуля не берут классов shef.toolsai, кроме публичного API;
 * * тексты для людей: без «ИИ», названия модели и сравнения с Битрикс24.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiCrm\Main\Constants;
use Shef\ToolsAiCrm\Settings\CoreCheck;
use Shef\ToolsAiCrm\Settings\OptionsInput;
use Shef\ToolsAiCrm\Settings\SectionAccess;
use Shef\ToolsAiCrm\Settings\Sections;

Check::boot();

Check::group('разделы');

Check::same('разделы в порядке меню', array_keys(Sections::getList()), ['options', 'profiles', 'prompts', 'stats_checks', 'stats_managers']);
Check::same('названия', array_column(Sections::getList(), 'title'), ['Общие', 'Авто-анализ CRM', 'Промпты анализа', 'Анализ записей CRM', 'Оценка работы менеджеров']);
foreach(Sections::getList() as $code => $item)
{
	Check::same('файл раздела есть: '.$code, is_file($root.$item['file']), true);
}
Check::same('адрес раздела — от Constants::PUBLIC_URL', Sections::url('profiles'), '/shef/toolsaicrm/?section=profiles');
Check::same('адрес в слайдере', Sections::url('profiles', [], true), '/shef/toolsaicrm/?section=profiles&IFRAME=Y&IFRAME_TYPE=SIDE_SLIDER');
Check::same('параметры экранируются', Sections::url('stats_checks', ['from' => '2026-10-01', 'x' => 'a&b']), '/shef/toolsaicrm/?section=stats_checks&from=2026-10-01&x=a%26b');
Check::same('чужой раздел — по умолчанию', [Sections::resolve('../../etc'), Sections::resolve(null), Sections::resolve(['prompts']), Sections::resolve('quota'), Sections::resolve('calls')], ['options', 'options', 'options', 'options', 'options']);
Check::same('скрытые поля GET-формы', Sections::hiddenFields('prompts', true), ['section' => 'prompts', 'IFRAME' => 'Y', 'IFRAME_TYPE' => 'SIDE_SLIDER']);
Check::same('адрес настроек shef.toolsai: подключение модели и расход', [Sections::coreUrl('options'), Sections::coreUrl('quota', true)], ['/shef/toolsai/?section=options', '/shef/toolsai/?section=quota&IFRAME=Y&IFRAME_TYPE=SIDE_SLIDER']);
Check::same('отчёты — две статистики', array_values(array_filter(array_keys(Sections::getList()), [Sections::class, 'isReport'])), ['stats_checks', 'stats_managers']);
Check::same('блок отчёта по разделу', [Sections::statsBlock('stats_checks'), Sections::statsBlock('stats_managers'), Sections::statsBlock('options')], ['checks', 'managers', null]);
Check::same('карточки — все разделы', array_values(array_filter(array_keys(Sections::getList()), [Sections::class, 'hasCards'])), array_keys(Sections::getList()));
Check::same('раздел по умолчанию — «Общие»', Sections::DEFAULT, 'options');

Check::group('права: администратор');

$no = static fn(string $action): bool => false;
$admin = new SectionAccess(true, $no);
Check::same('все разделы', $admin->getSections(), array_keys(Sections::getList()));
Check::same('по умолчанию — общие', $admin->getDefaultSection(), 'options');
Check::same('править промпты и любое действие', [$admin->canEditPrompts(), $admin->can('whatever')], [true, true]);
Check::same('несуществующего раздела нет и у администратора', $admin->canSection('quota'), false);

Check::group('права: роли shef.toolsai');

$can = static fn(array $granted): SectionAccess => new SectionAccess(false, static fn(string $action): bool => in_array($action, $granted, true));
$boss = $can(['reports.view', 'reports.all']);
Check::same('«Статистика по всему порталу» — две статистики', $boss->getSections(), ['stats_checks', 'stats_managers']);
Check::same('по умолчанию — первый доступный, «Общие» закрыты', $boss->getDefaultSection(), 'stats_checks');
Check::same('«Общие» и профили — только администратору', [$boss->canSection('options'), $boss->canSection('profiles')], [false, false]);
$reader = $can(['prompts.read']);
Check::same('«Промпты: читать» — промпты анализа, без правки', [$reader->getSections(), $reader->canEditPrompts()], [['prompts'], false]);
$editor = $can(['prompts.read', 'prompts.edit']);
Check::same('«Промпты: править» даёт правку', [$editor->getSections(), $editor->canEditPrompts()], [['prompts'], true]);
Check::same('«Отчёты: смотреть» без «по всему порталу» статистик не открывает', $can(['reports.view'])->getSections(), []);
$none = $can([]);
Check::same('нет прав — нет разделов, страницы нет', [$none->getSections(), $none->hasAny(), $none->getDefaultSection()], [[], false, null]);
Check::same('есть хоть один раздел — страница открыта', [$boss->hasAny(), $reader->hasAny(), $admin->hasAny()], [true, true, true]);
Check::same('права для каждого раздела из SECTIONS — известные действия shef.toolsai', array_unique(array_values(SectionAccess::SECTIONS)), ['prompts.read', 'reports.all']);

Check::group('движок shef.toolsai');

$min = Constants::CORE_MIN_VERSION;
Check::same('нет модуля — сообщение с нужной версией', str_contains((string)CoreCheck::problem(false, ''), 'не установлен') && str_contains((string)CoreCheck::problem(false, ''), $min), true);
Check::same('версия ниже — сообщение «слишком старый»', str_contains((string)CoreCheck::problem(true, '1.95.9'), 'слишком старый'), true);
Check::same('версия не прочиталась — сообщение', CoreCheck::problem(true, '') !== null, true);
Check::same('минимальная — подходит', CoreCheck::problem(true, $min), null);
Check::same('новее — подходит', [CoreCheck::problem(true, '1.96.1'), CoreCheck::problem(true, '2.0.0'), CoreCheck::problem(true, '1.100.0')], [null, null, null]);
Check::same('«1.100.0» новее «1.96.0» (версии — числа, не строки)', CoreCheck::problem(true, '1.100.0'), null);

Check::group('«Общие»: проверка ввода');

Check::same('пусто — по умолчанию, ошибок нет', OptionsInput::validate(['DEAL_maxperrun' => '', 'DEAL_interval' => ' ']), []);
Check::same('в границах', OptionsInput::validate(['DEAL_maxperrun' => '500', 'DEAL_interval' => '10']), []);
Check::same('границы включительно', OptionsInput::validate(['DEAL_maxperrun' => '1', 'DEAL_interval' => '10080']), []);
Check::same('за границей — ошибка по каждой', array_keys(OptionsInput::validate(['DEAL_maxperrun' => '501', 'DEAL_interval' => '9'])), ['DEAL_maxperrun', 'DEAL_interval']);
Check::same('нуль и минус', array_keys(OptionsInput::validate(['DEAL_maxperrun' => '0', 'DEAL_interval' => '-5'])), ['DEAL_maxperrun', 'DEAL_interval']);
Check::same('не число', array_keys(OptionsInput::validate(['DEAL_maxperrun' => '5x', 'DEAL_interval' => '1e3'])), ['DEAL_maxperrun', 'DEAL_interval']);
Check::same('дробное — ошибка', array_keys(OptionsInput::validate(['DEAL_maxperrun' => '2.5'])), ['DEAL_maxperrun']);
Check::same('чужие коды не проверяются', OptionsInput::validate(['DEAL_enabled' => 'Y', 'DEAL_crmevents' => 'N']), []);
Check::same('интервал — в границах Config', [OptionsInput::LIMITS['DEAL_interval'][0], OptionsInput::LIMITS['DEAL_interval'][1]], [\Shef\ToolsAiCrm\Config::AGENT_INTERVAL_MIN, \Shef\ToolsAiCrm\Config::AGENT_INTERVAL_MAX]);

Check::group('адреса страниц ведут туда, где лежат файлы');

Check::same('страница, компонент и ajax — в settings/', [Constants::SETTINGS_MODULE_PAGE, Constants::COMPONENT_MODULE_PAGE, Constants::COMPONENT_AJAX_MODULE_PAGE], ['/settings/page.php', '/settings/component.php', '/settings/ajax.php']);
foreach([Constants::SETTINGS_MODULE_PAGE, Constants::COMPONENT_MODULE_PAGE, Constants::COMPONENT_AJAX_MODULE_PAGE] as $page)
{
	Check::same('файл есть: '.$page, is_file($root.$page), true);
}
Check::same('публичный адрес — тот же, что у разделов', str_starts_with(Sections::url('options'), Constants::PUBLIC_URL.'?section='), true);
Check::same('компонент страницы зовёт свой компонент', str_contains((string)file_get_contents($root.'/settings/page.php'), "'shef:toolsaicrm.settings'") && Constants::COMPONENT_NAME === 'shef:toolsaicrm.settings', true);

Check::group('страницы не берут классов shef.toolsai, кроме публичного API');

$files = array_merge(glob($root.'/settings/*.php'), glob($root.'/lib/settings/*.php'), glob($root.'/lib/ui/*.php'), [$root.'/options_conf.php']);
$foreign = [];
foreach($files as $file)
{
	$source = (string)file_get_contents($file);
	// Пространство ядра: Shef\ToolsAi\ (не Shef\ToolsAiCrm\); допустим только Public\Service
	preg_match_all('/Shef\\\\+ToolsAi\\\\+([A-Za-z\\\\]+)/', $source, $found);
	foreach($found[1] as $class)
	{
		if(!str_starts_with($class, 'Public\\'))
		{
			$foreign[basename($file)][] = $class;
		}
	}
}
Check::same('чужие классы ядра в страницах и обвязке', $foreign, []);
Check::same('публичный API — только AccessService и сервис справки', (static function() use ($files): array
{
	$used = [];
	foreach($files as $file)
	{
		preg_match_all('/Shef\\\\+ToolsAi\\\\+Public\\\\+([A-Za-z\\\\]+)/', (string)file_get_contents($file), $found);
		foreach($found[1] as $class)
		{
			$used[$class] = true;
		}
	}
	ksort($used);

	return array_keys($used);
})(), ['Service\\AccessService', 'Service\\KbLinkService']);

Check::group('тексты для людей');

$bad = [];
$texts = array_merge(glob($root.'/settings/*.php'), glob($root.'/lang/ru/*.php'), glob($root.'/lang/ru/settings/*.php'), glob($root.'/lib/settings/sections.php'), [$root.'/options_conf.php']);
foreach($texts as $file)
{
	$source = (string)file_get_contents($file);
	foreach(['ИИ', 'DeepSeek', 'GPT', 'ChatGPT', 'OpenAI', 'Claude', 'больше нет', 'теперь '] as $word)
	{
		if(str_contains($source, $word))
		{
			$bad[basename($file)][] = $word;
		}
	}
}
Check::same('нет «ИИ», названий моделей, «больше нет», «теперь»', $bad, []);

Check::finish();
