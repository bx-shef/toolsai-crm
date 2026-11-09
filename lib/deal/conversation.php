<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Deal;

/**
 * Переписка записи для анализа (1.67.0): тексты последних писем (входящих и
 * исходящих) и чатов открытых линий — чтобы модель оценила не только риск,
 * но и работу менеджера в письмах и чатах, как по звонкам.
 *
 * До 1.67.0 письма и чаты разбирали отдельные агенты «Письма» и «Чаты», а в
 * сводку шли только их резюме. Теперь отдельных агентов нет (решение
 * владельца 2026-10-27): тексты собирает Deal\ConversationSource, эта часть
 * их ужимает под модель.
 *
 * Объём ограничен, как у звонков: свежие — первыми, у каждого письма и чата
 * свой потолок (EMAIL_CHARS / CHAT_CHARS), общий — $limit; не вошедшее —
 * строкой «ранее ещё N». В тексте — от старого к новому.
 *
 * Чистая логика, без ядра — conversation_test.php.
 */
final class Conversation
{
	public const DIRECTION_IN = 'in';
	public const DIRECTION_OUT = 'out';

	/** Сколько последних писем и чатов брать (ConversationSource). */
	public const EMAILS_LIMIT = 6;
	public const CHATS_LIMIT = 3;
	/** Потолок одного письма и одного чата, символов. */
	public const EMAIL_CHARS = 1200;
	public const CHAT_CHARS = 3000;
	/** Общий потолок блока, символов (~2–3 тыс. токенов). */
	public const LIMIT = 9000;
	/** Границы блока в сводке (DealFacts); из текстов писем и чатов вырезаются. */
	public const OPEN = '<переписка>';
	public const CLOSE = '</переписка>';

	/**
	 * @param list<array{at: int, direction?: string, subject?: string, text: string}> $emails
	 * @param list<array{at: int, subject?: string, text: string}> $chats
	 */
	public static function render(array $emails, array $chats, int $limit = self::LIMIT): string
	{
		$items = [];
		foreach($emails as $email)
		{
			$item = static::item($email, 'email', self::EMAIL_CHARS);
			if($item !== null)
			{
				$items[] = $item;
			}
		}
		foreach($chats as $chat)
		{
			$item = static::item($chat, 'chat', self::CHAT_CHARS);
			if($item !== null)
			{
				$items[] = $item;
			}
		}
		if($items === [])
		{
			return '';
		}

		// Свежие — первыми: так решаем, кому хватит места
		usort($items, static fn(array $a, array $b): int => $b['at'] <=> $a['at']);
		$blocks = [];
		$used = 0;
		$skipped = 0;
		foreach($items as $item)
		{
			$block = static::block($item);
			if($skipped > 0 || $used + mb_strlen($block) + 1 > $limit)
			{
				$skipped++;
				continue;
			}
			$blocks[] = $block;
			$used += mb_strlen($block) + 1;
		}
		$blocks = array_reverse($blocks);
		if($skipped > 0)
		{
			array_unshift($blocks, 'Ранее ещё писем и чатов: '.$skipped.' — не вошли по объёму.');
		}

		return implode("\n\n", $blocks);
	}

	/** @return array{kind: string, at: int, direction: string, subject: string, text: string}|null */
	private static function item(array $raw, string $kind, int $chars): ?array
	{
		$at = (int)($raw['at'] ?? 0);
		$text = trim(static::unmark((string)($raw['text'] ?? '')));
		if($at <= 0 || $text === '')
		{
			return null;
		}
		if(mb_strlen($text) > $chars)
		{
			$text = rtrim(mb_substr($text, 0, $chars - 1)).'…';
		}
		$direction = (string)($raw['direction'] ?? '');

		return [
			'kind' => $kind,
			'at' => $at,
			'direction' => in_array($direction, [self::DIRECTION_IN, self::DIRECTION_OUT], true) ? $direction : '',
			'subject' => trim((string)preg_replace('/\s+/u', ' ', static::unmark((string)($raw['subject'] ?? '')))),
			'text' => $text,
		];
	}

	/** Вырезает границы блока из текста клиента — письмо не закроет блок раньше времени. */
	public static function unmark(string $text): string
	{
		return (string)preg_replace('~<\s*/?\s*переписка\s*>~iu', '', $text);
	}

	private static function block(array $item): string
	{
		$subject = $item['subject'] !== '' ? ' «'.mb_substr($item['subject'], 0, 80).'»' : '';
		$title = $item['kind'] === 'chat'
			? 'Чат'.$subject
			: 'Письмо'.match($item['direction'])
			{
				self::DIRECTION_IN => ' от клиента',
				self::DIRECTION_OUT => ' менеджера клиенту',
				default => '',
			}.$subject;

		return '— '.$title.', '.date('d.m.Y H:i', $item['at']).":\n".$item['text'];
	}
}
