<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Analysis\Prompt;

use Shef\ToolsAiCrm\Analysis\EntityType;

/**
 * Уровень шаблона промпта анализа (1.9.1): где действует свой шаблон.
 *
 *   код               уровень
 *   deal              сделки, общий
 *   deal.c5           сделки, направление 5 (0 — общее направление)
 *   lead              лиды
 *   dynamic.1032      смарт-процесс 1032, общий
 *   dynamic.1032.c7   смарт-процесс 1032, воронка 7
 *
 * Поиск (PromptResolver): воронка -> тип -> стандартный из кода. Вид
 * стандартного — kind (EntityType::promptKind()). У типа без воронок (лид,
 * смарт-процесс без воронок) уровня воронки нет.
 *
 * Чистый класс — без ядра.
 */
final class PromptLevel
{
	private function __construct(
		/** deal, lead, dynamic — какой стандартный шаблон. */
		public readonly string $kind,
		/** entityTypeId: у смарт-процесса — свой, у сделки 2, у лида 1. */
		public readonly int $entityTypeId,
		/** Воронка; null — уровень типа. */
		public readonly ?int $categoryId,
	)
	{
	}

	/**
	 * Уровень для записи типа в воронке. $categoryId = null или тип без
	 * воронок — уровень типа.
	 */
	public static function of(EntityType $type, ?int $categoryId = null): self
	{
		return new self($type->promptKind(), $type->entityTypeId, $type->hasCategories && $categoryId !== null ? max(0, $categoryId) : null);
	}

	/** Код уровня (без префикса таблицы). */
	public function code(): string
	{
		$code = $this->kind === EntityType::KEY_DYNAMIC ? $this->kind.'.'.$this->entityTypeId : $this->kind;

		return $this->categoryId !== null ? $code.'.c'.$this->categoryId : $code;
	}

	/** Код -> уровень; мусор — null. */
	public static function parse(mixed $code): ?self
	{
		if(!is_string($code))
		{
			return null;
		}
		$code = trim($code);
		if(1 === preg_match('/^(deal|lead)(?:\.c(\d{1,9}))?$/', $code, $m))
		{
			$category = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : null;
			if($m[1] === EntityType::KEY_LEAD && $category !== null)
			{
				return null;
			}

			return new self($m[1], $m[1] === EntityType::KEY_LEAD ? EntityType::LEAD : EntityType::DEAL, $category);
		}
		if(1 === preg_match('/^dynamic\.(\d{1,9})(?:\.c(\d{1,9}))?$/', $code, $m) && EntityType::isSupportedId((int)$m[1]) && (int)$m[1] > EntityType::DEAL)
		{
			return new self(EntityType::KEY_DYNAMIC, (int)$m[1], isset($m[2]) && $m[2] !== '' ? (int)$m[2] : null);
		}

		return null;
	}

	public function isCategory(): bool
	{
		return $this->categoryId !== null;
	}

	/** Уровень выше: воронка -> тип; у типа — null (дальше стандартный). */
	public function parent(): ?self
	{
		return $this->categoryId !== null ? new self($this->kind, $this->entityTypeId, null) : null;
	}

	/**
	 * Цепочка поиска: этот уровень и выше.
	 *
	 * @return self[]
	 */
	public function chain(): array
	{
		$chain = [];
		for($level = $this; $level !== null; $level = $level->parent())
		{
			$chain[] = $level;
		}

		return $chain;
	}
}
