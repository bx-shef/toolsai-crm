<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Deal\Field;

/**
 * Чистая логика: добавить наши поля в конфигурацию карточки сделки (1.8.3).
 *
 * Конфигурация — то, что ядро хранит в CUserOptions «crm.entity.editor» /
 * «DEAL_DETAILS_C{N}_common» (ui/lib/form/EntityEditorConfiguration.php::set).
 * Два формата: список разделов [{type: section, name, title, elements}] или
 * колонки [{type: column, elements: [разделы]}] (crm/lib/Entity/EntityEditorConfig.php::set,
 * «compatibility mode»).
 *
 * Правила:
 *   - поле, которое уже есть в карточке в любом разделе, не трогаем;
 *   - недостающие поля дописываем в конец раздела SECTION («AI-анализ»);
 *   - раздела нет — создаём его в конце (последней колонки);
 *   - чужие разделы и поля не удаляем и не переставляем.
 * Без рантайма Битрикса — проверяется тестами.
 */
final class CardLayout
{
	public const SECTION = 'shef_ai_analysis';
	public const SECTION_TITLE = 'AI-анализ';

	/**
	 * @param array $config конфигурация карточки
	 * @param string[] $codes наши поля по порядку
	 * @return array{config: array, added: string[]}
	 */
	public static function merge(array $config, array $codes): array
	{
		$present = static::collectFieldNames($config);
		$missing = array_values(array_filter($codes, static fn(string $code): bool => !isset($present[$code])));
		if($missing === [])
		{
			return ['config' => $config, 'added' => []];
		}

		$elements = array_map(static fn(string $code): array => ['name' => $code], $missing);

		$hasColumns = static::hasColumns($config);
		$columns = $hasColumns ? $config : [['elements' => $config]];

		$done = false;
		foreach($columns as $c => $column)
		{
			foreach((array)($column['elements'] ?? []) as $s => $section)
			{
				if(is_array($section) && ($section['name'] ?? null) === static::SECTION)
				{
					$current = is_array($section['elements'] ?? null) ? array_values($section['elements']) : [];
					$columns[$c]['elements'][$s]['elements'] = array_merge($current, $elements);
					$done = true;
					break 2;
				}
			}
		}

		if(!$done)
		{
			$last = array_key_last($columns);
			if($last === null)
			{
				$columns = [['elements' => []]];
				$last = 0;
			}
			$current = is_array($columns[$last]['elements'] ?? null) ? array_values($columns[$last]['elements']) : [];
			$current[] = [
				'name' => static::SECTION,
				'title' => static::SECTION_TITLE,
				'type' => 'section',
				'elements' => $elements,
			];
			$columns[$last]['elements'] = $current;
		}

		return ['config' => $hasColumns ? $columns : $columns[0]['elements'], 'added' => $missing];
	}

	/**
	 * Строка для «Проверить и включить»: направления по итогу.
	 * @param int[] $added @param int[] $already @param int[] $default @param string[] $errors
	 */
	public static function report(array $added, array $already, array $default, array $errors): string
	{
		$list = static fn(array $ids): string => implode(', ', $ids);
		$parts = [];
		if($added !== []) { $parts[] = 'добавлены в направления '.$list($added); }
		if($already !== []) { $parts[] = 'уже были в '.$list($already); }
		if($default !== []) { $parts[] = 'штатная карточка (поля видны в «Дополнительно») в '.$list($default); }
		if($errors !== []) { $parts[] = 'ошибка: '.implode('; ', $errors); }

		return $parts === [] ? 'нечего делать' : implode('; ', $parts);
	}

	/** Все имена полей в карточке: name => true. */
	public static function collectFieldNames(array $config): array
	{
		$names = [];
		$sections = static::hasColumns($config)
			? array_merge(...array_map(static fn($column): array => is_array($column['elements'] ?? null) ? array_values($column['elements']) : [], array_values($config)))
			: $config;
		foreach($sections as $section)
		{
			foreach((is_array($section) && is_array($section['elements'] ?? null)) ? $section['elements'] : [] as $element)
			{
				if(is_array($element) && is_string($element['name'] ?? null))
				{
					$names[$element['name']] = true;
				}
			}
		}

		return $names;
	}

	private static function hasColumns(array $config): bool
	{
		foreach($config as $item)
		{
			if(is_array($item) && ($item['type'] ?? null) === 'column')
			{
				return true;
			}
		}

		return false;
	}
}
