<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Deal;

/**
 * Тип клиента лида (1.11.0, решение владельца). Чистый класс — без ядра.
 *
 * У лида свои признаки, не те, что у сделки (ClientType): ядро считает тип
 * клиента по истории сделок компании или контакта, а у лида их обычно нет.
 *
 *   * новый / повторный: к лиду не привязан ни контакт, ни компания —
 *     новый; есть хоть один (COMPANY_ID, CONTACT_ID или любой из
 *     множественных контактов лида, CONTACT_IDS) — повторный;
 *   * юрлицо / физлицо: привязана компания ИЛИ заполнено поле «Название
 *     компании» (COMPANY_TITLE) — юрлицо; иначе физлицо. Одинаково для
 *     новых и повторных.
 *
 * Профиль лида хранит по каждому признаку «любой» (''), либо конкретное
 * значение: LEAD_REPEAT — new / repeat, LEAD_LEGAL — legal / person.
 */
final class LeadClientType
{
	public const ANY = '';
	public const NEW = 'new';
	public const REPEAT = 'repeat';
	public const LEGAL = 'legal';
	public const PERSON = 'person';

	public function __construct(
		public readonly bool $repeat,
		public readonly bool $legal,
	)
	{
	}

	/**
	 * Признаки по полям лида.
	 *
	 * @param int[] $contactIds множественные контакты лида (CONTACT_IDS)
	 */
	public static function resolve(int $companyId, int $contactId, array $contactIds, string $companyTitle): self
	{
		$hasContact = $contactId > 0;
		foreach($contactIds as $id)
		{
			if(is_numeric($id) && (int)$id > 0)
			{
				$hasContact = true;
				break;
			}
		}

		return new self(
			repeat: $companyId > 0 || $hasContact,
			legal: $companyId > 0 || trim($companyTitle) !== '',
		);
	}

	public function repeatCode(): string
	{
		return $this->repeat ? static::REPEAT : static::NEW;
	}

	public function legalCode(): string
	{
		return $this->legal ? static::LEGAL : static::PERSON;
	}

	/** Строка для сводки модели. */
	public function toPromptText(): string
	{
		return 'Клиент лида: '
			.($this->repeat ? 'повторный (к лиду привязан контакт или компания)' : 'новый (контакт и компания не привязаны)')
			.', '
			.($this->legal ? 'юрлицо (есть компания или название компании)' : 'физлицо');
	}

	/** Значение настройки профиля «новый/повторный»; незнакомое — «любой». */
	public static function parseRepeat(mixed $value): string
	{
		return in_array($value, [static::NEW, static::REPEAT], true) ? $value : static::ANY;
	}

	/** Значение настройки профиля «юр/физ»; незнакомое — «любой». */
	public static function parseLegal(mixed $value): string
	{
		return in_array($value, [static::LEGAL, static::PERSON], true) ? $value : static::ANY;
	}

	/**
	 * Подходит ли лид под настройки профиля, и насколько точно:
	 * null — не подходит; иначе число заданных (не «любой») признаков, 0-2.
	 * Чем больше — тем профиль точнее и главнее.
	 */
	public function match(string $repeat, string $legal): ?int
	{
		$score = 0;
		if($repeat !== static::ANY)
		{
			if($repeat !== $this->repeatCode())
			{
				return null;
			}
			$score++;
		}
		if($legal !== static::ANY)
		{
			if($legal !== $this->legalCode())
			{
				return null;
			}
			$score++;
		}

		return $score;
	}
}
