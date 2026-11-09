<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Deal;

/**
 * Профиль анализа сделок: для какого направления и типа клиента, каким
 * промптом и по какой шкале действовать.
 *
 * Образец — скрипты речевой аналитики CRM (crm/lib/Copilot/CallAssessment,
 * CopilotCallAssessmentTable: TITLE, IS_ENABLED, PROMPT, LOW_BORDER /
 * HIGH_BORDER, тип клиента). Хранится в Model\DealProfileTable.
 *
 * Класс чистый — без ядра: строку таблицы разбирает fromRow(), форму —
 * fromInput(). Границы держатся строго, как у Verdict: риск-границы в 0-100,
 * HIGH не ниже LOW, типы клиента — только известные.
 */
final class Profile
{
	public const DEFAULT_IDLE_DAYS = 3;
	public const DEFAULT_REANALYZE_DAYS = 7;
	public const DEFAULT_LOW_BORDER = 50;
	public const DEFAULT_HIGH_BORDER = 70;
	public const DEFAULT_SORT = 100;
	/**
	 * Анализировать только живые сделки: последняя активность не старше
	 * стольких дней. 0 — без ограничения. С версии 1.4.0.
	 */
	public const DEFAULT_ACTIVE_DAYS = 60;
	public const ACTIVE_DAYS_MAX = 3650;

	/** Итог стадии (#408): любой, успех или провал — семантика стадии S/F ядра. */
	public const OUTCOME_ANY = '';
	public const OUTCOME_SUCCESS = 'S';
	public const OUTCOME_FAILURE = 'F';
	/** Столбец STAGES — коды стадий через запятую. */
	public const STAGES_MAX = 1000;

	/** Промпт длиннее — обрезается: это системный промпт, а не регламент. */
	public const PROMPT_MAX = 8000;

	/**
	 * @param int[] $clientTypes ClientType::*; пусто — любой тип клиента
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $title,
		public readonly bool $enabled,
		public readonly int $sort,
		public readonly int $categoryId,
		public readonly array $clientTypes,
		public readonly string $prompt,
		public readonly int $idleDays,
		public readonly int $reanalyzeDays,
		public readonly int $lowBorder,
		public readonly int $highBorder,
		public readonly int $seniorId,
		/** Живая сделка — активность не старше стольких дней; 0 — любая. */
		public readonly int $activeDays = self::DEFAULT_ACTIVE_DAYS,
		/**
		 * «Тотальный контроль»: при риске >= HIGH старший получает дело
		 * всегда, мнение модели (needSenior) не учитывается. По умолчанию
		 * выключен. С версии 1.8.0.
		 */
		public readonly bool $totalControl = false,
		/**
		 * Тип сущности (1.9.0): 2 — сделка (по умолчанию), 1 — лид,
		 * иначе смарт-процесс (его entityTypeId). categoryId — воронка этого типа.
		 */
		public readonly int $entityTypeId = 2,
		/**
		 * Тип клиента лида (1.11.0, LeadClientType): «новый/повторный» и
		 * «юрлицо/физлицо», '' — любой. Только у профиля лида; у остальных — ''.
		 */
		public readonly string $leadRepeat = LeadClientType::ANY,
		public readonly string $leadLegal = LeadClientType::ANY,
		/**
		 * «Не передавать переписку модели» (1.67.3): тексты писем и чатов
		 * (блок «Переписка» сводки) модели не уходят. По умолчанию — уходят.
		 */
		public readonly bool $noConversation = false,
		/**
		 * Стадии (#408): коды стадий воронки профиля, пусто — любая стадия.
		 * @var string[]
		 */
		public readonly array $stages = [],
		/** Итог (#408): '' — любой, 'S' — любая стадия успеха, 'F' — провала. Задан — стадии пусты. */
		public readonly string $stageSemantic = self::OUTCOME_ANY,
	)
	{
	}

	/** Строка таблицы (или то, что пришло из формы) -> профиль. */
	public static function fromRow(array $row): self
	{
		$low = static::int($row['LOW_BORDER'] ?? null, static::DEFAULT_LOW_BORDER, 0, 100);
		$entityTypeId = static::int($row['ENTITY_TYPE_ID'] ?? null, 2, 1);
		$isLead = $entityTypeId === \Shef\ToolsAiCrm\Analysis\EntityType::LEAD;
		$semantic = static::parseOutcome($row['STAGE_SEMANTIC'] ?? '');

		return new self(
			id: static::int($row['ID'] ?? null, 0, 0),
			title: mb_substr(trim(is_string($row['TITLE'] ?? null) ? $row['TITLE'] : ''), 0, 255),
			enabled: ($row['IS_ENABLED'] ?? 'N') === 'Y' || ($row['IS_ENABLED'] ?? null) === true,
			sort: static::int($row['SORT'] ?? null, static::DEFAULT_SORT, 0, 1_000_000),
			categoryId: static::int($row['CATEGORY_ID'] ?? null, 0, 0),
			clientTypes: ClientType::parseList($row['CLIENT_TYPES'] ?? ''),
			prompt: mb_substr(trim(is_string($row['PROMPT'] ?? null) ? $row['PROMPT'] : ''), 0, static::PROMPT_MAX),
			idleDays: static::int($row['IDLE_DAYS'] ?? null, static::DEFAULT_IDLE_DAYS, 0, 365),
			reanalyzeDays: static::int($row['REANALYZE_DAYS'] ?? null, static::DEFAULT_REANALYZE_DAYS, 1, 365),
			lowBorder: $low,
			// HIGH ниже LOW дал бы дело старшему при риске, при котором
			// менеджеру ещё ничего не положено. Подтягиваем к LOW.
			highBorder: max($low, static::int($row['HIGH_BORDER'] ?? null, static::DEFAULT_HIGH_BORDER, 0, 100)),
			seniorId: static::int($row['SENIOR_ID'] ?? null, 0, 0),
			activeDays: static::int($row['ACTIVE_DAYS'] ?? null, static::DEFAULT_ACTIVE_DAYS, 0, static::ACTIVE_DAYS_MAX),
			totalControl: ($row['TOTAL_CONTROL'] ?? 'N') === 'Y' || ($row['TOTAL_CONTROL'] ?? null) === true,
			entityTypeId: $entityTypeId,
			leadRepeat: $isLead ? LeadClientType::parseRepeat($row['LEAD_REPEAT'] ?? '') : LeadClientType::ANY,
			leadLegal: $isLead ? LeadClientType::parseLegal($row['LEAD_LEGAL'] ?? '') : LeadClientType::ANY,
			noConversation: ($row['NO_CONVERSATION'] ?? 'N') === 'Y' || ($row['NO_CONVERSATION'] ?? null) === true,
			stages: $semantic === self::OUTCOME_ANY ? static::parseStages($row['STAGES'] ?? '') : [],
			stageSemantic: $semantic,
		);
	}

	/**
	 * Поля формы -> поля таблицы и ошибки. Ошибка — значение, которое молча
	 * превратилось бы в другое: «70%» в границе, HIGH ниже LOW, пустое название.
	 *
	 * @return array{0: array<string, mixed>, 1: string[]}
	 */
	public static function fromInput(array $input): array
	{
		$errors = [];
		$number = static function(string $key, int $default, int $min, int $max) use ($input, &$errors): int
		{
			$raw = trim((string)($input[$key] ?? ''));
			if($raw === '')
			{
				return $default;
			}
			if(1 !== preg_match('/^\d+$/', $raw) || (int)$raw < $min || (int)$raw > $max)
			{
				$errors[] = $key;

				return $default;
			}

			return (int)$raw;
		};

		$title = trim((string)($input['TITLE'] ?? ''));
		if($title === '')
		{
			$errors[] = 'TITLE';
		}

		// «Где анализировать» (1.9.0): ключ «тип:воронка» из формы. Нет ключа —
		// прежняя форма с одним CATEGORY_ID: сделка.
		$entityTypeId = 2;
		$categoryFromKey = null;
		if(array_key_exists('ENTITY_KEY', $input))
		{
			$parsed = \Shef\ToolsAiCrm\Analysis\EntityType::parseKey($input['ENTITY_KEY']);
			if($parsed === null || !\Shef\ToolsAiCrm\Analysis\EntityType::isSupportedId($parsed[0]))
			{
				$errors[] = 'ENTITY_KEY';
			}
			else
			{
				[$entityTypeId, $categoryFromKey] = $parsed;
			}
		}

		$types = $input['CLIENT_TYPES'] ?? [];
		$types = is_array($types) ? $types : [$types];

		$fields = [
			'TITLE' => mb_substr($title, 0, 255),
			'IS_ENABLED' => ($input['IS_ENABLED'] ?? 'N') === 'Y' ? 'Y' : 'N',
			'SORT' => $number('SORT', static::DEFAULT_SORT, 0, 1_000_000),
			'CATEGORY_ID' => $number('CATEGORY_ID', 0, 0, PHP_INT_MAX),
			'CLIENT_TYPES' => ClientType::toList(ClientType::parseList(implode(',', array_map('strval', $types)))),
			// Общий промпт, открытый «Изменить» и сохранённый без правки, — не свой (1.67.0)
			'PROMPT' => static::ownPrompt((string)($input['PROMPT'] ?? '')),
			'IDLE_DAYS' => $number('IDLE_DAYS', static::DEFAULT_IDLE_DAYS, 0, 365),
			'REANALYZE_DAYS' => $number('REANALYZE_DAYS', static::DEFAULT_REANALYZE_DAYS, 1, 365),
			'LOW_BORDER' => $number('LOW_BORDER', static::DEFAULT_LOW_BORDER, 0, 100),
			'HIGH_BORDER' => $number('HIGH_BORDER', static::DEFAULT_HIGH_BORDER, 0, 100),
			'SENIOR_ID' => $number('SENIOR_ID', 0, 0, PHP_INT_MAX),
			'ACTIVE_DAYS' => $number('ACTIVE_DAYS', static::DEFAULT_ACTIVE_DAYS, 0, static::ACTIVE_DAYS_MAX),
			'TOTAL_CONTROL' => ($input['TOTAL_CONTROL'] ?? 'N') === 'Y' ? 'Y' : 'N',
			'NO_CONVERSATION' => ($input['NO_CONVERSATION'] ?? 'N') === 'Y' ? 'Y' : 'N',
			'ENTITY_TYPE_ID' => $entityTypeId,
			// Тип клиента лида (1.11.0) — только у профиля лида.
			'LEAD_REPEAT' => LeadClientType::ANY,
			'LEAD_LEGAL' => LeadClientType::ANY,
		];
		if($entityTypeId === \Shef\ToolsAiCrm\Analysis\EntityType::LEAD)
		{
			// Тип клиента сделки у лида не работает (ядро считает его по
			// истории сделок) — у лида свои признаки, CLIENT_TYPES пусто.
			$fields['CLIENT_TYPES'] = '';
			$fields['LEAD_REPEAT'] = LeadClientType::parseRepeat($input['LEAD_REPEAT'] ?? '');
			$fields['LEAD_LEGAL'] = LeadClientType::parseLegal($input['LEAD_LEGAL'] ?? '');
			foreach(['LEAD_REPEAT', 'LEAD_LEGAL'] as $key)
			{
				$raw = $input[$key] ?? '';
				if($raw !== '' && $raw !== $fields[$key])
				{
					$errors[] = $key;
				}
			}
		}
		// Стадии и итог (#408): итог задан — стадии не нужны.
		$stagesRaw = $input['STAGES'] ?? [];
		$stagesRaw = is_array($stagesRaw) ? implode(',', array_map('strval', $stagesRaw)) : (string)$stagesRaw;
		$fields['STAGE_SEMANTIC'] = static::parseOutcome($input['STAGE_SEMANTIC'] ?? '');
		if(($input['STAGE_SEMANTIC'] ?? '') !== '' && $fields['STAGE_SEMANTIC'] !== $input['STAGE_SEMANTIC'])
		{
			$errors[] = 'STAGE_SEMANTIC';
		}
		$stages = $fields['STAGE_SEMANTIC'] === self::OUTCOME_ANY ? static::parseStages($stagesRaw) : [];
		$fields['STAGES'] = implode(',', $stages);
		if($fields['STAGE_SEMANTIC'] === self::OUTCOME_ANY && count($stages) !== count(array_unique(array_filter(array_map('trim', explode(',', $stagesRaw)), 'strlen'))))
		{
			// Код стадии с чужими символами или список длиннее столбца — не молча терять
			$errors[] = 'STAGES';
		}
		if($categoryFromKey !== null)
		{
			$fields['CATEGORY_ID'] = $categoryFromKey;
		}

		if($fields['HIGH_BORDER'] < $fields['LOW_BORDER'])
		{
			$errors[] = 'HIGH_BORDER';
		}

		return [$fields, array_values(array_unique($errors))];
	}

	/** Профиль подходит любому типу клиента. У лида — свои признаки (1.11.0). */
	public function isAnyClient(): bool
	{
		if($this->entityTypeId === \Shef\ToolsAiCrm\Analysis\EntityType::LEAD)
		{
			return $this->leadRepeat === LeadClientType::ANY && $this->leadLegal === LeadClientType::ANY;
		}

		return $this->clientTypes === [];
	}

	/** Профиль на итог «успех» или «провал» — для закрытых записей (#408). */
	public function isOutcome(): bool
	{
		return $this->stageSemantic !== self::OUTCOME_ANY;
	}

	/** Итог стадии: 'S', 'F' или '' (любой, и всё незнакомое). */
	public static function parseOutcome(mixed $value): string
	{
		return in_array($value, [self::OUTCOME_SUCCESS, self::OUTCOME_FAILURE], true) ? $value : self::OUTCOME_ANY;
	}

	/**
	 * Коды стадий через запятую -> список без повторов. Код — как у ядра
	 * (NEW, C3:WON, DT128_5:PREPARATION); чужое и не влезающее в столбец — мимо.
	 *
	 * @return string[]
	 */
	public static function parseStages(mixed $value): array
	{
		if(!is_string($value))
		{
			return [];
		}
		$result = [];
		$length = 0;
		foreach(explode(',', $value) as $code)
		{
			$code = trim($code);
			if($code === '' || 1 !== preg_match('/^[A-Za-z0-9_:\-]{1,50}$/', $code) || in_array($code, $result, true))
			{
				continue;
			}
			$length += strlen($code) + ($result === [] ? 0 : 1);
			if($length > self::STAGES_MAX)
			{
				break;
			}
			$result[] = $code;
		}

		return $result;
	}

	private static function int(mixed $value, int $default, int $min, int $max = PHP_INT_MAX): int
	{
		if(is_int($value))
		{
			return max($min, min($max, $value));
		}
		if(is_string($value) && 1 === preg_match('/^\s*\d+\s*$/', $value))
		{
			return max($min, min($max, (int)$value));
		}

		return $default;
	}

	/** Свой промпт из формы: обрезан по пределу; совпал со стандартным (сделки, лида, смарт-процесса) — пусто, действует общий. */
	public static function ownPrompt(string $raw): string
	{
		$prompt = trim($raw);
		// Браузер шлёт переводы строк как \r\n; общий — стандартный промпт любого вида записи
		$norm = static fn(string $text): string => str_replace("\r\n", "\n", trim($text));
		foreach(\Shef\ToolsAiCrm\Analysis\Prompt\PromptTemplate::keys() as $key)
		{
			if($norm($prompt) === $norm(\Shef\ToolsAiCrm\Analysis\Prompt\PromptTemplate::defaults($key)->system))
			{
				return '';
			}
		}

		return mb_substr($prompt, 0, static::PROMPT_MAX);
	}
}
