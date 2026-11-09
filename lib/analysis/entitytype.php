<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Analysis;

/**
 * Тип сущности CRM, которую разбирает блок анализа (1.9.0): сделка, лид,
 * смарт-процесс. Чистое описание — без ядра, проверяется тестом.
 *
 * Что здесь, и откуда это в ядре:
 *
 *   - entityTypeId — CCrmOwnerType: лид 1, сделка 2, смарт-процессы —
 *     свой ID типа (обычно от 128; счёт — 31). Литералами: класс чистый.
 *   - ufEntityId — ENTITY_ID пользовательских полей. Сделка `CRM_DEAL`,
 *     лид `CRM_LEAD` (CCrmOwnerType::ResolveUserFieldEntityID(),
 *     crm/classes/general/crm_owner_type.php:2794). У смарт-процесса —
 *     `CRM_{ID типа}`, где ID — запись b_crm_dynamic_type, а НЕ entityTypeId
 *     (crm/lib/Model/Dynamic/Factory.php:103 -> префикс `CRM_` + typeId).
 *     Поэтому для смарт-процесса его даёт рантайм: Factory::getUserFieldEntityId().
 *   - hasCategories — есть ли воронки. У лида нет (одна «воронка», 0).
 *   - noun — как назвать сущность в тексте для людей и модели.
 *
 * Возможности типа (1.9.1) — то, что у сделки действительно своё, а не
 * отдельный путь кода. Блок анализа спрашивает возможность, а не «сделка ли
 * это»:
 *
 *   - stageRollbacks — считать откаты по стадиям: история стадий есть
 *     только у сделки (crm DealStageHistoryTable);
 *   - closedFlag — у записи есть флаг CLOSED, и кандидаты отсекают
 *     CLOSED = 'Y' (сделка; у лида и смарт-процесса — по семантике стадии);
 *   - cardCategoryExtra — ключ воронки в настройке карточки
 *     (EntityEditorConfig): у сделки `DEAL_CATEGORY_ID`, у смарт-процесса
 *     `CATEGORY_ID`, у типа без воронок — нет;
 *   - feminine — род для текстов («Сделка 5 не найдена»).
 *
 * Тип клиента (по компании, иначе по контакту) — не возможность типа, а
 * поля записи: есть COMPANY_ID и CONTACT_ID — считаем (ContextBuilder).
 *
 * Вид шаблона промпта (promptKind()) — какой стандартный текст брать:
 * `deal`, `lead`, `dynamic`. Свой шаблон ищется по уровням (Prompt\PromptLevel).
 */
final class EntityType
{
	public const LEAD = 1;
	public const DEAL = 2;

	public const KEY_DEAL = 'deal';
	public const KEY_LEAD = 'lead';
	public const KEY_DYNAMIC = 'dynamic';

	private function __construct(
		public readonly int $entityTypeId,
		public readonly string $ufEntityId,
		public readonly bool $hasCategories,
		/** Родительный падеж: «анализ сделки», «анализ лида». */
		public readonly string $nounGenitive,
		/** Именительный, с заглавной: «Сделка #5», «Лид #5». */
		public readonly string $noun,
		/** Название для админки: «Сделки», «Лиды», «Смарт-процесс „Заявки“». */
		public readonly string $title,
		/** Откаты по стадиям в сводке (только сделка). */
		public readonly bool $stageRollbacks = false,
		/** Кандидаты отсекают CLOSED = 'Y' (только сделка). */
		public readonly bool $closedFlag = false,
		/** Ключ воронки в настройке карточки; '' — без воронки. */
		public readonly string $cardCategoryExtra = '',
		/** Женский род: «не найдена». */
		public readonly bool $feminine = false,
	)
	{
	}

	public static function deal(): self
	{
		return new self(self::DEAL, 'CRM_DEAL', true, 'сделки', 'Сделка', 'Сделки', stageRollbacks: true, closedFlag: true, cardCategoryExtra: 'DEAL_CATEGORY_ID', feminine: true);
	}

	public static function lead(): self
	{
		return new self(self::LEAD, 'CRM_LEAD', false, 'лида', 'Лид', 'Лиды');
	}

	/**
	 * @param int $entityTypeId CCrmOwnerType смарт-процесса
	 * @param string $ufEntityId ENTITY_ID его полей (Factory::getUserFieldEntityId())
	 */
	public static function dynamic(int $entityTypeId, string $ufEntityId, string $title = '', bool $hasCategories = true): self
	{
		$title = trim($title);

		return new self(
			$entityTypeId,
			$ufEntityId,
			$hasCategories,
			'элемента'.($title !== '' ? ' «'.$title.'»' : ''),
			'Элемент'.($title !== '' ? ' «'.$title.'»' : ''),
			'Смарт-процесс'.($title !== '' ? ' «'.$title.'»' : ' '.$entityTypeId),
			cardCategoryExtra: $hasCategories ? 'CATEGORY_ID' : '',
		);
	}

	public function isDeal(): bool
	{
		return $this->entityTypeId === self::DEAL;
	}

	public function isLead(): bool
	{
		return $this->entityTypeId === self::LEAD;
	}

	public function isDynamic(): bool
	{
		return !$this->isDeal() && !$this->isLead();
	}

	/** «Сделка 5 не найдена», «Лид 5 не найден». */
	public function notFoundText(int $id): string
	{
		return $this->noun.' '.$id.' не найден'.($this->feminine ? 'а' : '');
	}

	/** Вид стандартного шаблона промпта: deal, lead, dynamic. */
	public function promptKind(): string
	{
		return match(true)
		{
			$this->isDeal() => self::KEY_DEAL,
			$this->isLead() => self::KEY_LEAD,
			default => self::KEY_DYNAMIC,
		};
	}

	/**
	 * Наш ли это ENTITY_ID пользовательских полей: CRM_DEAL, CRM_LEAD или
	 * смарт-процесс CRM_{число}. Чистая функция — для обработчиков полей.
	 */
	public static function isAnalysisUfEntity(mixed $ufEntityId): bool
	{
		return is_string($ufEntityId) && 1 === preg_match('/^CRM_(DEAL|LEAD|\d+)$/', $ufEntityId);
	}

	/**
	 * Что за сущность по ENTITY_ID полей. Сделка и лид — сразу entityTypeId;
	 * смарт-процесс — ID ТИПА (b_crm_dynamic_type.ID), entityTypeId по нему
	 * найдёт рантайм (EntityTypes::fromUfEntityId()). Чистая функция.
	 *
	 * @return array{entityTypeId: int, dynamicTypeId: int}|null
	 */
	public static function parseUfEntity(mixed $ufEntityId): ?array
	{
		if(!static::isAnalysisUfEntity($ufEntityId))
		{
			return null;
		}

		return match($ufEntityId)
		{
			'CRM_DEAL' => ['entityTypeId' => self::DEAL, 'dynamicTypeId' => 0],
			'CRM_LEAD' => ['entityTypeId' => self::LEAD, 'dynamicTypeId' => 0],
			default => ['entityTypeId' => 0, 'dynamicTypeId' => (int)substr($ufEntityId, 4)],
		};
	}

	/**
	 * Ключ «тип:воронка» для формы профиля и обратно. Чистая функция.
	 * «2:0» — сделки, общее направление; «1:0» — лиды; «128:7» — смарт-процесс.
	 */
	public static function toKey(int $entityTypeId, int $categoryId): string
	{
		return $entityTypeId.':'.max(0, $categoryId);
	}

	/** @return array{0: int, 1: int}|null */
	public static function parseKey(mixed $key): ?array
	{
		if(!is_string($key) || 1 !== preg_match('/^(\d{1,9}):(\d{1,9})$/', trim($key), $m) || (int)$m[1] <= 0)
		{
			return null;
		}

		return [(int)$m[1], (int)$m[2]];
	}

	/** Поддерживает ли модуль такой тип: лид, сделка или смарт-процесс. */
	public static function isSupportedId(int $entityTypeId): bool
	{
		return $entityTypeId === self::LEAD || $entityTypeId === self::DEAL || $entityTypeId === 31 || $entityTypeId >= 128;
	}
}
