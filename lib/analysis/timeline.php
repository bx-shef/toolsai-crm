<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Analysis;

/**
 * Единая история записи (1.45.0, #34): все касания сделки, лида или элемента
 * смарт-процесса одной лентой по времени — для анализа, чата справа и MCP.
 *
 * На входе — касания (собирает TimelineSource из дел CRM и сохранённых
 * результатов модуля), на выходе — текст для модели:
 *
 *   12.10.2026 14:05 · звонок входящий, 3 мин 20 с. Резюме: … Оценка по скрипту: 70%, не выполнено: …
 *   12.10.2026 15:40 · письмо клиенту «КП». Суть: …
 *
 * Объём под модель: свежие касания подробнее (DETAILED), следующие короче
 * (SHORT), остальные — одной строкой без содержания; общий потолок —
 * $limit символов, не вошедшее — строкой «ранее ещё N касаний». Ничего не
 * выдумываем: нет резюме — так и пишем.
 *
 * Чистая логика, без ядра — timeline_test.php.
 */
final class Timeline
{
	public const KIND_CALL = 'call';
	public const KIND_EMAIL = 'email';
	public const KIND_CHAT = 'chat';
	public const KIND_ACTIVITY = 'activity';
	public const KIND_COMMENT = 'comment';
	public const KIND_STAGE = 'stage';

	public const DIRECTION_IN = 'in';
	public const DIRECTION_OUT = 'out';

	/** Потолок истории по умолчанию, символов (~1–1,5 тыс. токенов). */
	public const LIMIT = 4000;
	/** Сколько свежих касаний — подробно, сколько следующих — коротко. */
	public const DETAILED = 5;
	public const SHORT = 10;
	/** Длина содержания: подробно / коротко. */
	public const TEXT_FULL = 400;
	public const TEXT_SHORT = 120;
	/** Невыполненных пунктов скрипта в подробной строке. */
	public const CRITERIA_LIMIT = 3;

	private const NOUNS = [
		self::KIND_CALL => 'звонков',
		self::KIND_EMAIL => 'писем',
		self::KIND_CHAT => 'чатов',
		self::KIND_ACTIVITY => 'дел',
		self::KIND_COMMENT => 'комментариев',
		self::KIND_STAGE => 'смен стадий',
	];

	/**
	 * Касание в допустимом виде; без времени или с неизвестным видом — null.
	 *
	 * @return array{kind: string, at: int, direction: string, duration: ?int, subject: string, summary: string, score: ?int, failed: list<string>, note: string}|null
	 */
	public static function touch(array $raw): ?array
	{
		$kind = (string)($raw['kind'] ?? '');
		$at = (int)($raw['at'] ?? 0);
		if($at <= 0 || !isset(self::NOUNS[$kind]))
		{
			return null;
		}
		$direction = (string)($raw['direction'] ?? '');
		$score = $raw['score'] ?? null;

		return [
			'kind' => $kind,
			'at' => $at,
			'direction' => in_array($direction, [self::DIRECTION_IN, self::DIRECTION_OUT], true) ? $direction : '',
			'duration' => isset($raw['duration']) && (int)$raw['duration'] > 0 ? (int)$raw['duration'] : null,
			'subject' => static::clean((string)($raw['subject'] ?? '')),
			'summary' => static::clean((string)($raw['summary'] ?? '')),
			// Резюме звонка нет (ядро его не сделало) — начало расшифровки, чтобы модель видела суть (#271)
			'transcript' => static::clean((string)($raw['transcript'] ?? '')),
			'score' => is_numeric($score) ? max(0, min(100, (int)$score)) : null,
			'failed' => array_values(array_filter(array_map(static fn($s): string => static::clean((string)$s), (array)($raw['failed'] ?? [])), static fn(string $s): bool => $s !== '')),
			'note' => static::clean((string)($raw['note'] ?? '')),
		];
	}

	/**
	 * История по времени (от старого к новому) в пределах $limit символов.
	 * Пусто — касаний нет.
	 *
	 * @param list<array> $touches касания в любом порядке (сырые — через touch())
	 */
	public static function render(array $touches, int $limit = self::LIMIT): string
	{
		$items = array_values(array_filter(array_map(static fn($t): ?array => is_array($t) ? static::touch($t) : null, $touches)));
		if($items === [])
		{
			return '';
		}
		// От свежего к старому: так решаем, кому сколько места
		usort($items, static fn(array $a, array $b): int => $b['at'] <=> $a['at']);

		$lines = [];
		$used = 0;
		$skipped = [];
		foreach($items as $i => $item)
		{
			$level = $i < self::DETAILED ? 2 : ($i < self::DETAILED + self::SHORT ? 1 : 0);
			$line = static::line($item, $level);
			if($skipped !== [] || $used + mb_strlen($line) + 1 > $limit)
			{
				// Подробная не влезла — пробуем короче, потом заголовком; дальше — только счёт
				$fallback = $skipped === [] && $level > 0 ? static::line($item, 0) : null;
				if($fallback !== null && $used + mb_strlen($fallback) + 1 <= $limit)
				{
					$lines[] = $fallback;
					$used += mb_strlen($fallback) + 1;
					continue;
				}
				$skipped[$item['kind']] = ($skipped[$item['kind']] ?? 0) + 1;
				continue;
			}
			$lines[] = $line;
			$used += mb_strlen($line) + 1;
		}

		$lines = array_reverse($lines);
		if($skipped !== [])
		{
			array_unshift($lines, static::skippedLine($skipped));
		}

		return implode("\n", $lines);
	}

	/**
	 * Одна строка касания. $level: 2 — подробно, 1 — коротко, 0 — без содержания.
	 */
	public static function line(array $item, int $level): string
	{
		$head = date('d.m.Y H:i', $item['at']).' · '.static::title($item);
		if($level === 0)
		{
			return $head.'.';
		}
		$length = $level === 2 ? self::TEXT_FULL : self::TEXT_SHORT;
		$parts = [];
		switch($item['kind'])
		{
			case self::KIND_CALL:
				$parts[] = $item['summary'] !== '' ? 'Резюме: '.static::cut($item['summary'], $length)
					: ($item['transcript'] !== '' ? 'Резюме нет, начало расшифровки: '.static::cut($item['transcript'], $length) : 'Резюме нет.');
				if($item['score'] !== null)
				{
					$score = 'Оценка по скрипту: '.$item['score'].'%';
					if($level === 2 && $item['failed'] !== [])
					{
						$score .= ', не выполнено: '.implode('; ', array_map(static fn(string $s): string => static::cut($s, 80), array_slice($item['failed'], 0, self::CRITERIA_LIMIT)));
					}
					$parts[] = $score.'.';
				}
				break;
			case self::KIND_EMAIL:
				$parts[] = $item['summary'] !== '' ? 'Суть: '.static::cut($item['summary'], $length) : 'Сути нет.';
				if($item['score'] !== null)
				{
					$parts[] = 'Оценка ответа: '.$item['score'].'%.';
				}
				break;
			case self::KIND_CHAT:
				$parts[] = $item['summary'] !== '' ? 'Суть: '.static::cut($item['summary'], $length) : 'Сути нет.';
				if($item['score'] !== null)
				{
					$parts[] = 'Оценка по скрипту: '.$item['score'].'%.';
				}
				break;
			case self::KIND_ACTIVITY:
			case self::KIND_COMMENT:
				if($item['note'] !== '')
				{
					$parts[] = static::cut($item['note'], $length);
				}
				break;
		}

		return $parts === [] ? $head.'.' : $head.'. '.implode(' ', array_map(static fn(string $p): string => static::dot($p), $parts));
	}

	/**
	 * Касания без содержания писем и чатов (1.67.3, профиль «Не передавать
	 * переписку модели»): тема, суть и оценка убраны, остаются вид, время и
	 * направление — порядок касаний модель видит, текста переписки нет.
	 *
	 * @param list<array> $touches
	 * @return list<array>
	 */
	public static function withoutMessaging(array $touches): array
	{
		return array_map(static fn(array $item): array => in_array($item['kind'] ?? '', [self::KIND_EMAIL, self::KIND_CHAT], true)
			? ['subject' => '', 'summary' => '', 'score' => null] + $item
			: $item, $touches);
	}

	/** «звонок входящий, 3 мин 20 с», «письмо клиенту «КП»», «стадия → «Счёт»». */
	public static function title(array $item): string
	{
		$in = $item['direction'] === self::DIRECTION_IN;
		$out = $item['direction'] === self::DIRECTION_OUT;
		$subject = $item['subject'] !== '' ? ' «'.static::cut($item['subject'], 80).'»' : '';

		return match($item['kind'])
		{
			self::KIND_CALL => 'звонок'.($in ? ' входящий' : ($out ? ' исходящий' : ''))
				.($item['duration'] !== null ? ', '.static::duration($item['duration']) : ''),
			self::KIND_EMAIL => 'письмо'.($in ? ' от клиента' : ($out ? ' клиенту' : '')).$subject,
			self::KIND_CHAT => 'чат'.$subject,
			self::KIND_COMMENT => 'комментарий',
			self::KIND_STAGE => 'стадия →'.($subject !== '' ? $subject : ' ?'),
			default => 'дело'.$subject,
		};
	}

	/** 200 → «3 мин 20 с», 45 → «45 с», 3600 → «60 мин». */
	public static function duration(int $seconds): string
	{
		$minutes = intdiv($seconds, 60);
		$rest = $seconds % 60;
		if($minutes === 0)
		{
			return $rest.' с';
		}

		return $minutes.' мин'.($rest > 0 ? ' '.$rest.' с' : '');
	}

	/** @param array<string, int> $skipped вид => сколько не вошло */
	private static function skippedLine(array $skipped): string
	{
		$total = array_sum($skipped);
		$parts = [];
		foreach(self::NOUNS as $kind => $noun)
		{
			if(isset($skipped[$kind]))
			{
				$parts[] = $noun.': '.$skipped[$kind];
			}
		}

		return 'Ранее ещё '.$total.' касаний ('.implode(', ', $parts).') — не вошли по объёму.';
	}

	private static function clean(string $text): string
	{
		return trim((string)preg_replace('/\s+/u', ' ', strip_tags($text)));
	}

	private static function cut(string $text, int $length): string
	{
		return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length - 1)).'…' : $text;
	}

	private static function dot(string $text): string
	{
		return preg_match('/[.!?…]$/u', $text) ? $text : $text.'.';
	}
}
