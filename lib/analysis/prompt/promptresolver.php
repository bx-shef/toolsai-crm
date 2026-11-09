<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Analysis\Prompt;

/**
 * Какой шаблон промпта действует на уровне (1.9.1): воронка -> тип ->
 * стандартный. Чистая функция над строками таблицы — её зовут агент
 * (через PromptStore::resolve()) и страница промптов, проверяет тест.
 */
final class PromptResolver
{
	/**
	 * @param array<string, array> $rows свои шаблоны: код уровня (PromptLevel::code()) => строка PromptTable
	 * @return array{template: PromptTemplate, source: ?PromptLevel} source — чей шаблон сработал; null — стандартный
	 */
	public static function resolve(PromptLevel $level, array $rows): array
	{
		foreach($level->chain() as $candidate)
		{
			$row = $rows[$candidate->code()] ?? null;
			if(is_array($row))
			{
				return ['template' => PromptStore::fromRow($level->kind, $row), 'source' => $candidate];
			}
		}

		return ['template' => PromptTemplate::defaults($level->kind), 'source' => null];
	}

	/**
	 * Как шаблон уровня выглядит для страницы: свой, унаследован (от какого
	 * уровня) или стандартный. Чистая функция.
	 *
	 * @param array<string, array> $rows
	 * @return array{state: string, source: ?PromptLevel} state: own | inherited | default
	 */
	public static function state(PromptLevel $level, array $rows): array
	{
		$source = static::resolve($level, $rows)['source'];

		return [
			'state' => $source === null ? 'default' : ($source->code() === $level->code() ? 'own' : 'inherited'),
			'source' => $source,
		];
	}
}
