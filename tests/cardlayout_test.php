<?php declare(strict_types=1);

/**
 * Поля анализа в карточке сделки 1.8.3: чистое слияние конфигурации
 * (CardLayout::merge) и строка отчёта «Проверить и включить».
 *
 * Держит: раздел «AI-анализ» создаётся в конце, если его нет; поля, уже
 * стоящие где угодно, не дублируются и не переезжают; чужое не удаляется и
 * не переставляется; повторный вызов ничего не меняет; оба формата ядра —
 * список разделов и колонки.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

// Базовый тип поля ядра — только то, что нужно AckType (как в dealfields_test).
if(!class_exists(\Bitrix\Main\UserField\Types\StringType::class))
{
	eval(<<<'PHP'
	namespace Bitrix\Main\UserField\Types
	{
		abstract class BaseType {}
		class StringType extends BaseType {}
	}
	PHP);
}

use Shef\ToolsAiCrm\Deal\Field\CardLayout;
use Shef\ToolsAiCrm\Analysis\EntityFields;

$codes = EntityFields::getCodes();

Check::group('поля');

Check::same('девять полей UF_SHEF_AI_* (1.9.0 — история)', count($codes), 9);
Check::same('все с префиксом', array_filter($codes, static fn(string $c): bool => !str_starts_with($c, 'UF_SHEF_AI_')), []);

Check::group('список разделов');

$sections = [
	['name' => 'main', 'title' => 'О сделке', 'type' => 'section', 'elements' => [['name' => 'TITLE'], ['name' => 'STAGE_ID']]],
	['name' => 'additional', 'title' => 'Дополнительно', 'type' => 'section', 'elements' => [['name' => 'COMMENTS', 'optionFlags' => '1']]],
];
$r = CardLayout::merge($sections, $codes);
Check::same('добавлены все', $r['added'], $codes);
Check::same('чужие разделы на месте и по порядку', array_slice($r['config'], 0, 2), $sections);
Check::same('новый раздел — последним', [$r['config'][2]['name'], $r['config'][2]['title'], $r['config'][2]['type']], [CardLayout::SECTION, 'AI-анализ', 'section']);
Check::same('поля в разделе по порядку', array_column($r['config'][2]['elements'], 'name'), $codes);

$again = CardLayout::merge($r['config'], $codes);
Check::same('повтор — ничего не меняет', [$again['added'], $again['config']], [[], $r['config']]);

Check::group('поле уже есть где-то');

$moved = $sections;
$moved[0]['elements'][] = ['name' => EntityFields::RISK];
$r = CardLayout::merge($moved, $codes);
Check::same('риск не дублируется', in_array(EntityFields::RISK, $r['added'], true), false);
Check::same('риск остался в «О сделке»', $r['config'][0], $moved[0]);
Check::same('остальные восемь — в новом разделе', count($r['config'][2]['elements']), 8);

Check::group('раздел уже есть');

$withSection = $sections;
$withSection[] = ['name' => CardLayout::SECTION, 'title' => 'Мой заголовок', 'type' => 'section', 'elements' => [['name' => 'UF_OTHER'], ['name' => EntityFields::WHY]]];
$r = CardLayout::merge($withSection, $codes);
Check::same('заголовок раздела не меняем', $r['config'][2]['title'], 'Мой заголовок');
Check::same('дописываем в конец раздела, чужое на месте', array_column($r['config'][2]['elements'], 'name'), array_merge(['UF_OTHER', EntityFields::WHY], array_values(array_diff($codes, [EntityFields::WHY]))));
Check::same('второго раздела нет', count($r['config']), 3);

Check::group('колонки');

$columns = [
	['name' => 'default_column', 'type' => 'column', 'elements' => [$sections[0]]],
	['name' => 'right', 'type' => 'column', 'elements' => [$sections[1]]],
];
$r = CardLayout::merge($columns, $codes);
Check::same('колонки сохранены', [count($r['config']), $r['config'][0]], [2, $columns[0]]);
Check::same('раздел — в конце последней колонки', [$r['config'][1]['elements'][0], $r['config'][1]['elements'][1]['name']], [$sections[1], CardLayout::SECTION]);
$columns[0]['elements'][0]['elements'][] = ['name' => EntityFields::TODO];
Check::same('поле в любой колонке считается', isset(CardLayout::collectFieldNames($columns)[EntityFields::TODO]), true);
Check::same('повтор по колонкам — ничего', CardLayout::merge($r['config'], $codes)['added'], []);

Check::group('отчёт');

Check::same('строка', CardLayout::report([1, 3], [0], [5], []), 'добавлены в направления 1, 3; уже были в 0; штатная карточка (поля видны в «Дополнительно») в 5');
Check::same('пусто', CardLayout::report([], [], [], []), 'нечего делать');

Check::group('рантайм');

$fields = (string)file_get_contents($root.'/lib/analysis/entityfields.php');
Check::same('пишем только общую (COMMON) настройку', [str_contains($fields, 'EntityEditorConfigScope::COMMON'), str_contains($fields, "'_common'")], [true, true]);

Check::finish();
