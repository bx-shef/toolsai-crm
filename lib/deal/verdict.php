<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Deal;

/**
 * Вердикт по здоровью сделки.
 *
 * Ответ модели разбирается терпимо, но границы держатся строго: риск за
 * пределами 0-100 подрезается, длинный текст обрезается, needSenior — только
 * настоящий true, а не строка «false», которая при (bool) стала бы true.
 */
final class Verdict
{
	private function __construct(
		public readonly int $risk,
		public readonly bool $needSenior,
		public readonly string $why,
		public readonly string $nextStep,
		public readonly bool $skipped = false,
		public readonly string $skipReason = '',
		/**
		 * Свои поля ответа из шаблона промпта (1.9.0): имя => строка.
		 * Сохраняются в истории и показываются, на шкалу не влияют.
		 */
		public readonly array $extra = [],
		/**
		 * Оценить нечего (1.14.7): нет звонков, комментариев, писем и
		 * откатов. Пропуск без модели, но поля записи пишутся: риск -1
		 * (NOTHING_RISK), «почему» — причина.
		 */
		public readonly bool $nothingToAssess = false,
		/**
		 * Оценка работы менеджера, % (1.67.0): звонки, письма и чаты по сводке.
		 * null — модель не оценила (нет переписки и звонков или ответ без поля).
		 */
		public readonly ?int $managerScore = null,
		/** Что менеджер делает хорошо и что не так в звонках, письмах и чатах (1.67.0). */
		public readonly string $managerReview = '',
	)
	{
	}

	/**
	 * @param string[] $extraKeys свои поля ответа (шаблон промпта, 1.9.0)
	 */
	public static function fromArray(array $raw, array $extraKeys = []): self
	{
		$risk = $raw['risk'] ?? 0;
		// «todo» — синоним nextStep: так поле называется в карточке.
		$next = $raw['nextStep'] ?? $raw['todo'] ?? null;

		$extra = [];
		foreach($extraKeys as $key)
		{
			$value = $raw[$key] ?? null;
			if(is_scalar($value) && trim((string)$value) !== '')
			{
				$extra[$key] = mb_substr(static::plain(trim(is_bool($value) ? ($value ? 'да' : 'нет') : (string)$value)), 0, 500);
			}
		}

		// Оценка менеджера (1.67.0): -1 или не число — «не оценивал»
		$score = $raw['managerScore'] ?? null;
		$score = is_numeric($score) && (float)$score >= 0 ? min(100, (int)round((float)$score)) : null;
		$review = $raw['managerReview'] ?? null;

		return new self(
			risk: is_numeric($risk) ? max(0, min(100, (int)round((float)$risk))) : 0,
			needSenior: ($raw['needSenior'] ?? false) === true,
			why: mb_substr(is_string($raw['why'] ?? null) ? static::plain($raw['why']) : '', 0, 500),
			nextStep: mb_substr(is_string($next) ? static::plain($next) : '', 0, 300),
			extra: $extra,
			managerScore: $score,
			managerReview: mb_substr(is_string($review) ? static::plain($review) : '', 0, 500),
		);
	}

	/**
	 * Текст модели без BB-кода (1.67.0, ревью #389): в сводке теперь тексты
	 * писем клиента, и ответ модели не должен приносить в комментарий CRM
	 * ссылки и картинки вида [url=…], [img].
	 */
	public static function plain(string $text): string
	{
		return trim((string)preg_replace('~\[/?(?:url|img|video|audio|user|disk|file|code|quote|b|i|u|s|color|size|font|list|\*|table|tr|td|th|p|left|right|center|justify)(?:[=\s][^\]]*)?\]~iu', '', $text));
	}

	/**
	 * Строка об оценке менеджера для комментариев и дел (1.67.0); пусто —
	 * модель работу менеджера не оценила.
	 */
	public function managerLine(): string
	{
		if($this->managerScore === null && $this->managerReview === '')
		{
			return '';
		}

		return 'Работа менеджера: '.($this->managerScore !== null ? $this->managerScore.'%' : 'без оценки')
			.($this->managerReview !== '' ? ' — '.$this->managerReview : '');
	}

	/** Поля оценки менеджера для истории (EXTRA) — те же ключи, что в ответе модели. */
	public function managerExtra(): array
	{
		return array_filter(['managerScore' => $this->managerScore, 'managerReview' => $this->managerReview], static fn($v): bool => $v !== null && $v !== '');
	}

	/** Риск в полях записи, когда оценить нечего (1.14.7). Пусто — не анализировалась. */
	public const NOTHING_RISK = -1;

	public static function skipped(string $reason): self
	{
		return new self(0, false, '', '', true, $reason);
	}

	/** Оценить нечего (1.14.7): пропуск без модели с риском -1 в полях. */
	public static function nothingToAssess(string $reason): self
	{
		return new self(self::NOTHING_RISK, false, $reason, '', true, $reason, [], true);
	}

	/** Показ риска людям: -1 — «−1 (оценить нечего)». */
	public static function riskText(int $risk): string
	{
		return $risk === self::NOTHING_RISK ? '−1 (оценить нечего)' : (string)$risk;
	}

	/** Звать ли старшего при таком пороге. */
	public function shouldEscalate(int $threshold): bool
	{
		return !$this->skipped && $this->needSenior && $this->risk >= $threshold;
	}
}
