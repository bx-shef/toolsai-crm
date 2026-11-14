<?php declare(strict_types=1);

/**
 * Форма настроек «как у Битрикс24» (1.20.0, #96) — Ui\SettingsForm, без ядра.
 *
 * Что держит:
 *
 * * вкладки shef.options -> секции; поле на настройку с именем
 *   param_<ВКЛАДКА>_<код> — тем, что читает цикл сохранения страницы;
 * * типы: Checkbox -> переключатель (checked по Y), NumberInt -> число,
 *   TextArea, Enum -> выбор с текущим значением, RowInfo -> текст;
 * * подсказка «?» — из FAQ по полному коду;
 * * описание: ссылки и <br> остаются, остальной HTML экранируется,
 *   javascript: не проходит;
 * * настоящий options_conf.php в форме — в optionsconf_test.php (там заглушки ядра).
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/stub/options.php';
require_once $root.'/tests/assert.php';

use Shef\Options\Main\Options;
use Shef\ToolsAiCrm\Ui\SettingsForm;

Check::group('поля');

$tab = (new Options\Tab('DEF'))->setName('Движок')->setTitle('Свой движок')
	->addOption((new Options\RowInfo('About'))->setDescription('См. <a href="/shef/toolsai/?section=quota&amp;x=1">расход</a><br>и <script>alert(1)</script>'))
	->addOption((new Options\Checkbox('on'))->setTitle('Включено'))
	->addOption((new Options\NumberInt('quota'))->setTitle('Квота')->setDescription('<a href="javascript:alert(1)">x</a>'))
	->addOption((new Options\TextArea('crit'))->setTitle('Критерии'))
	->addOption((new Options\Enum('prov'))->setTitle('Провайдер')->setList(['echo' => 'Заглушка', 'openai' => 'OpenAI']))
	->addOption((new Options\Text('url'))->setTitle('Адрес'));

$values = ['DEF_on' => 'Y', 'DEF_quota' => '500', 'DEF_crit' => "a\nb", 'DEF_prov' => 'openai', 'DEF_url' => 'https://x.by'];
$form = SettingsForm::fromTabs([$tab], static fn(string $code): string => $values[$code] ?? '', static fn(string $code): string => $code === 'DEF_quota' ? 'Сколько можно потратить' : '');

Check::same('секция', [$form[0]['id'], $form[0]['title'], $form[0]['description']], ['DEF', 'Движок', 'Свой движок']);
Check::same('типы по порядку', array_column($form[0]['fields'], 'type'), ['info', 'checker', 'number', 'textarea', 'selector', 'text']);
Check::same('имена — как у shef.options', array_column($form[0]['fields'], 'name'), ['', 'param_DEF_on', 'param_DEF_quota', 'param_DEF_crit', 'param_DEF_prov', 'param_DEF_url']);
Check::same('переключатель включён по Y', $form[0]['fields'][1]['checked'], true);
Check::same('выключен по N', SettingsForm::fromTabs([$tab], static fn(string $c): string => 'N')[0]['fields'][1]['checked'], false);
Check::same('выбор — текущее значение отмечено', $form[0]['fields'][4]['items'], [
	['value' => 'echo', 'name' => 'Заглушка', 'selected' => false],
	['value' => 'openai', 'name' => 'OpenAI', 'selected' => true],
]);
Check::same('значение текста', $form[0]['fields'][5]['value'], 'https://x.by');
Check::same('подсказка из FAQ', $form[0]['fields'][2]['hint'], 'Сколько можно потратить');
$withPh = SettingsForm::withPlaceholders($form, ['DEF_url' => 'сейчас: https://a.by', 'DEF_quota' => 'сейчас: 5', 'DEF_on' => 'x', 'DEF_crit' => 'x']);
Check::same('#294: серый текст — текстовым и числовым полям, прочим нет', array_column($withPh[0]['fields'], 'placeholder', 'name'), ['param_DEF_quota' => 'сейчас: 5', 'param_DEF_url' => 'сейчас: https://a.by']);
Check::same('#294: JS отдаёт его полю ввода', str_contains(SettingsForm::script(), "input.placeholder = field.placeholder || ''"), true);
Check::same('#292: без значка — пусто', $form[0]['fields'][2]['hintIcon'], '');
$withIcon = SettingsForm::fromTabs([$tab], static fn(string $code): string => '', static fn(string $code): string => 'x', static fn(string $code): string => $code === 'DEF_quota' ? '<button type="button" class="shef-tai-q">?</button>' : '');
Check::same('значок «?» — готовый HTML кнопки по полному коду', [$withIcon[0]['fields'][2]['hintIcon'], $withIcon[0]['fields'][5]['hintIcon']], ['<button type="button" class="shef-tai-q">?</button>', '']);
Check::same('#292: JS ставит значок ссылкой, а не «?» текстом', str_contains(SettingsForm::script(), "htmlNode('shef-ui-row__q', field.hintIcon)"), true);

Check::group('описания');

$info = $form[0]['fields'][0]['html'];
Check::same('ссылка и <br> остаются', str_contains($info, '<a href="/shef/toolsai/?section=quota&amp;x=1" target="_top">расход</a><br>'), true);
Check::same('скрипт экранирован', str_contains($info, '<script>'), false);
Check::same('javascript: не ссылка', str_contains($form[0]['fields'][2]['description'], '<a '), false);

Check::same('«//host» не ссылка', str_contains(SettingsForm::safeHtml('<a href="//evil.by">x</a>'), '<a '), false);
Check::same('«/\\host» не ссылка', str_contains(SettingsForm::safeHtml('<a href="/\\evil.by">x</a>'), '<a '), false);
Check::same('data: не ссылка', str_contains(SettingsForm::safeHtml('<a href="data:text/html,1">x</a>'), '<a '), false);
Check::same('ссылка от парсера shef.options (лишние атрибуты) — ссылка, атрибуты прочь', SettingsForm::safeHtml('Эндпоинт движка: <a href="https://b24.x.by/endpoint.php" target="_blank" rel="nofollow" onclick="evil()">https://b24.x.by/endpoint.php</a>.'), 'Эндпоинт движка: <a href="https://b24.x.by/endpoint.php" target="_top">https://b24.x.by/endpoint.php</a>.');
Check::same('атрибуты без href — текст', SettingsForm::safeHtml('<a onclick="x()">y</a>'), '&lt;a onclick=&quot;x()&quot;&gt;y</a>');
Check::same('javascript: в href при лишних атрибутах — не ссылка', str_contains(SettingsForm::safeHtml('<a target="_blank" href="javascript:alert(1)">x</a>'), '<a '), false);
Check::same('https — ссылка', str_contains(SettingsForm::safeHtml('<a href="https://x.by/a?b=1&c=2">x</a>'), '<a href="https://x.by/a?b=1&amp;c=2" target="_top">'), true);

Check::group('сохранение');

$post = ['shef_ui_rendered' => 'Y', 'param_DEF_on' => 'N', 'param_DEF_url' => ' https://y.by ', 'param_DEF_prov' => 'echo'];
Check::same('только пришедшие поля', SettingsForm::collect([$tab], $post), ['DEF_on' => 'N', 'DEF_prov' => 'echo', 'DEF_url' => 'https://y.by']);
Check::same('форма не отрисована — ничего', SettingsForm::collect([$tab], ['param_DEF_url' => '']), []);
Check::same('переключатель Y', SettingsForm::collect([$tab], ['shef_ui_rendered' => 'Y', 'param_DEF_on' => 'Y']), ['DEF_on' => 'Y']);
Check::same('переключатель мусор — нет', SettingsForm::collect([$tab], ['shef_ui_rendered' => 'Y', 'param_DEF_on' => 'x']), ['DEF_on' => 'N']);

Check::group('JS передаёт имена полей ядру');

$js = SettingsForm::script();
Check::same('переключатель: inputName = имя поля', (bool)preg_match('/new BX\.UI\.Switcher\(\{\s*inputName: field\.name/', $js), true);
Check::same('переключатель: скрытое поле Y/N сразу, не пустое', str_contains($js, "switcher.inputNode.value = field.checked === true ? 'Y' : 'N'"), true);
Check::same('выбор, текст, многострочный: name = имя поля', str_contains($js, 'input.name = field.name;'), true);

Check::group('ключи API — полем-паролем, значение в страницу не уходит (1.64.5, #283)');

$secretTab = new Options\Tab('API');
$secretTab->setName('API')->setTitle('API')
	->addOption((new Options\Text('apikey'))->setTitle('Общий ключ API'))
	->addOption((new Options\Text('baseurl'))->setTitle('Адрес'));
$secretForm = SettingsForm::withSecrets(SettingsForm::fromTabs([$secretTab], static fn(string $code): string => ['API_apikey' => 'sk-live-1234567890abcd', 'API_baseurl' => 'https://api.x.by'][$code] ?? ''), ['API_apikey']);
Check::same('ключ: тип secret, значения нет, под полем «Сохранён: ••••abcd» (#527)', [$secretForm[0]['fields'][0]['type'], $secretForm[0]['fields'][0]['value'], $secretForm[0]['fields'][0]['secretState']], ['secret', '', 'Сохранён: ••••abcd']);
Check::same('ключа нет нигде в данных страницы', str_contains((string)json_encode($secretForm), 'sk-live'), false);
Check::same('прочие поля не тронуты', [$secretForm[0]['fields'][1]['type'], $secretForm[0]['fields'][1]['value']], ['text', 'https://api.x.by']);
Check::same('ключ не задан — «Не задан»', SettingsForm::withSecrets(SettingsForm::fromTabs([$secretTab], static fn(string $code): string => ''), ['API_apikey'])[0]['fields'][0]['secretState'], 'Не задан');
Check::same('#527: свой пуст, ключ наследуется — «Берётся из: Текст (••••e166)»', SettingsForm::withSecrets(SettingsForm::fromTabs([$secretTab], static fn(string $code): string => ''), ['API_apikey'], ['API_apikey' => 'Текст (••••e166)'])[0]['fields'][0]['secretState'], 'Берётся из: Текст (••••e166)');
Check::same('#527: свой ключ важнее унаследованного', SettingsForm::secretState('vibe_api_0000000000ce63', 'Текст (••••e166)'), 'Сохранён: ••••ce63');
Check::same('#527: короткий ключ тоже «Сохранён», без знаков', SettingsForm::secretState('asrkey'), 'Сохранён: ••••');
$ownWithSource = SettingsForm::withSecrets(SettingsForm::fromTabs([$secretTab], static fn(string $code): string => $code === 'API_apikey' ? 'sk-live-1234567890abcd' : ''), ['API_apikey'], ['API_apikey' => 'Текст (••••e166)'])[0]['fields'][0];
Check::same('#594: свой ключ при известном источнике — «Сохранён», подсказка «Новый ключ…», не пустой', [$ownWithSource['secretState'], str_starts_with($ownWithSource['placeholder'], 'Новый ключ'), $ownWithSource['secretEmpty']], ['Сохранён: ••••abcd', true, false]);
$noneField = SettingsForm::withSecrets(SettingsForm::fromTabs([$secretTab], static fn(string $code): string => ''), ['API_apikey'])[0]['fields'][0];
Check::same('#594: ключа нет нигде — «Вставьте ключ», флаг пустого для серого вида', [$noneField['placeholder'], $noneField['secretEmpty']], ['Вставьте ключ', true]);
Check::same('#594: JS красит серым по флагу, а не по тексту', [str_contains((string)file_get_contents(dirname(__DIR__).'/lib/ui/settingsform.js'), 'field.secretEmpty === true'), str_contains((string)file_get_contents(dirname(__DIR__).'/lib/ui/settingsform.js'), "'Не задан'")], [true, false]);
Check::same('короткий ключ — маска без знаков', SettingsForm::mask('abc'), '••••');
Check::same('сохранение: пусто — ключ не трогаем, «-» — удалить, прочее — как пришло', [
	SettingsForm::keepSecrets(['API_apikey' => '', 'API_baseurl' => ''], ['API_apikey']),
	SettingsForm::keepSecrets(['API_apikey' => '-'], ['API_apikey']),
	SettingsForm::keepSecrets(['API_apikey' => 'sk-new'], ['API_apikey']),
], [['API_baseurl' => ''], ['API_apikey' => ''], ['API_apikey' => 'sk-new']]);
Check::same('JS: поле-пароль и кнопка «Показать»', [str_contains(SettingsForm::script(), "input.type = 'password'"), str_contains(SettingsForm::script(), "'Показать'")], [true, true]);

Check::group('карточки (1.64.0)');

$meta = SettingsForm::withMeta($form, ['DEF' => ['--o-mail', '/note/document/4/']]);
Check::same('иконка и справка по коду вкладки', [$meta[0]['icon'], $meta[0]['help']], ['--o-mail', '/note/document/4/']);
Check::same('нет записи — шестерёнка, без справки', [SettingsForm::withMeta($form, [])[0]['icon'], SettingsForm::withMeta($form, [])[0]['help']], ['--o-settings', null]);
Check::same('кривой класс иконки — шестерёнка', SettingsForm::withMeta($form, ['DEF' => ['x" onclick="y', null]])[0]['icon'], '--o-settings');
Check::same('стиль карточек на месте', str_contains(SettingsForm::style(), '.shef-ui-card__head'), true);
Check::same('штатных ui.section и ui.form-elements больше не грузим', array_values(array_intersect(SettingsForm::extensions(), ['ui.section', 'ui.form-elements.view'])), []);
Check::same('JS формы на месте', str_contains(SettingsForm::script(), 'window.ShefUiSettingsForm'), true);

Check::finish();
