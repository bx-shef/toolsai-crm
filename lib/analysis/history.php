<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Analysis;

/**
 * История риска (1.9.0): чистая логика — что показать в поле «AI: история»
 * и что дать модели в сводку. Данные — Model\HistoryTable::getLast().
 *
 * Строка истории: ['at' => unix, 'risk' => int, 'why' => string,
 * 'action' => string, 'marked' => bool, 'extra' => array], от свежего к старому.
 */
final class History
{
	/** Сколько анализов показывать в поле карточки. */
	public const FIELD_LIMIT = 5;
	/** Сколько анализов давать модели. */
	public const PROMPT_LIMIT = 3;
	/** Хранить дней; старше — удаляет агент. */
	public const KEEP_DAYS = 180;
	/** Столько анализов подряд без отметок — «ничего не сделано». */
	public const IDLE_STREAK = 3;

	/** Сколько последних анализов подряд прошли без отметок. */
	public static function idleStreak(array $rows): int
	{
		$count = 0;
		foreach($rows as $row)
		{
			// «Оценить нечего» (риск -1, 1.14.7) — не анализ с рекомендациями:
			// серию не прерывает и в неё не идёт.
			if((int)($row['risk'] ?? 0) < 0)
			{
				continue;
			}
			if(!empty($row['marked']))
			{
				break;
			}
			$count++;
		}

		return $count;
	}

	/**
	 * Блок для модели. Пусто — анализов не было.
	 */
	public static function toPromptText(array $rows): string
	{
		$rows = array_slice(array_values($rows), 0, static::PROMPT_LIMIT);
		if($rows === [])
		{
			return '';
		}

		$lines = ['Прошлые анализы AI (от свежего к старому):'];
		foreach($rows as $i => $row)
		{
			if((int)$row['risk'] < 0)
			{
				$lines[] = sprintf('%d) %s: оценить было нечего', $i + 1, date('d.m.Y', (int)$row['at']));
				continue;
			}
			$lines[] = sprintf(
				'%d) %s: риск %d%%, отметки: %s. Почему: %s',
				$i + 1,
				date('d.m.Y', (int)$row['at']),
				(int)$row['risk'],
				!empty($row['marked']) ? 'есть' : 'нет',
				($row['why'] ?? '') !== '' ? $row['why'] : '—'
			);
		}
		$streak = static::idleStreak($rows);
		if($streak >= static::IDLE_STREAK)
		{
			$lines[] = sprintf('Внимание: %d анализа подряд никто не отметил, что работа сделана — рекомендации не выполняются.', $streak);
		}

		return implode("\n", $lines);
	}

	/** Текст поля «AI: история»: по строке на анализ, коротко. */
	public static function toFieldText(array $rows): string
	{
		$lines = [];
		foreach(array_slice(array_values($rows), 0, static::FIELD_LIMIT) as $row)
		{
			$lines[] = (int)$row['risk'] < 0
				? date('d.m.Y', (int)$row['at']).' — оценить нечего'
				: sprintf('%s — %d%%%s', date('d.m.Y', (int)$row['at']), (int)$row['risk'], !empty($row['marked']) ? ', отмечено' : '');
		}

		return implode("\n", $lines);
	}

	/** Были ли отметки (значения полей AckType) — для MARKED. */
	public static function isMarked(mixed $senior, mixed $manager): bool
	{
		return \Shef\ToolsAiCrm\Deal\Field\AckValue::parse($senior) !== null || \Shef\ToolsAiCrm\Deal\Field\AckValue::parse($manager) !== null;
	}
}
