<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Deal;

use Bitrix\Crm\ActivityBindingTable;
use Bitrix\Crm\ActivityTable;
use Bitrix\Main\Loader;
use Shef\ToolsAiCrm\Chat\DialogSource;
use Shef\ToolsAiCrm\Chat\Transcript;
use Shef\ToolsAiCrm\Email\EmailSource;
use Shef\ToolsAiCrm\Email\EmailText;

/**
 * Тексты писем и чатов записи для анализа (1.67.0) — штатными таблицами CRM:
 *
 *   дела записи        -> ActivityBindingTable + ActivityTable
 *   тело письма        -> Email\EmailSource::getBody() (сжатое — b_crm_act_mail_body),
 *                         текст — Email\EmailText::prepare() (без цитаты и подписи)
 *   служебные письма   -> Email\EmailText::serviceReason() — мимо (автоответ, недоставка)
 *   переписка чата     -> Chat\DialogSource::getMessages() по сессии (ASSOCIATED_ENTITY_ID
 *                         дела IMOPENLINES_SESSION), Chat\Transcript::build()
 *
 * Объём ужимает Conversation::render(). Права проверяет вызывающий (агент
 * анализа — служебный). Сбой одного письма или чата — без него; сбой целиком —
 * пусто, анализ идёт по остальной сводке.
 */
final class ConversationSource
{
	/** Сколько последних дел записи просматривать в поисках писем и чатов. */
	private const SCAN_LIMIT = 100;

	public static function collect(int $entityTypeId, int $id): string
	{
		if($id <= 0 || !Loader::includeModule('crm'))
		{
			return '';
		}
		$ids = array_map('intval', array_column(ActivityBindingTable::getList([
			'select' => ['ACTIVITY_ID'],
			'filter' => ['=OWNER_TYPE_ID' => $entityTypeId, '=OWNER_ID' => $id],
			'order' => ['ACTIVITY_ID' => 'DESC'],
			'limit' => self::SCAN_LIMIT,
		])->fetchAll(), 'ACTIVITY_ID'));
		if($ids === [])
		{
			return '';
		}
		$rows = ActivityTable::getList([
			'select' => ['ID', 'CREATED', 'START_TIME', 'TYPE_ID', 'DIRECTION', 'SUBJECT', 'DESCRIPTION', 'DESCRIPTION_TYPE', 'PROVIDER_ID', 'PROVIDER_TYPE_ID', 'ASSOCIATED_ENTITY_ID', 'SETTINGS'],
			// Только письма и чаты: тела звонков и задач не тянем (ревью #389)
			'filter' => ['@ID' => $ids, [
				'LOGIC' => 'OR',
				'=TYPE_ID' => \CCrmActivityType::Email,
				'=PROVIDER_ID' => DialogSource::PROVIDER_ID,
			]],
			'order' => ['CREATED' => 'DESC', 'ID' => 'DESC'],
		])->fetchAll();

		$emails = $chats = [];
		$imOpenLines = null;
		foreach($rows as $row)
		{
			try
			{
				if((int)$row['TYPE_ID'] === \CCrmActivityType::Email && count($emails) < Conversation::EMAILS_LIMIT)
				{
					$email = static::email($row);
					if($email !== null)
					{
						$emails[] = $email;
					}
				}
				elseif((string)$row['PROVIDER_ID'] === DialogSource::PROVIDER_ID && count($chats) < Conversation::CHATS_LIMIT)
				{
					$imOpenLines ??= Loader::includeModule('im') && Loader::includeModule('imopenlines');
					$chat = $imOpenLines ? static::chat($row) : null;
					if($chat !== null)
					{
						$chats[] = $chat;
					}
				}
			}
			catch(\Throwable)
			{
				// Письмо или чат не прочитались — без них
			}
		}

		return Conversation::render($emails, $chats);
	}

	/** @return array{at: int, direction: string, subject: string, text: string}|null */
	private static function email(array $row): ?array
	{
		$settings = is_array($row['SETTINGS'] ?? null) ? $row['SETTINGS'] : [];
		$from = $settings['EMAIL_META']['from'] ?? '';
		$incoming = (int)$row['DIRECTION'] === \CCrmActivityDirection::Incoming;
		if($incoming && EmailText::serviceReason(is_scalar($from) ? (string)$from : '', (string)$row['SUBJECT']) !== null)
		{
			return null;
		}
		[$body, $type] = EmailSource::getBody($row);
		$text = EmailText::prepare($body, $type, Conversation::EMAIL_CHARS);
		if($text === '')
		{
			return null;
		}

		return [
			'at' => EmailSource::toTs($row['START_TIME'] ?? null) ?? EmailSource::toTs($row['CREATED'] ?? null) ?? 0,
			'direction' => $incoming ? Conversation::DIRECTION_IN : Conversation::DIRECTION_OUT,
			'subject' => (string)$row['SUBJECT'],
			'text' => $text,
		];
	}

	/** @return array{at: int, subject: string, text: string}|null */
	private static function chat(array $row): ?array
	{
		$sessionId = (int)($row['ASSOCIATED_ENTITY_ID'] ?? 0);
		if($sessionId <= 0)
		{
			return null;
		}
		$transcript = Transcript::build(DialogSource::getMessages($sessionId), Conversation::CHAT_CHARS);
		if($transcript->text === '')
		{
			return null;
		}

		return [
			'at' => EmailSource::toTs($row['CREATED'] ?? null) ?? 0,
			'subject' => (string)$row['SUBJECT'],
			'text' => $transcript->text,
		];
	}
}
