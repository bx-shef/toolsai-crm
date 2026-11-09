<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\CallScript;

use Shef\ToolsAiCrm\Analysis\EntityType;
use Shef\ToolsAiCrm\Deal\Profile;

/**
 * Правило скрипта оценки звонков (#408, часть B): к звонку записи этого типа,
 * воронки и стадии ядро берёт скрипт пользователя ASSESSMENT_ID.
 *
 * Чистый класс. Стадии и итог — как у профиля анализа (Deal\Profile:
 * parseStages(), parseOutcome()); CATEGORY_ID = ANY_CATEGORY — любая воронка типа.
 */
final class Rule
{
	public const ANY_CATEGORY = -1;
	public const DEFAULT_SORT = 100;

	/**
	 * @param string[] $stages
	 */
	public function __construct(
		public readonly int $id,
		public readonly bool $enabled,
		public readonly int $sort,
		public readonly int $entityTypeId,
		public readonly int $categoryId,
		public readonly array $stages,
		public readonly string $stageSemantic,
		public readonly int $assessmentId,
	)
	{
	}

	public static function fromRow(array $row): self
	{
		$semantic = Profile::parseOutcome($row['STAGE_SEMANTIC'] ?? '');
		$category = (int)($row['CATEGORY_ID'] ?? self::ANY_CATEGORY);

		return new self(
			id: max(0, (int)($row['ID'] ?? 0)),
			enabled: ($row['IS_ENABLED'] ?? 'Y') === 'Y' || ($row['IS_ENABLED'] ?? null) === true,
			sort: max(0, min(1_000_000, (int)($row['SORT'] ?? self::DEFAULT_SORT))),
			entityTypeId: max(0, (int)($row['ENTITY_TYPE_ID'] ?? EntityType::DEAL)),
			categoryId: $category < 0 ? self::ANY_CATEGORY : $category,
			// Стадии принадлежат воронке: у «любой воронки» только итог
			stages: $semantic === Profile::OUTCOME_ANY && $category >= 0 ? Profile::parseStages($row['STAGES'] ?? '') : [],
			stageSemantic: $semantic,
			assessmentId: max(0, (int)($row['ASSESSMENT_ID'] ?? 0)),
		);
	}

	/**
	 * Форма -> поля таблицы и ошибки.
	 *
	 * @return array{0: array<string, mixed>, 1: string[]}
	 */
	public static function fromInput(array $input): array
	{
		$errors = [];
		$entityTypeId = 0;
		$categoryId = self::ANY_CATEGORY;
		$place = (string)($input['PLACE'] ?? '');
		// «тип:воронка» или «тип:*» — любая воронка
		if(1 === preg_match('/^(\d+):\*$/', $place, $m))
		{
			$entityTypeId = (int)$m[1];
		}
		else
		{
			$parsed = EntityType::parseKey($place);
			if($parsed !== null)
			{
				[$entityTypeId, $categoryId] = $parsed;
			}
		}
		if($entityTypeId <= 0 || !EntityType::isSupportedId($entityTypeId))
		{
			$errors[] = 'PLACE';
		}

		$semanticRaw = (string)($input['STAGE_SEMANTIC'] ?? '');
		$semantic = Profile::parseOutcome($semanticRaw);
		if($semanticRaw !== '' && $semantic !== $semanticRaw)
		{
			$errors[] = 'STAGE_SEMANTIC';
		}
		$stagesRaw = $input['STAGES'] ?? [];
		$stagesRaw = is_array($stagesRaw) ? implode(',', array_map('strval', $stagesRaw)) : (string)$stagesRaw;
		$stages = $semantic === Profile::OUTCOME_ANY && $categoryId >= 0 ? Profile::parseStages($stagesRaw) : [];
		if($semantic === Profile::OUTCOME_ANY && $categoryId >= 0 && count($stages) !== count(array_unique(array_filter(array_map('trim', explode(',', $stagesRaw)), 'strlen'))))
		{
			$errors[] = 'STAGES';
		}

		$assessmentId = trim((string)($input['ASSESSMENT_ID'] ?? ''));
		if(1 !== preg_match('/^[1-9]\d{0,9}$/', $assessmentId))
		{
			$errors[] = 'ASSESSMENT_ID';
		}
		$sort = trim((string)($input['SORT'] ?? ''));
		if($sort !== '' && (1 !== preg_match('/^\d{1,7}$/', $sort) || (int)$sort > 1_000_000))
		{
			$errors[] = 'SORT';
		}

		return [[
			'IS_ENABLED' => ($input['IS_ENABLED'] ?? 'N') === 'Y' ? 'Y' : 'N',
			'SORT' => $sort === '' || in_array('SORT', $errors, true) ? self::DEFAULT_SORT : (int)$sort,
			'ENTITY_TYPE_ID' => $entityTypeId,
			'CATEGORY_ID' => $categoryId,
			'STAGES' => implode(',', $stages),
			'STAGE_SEMANTIC' => $semantic,
			'ASSESSMENT_ID' => in_array('ASSESSMENT_ID', $errors, true) ? 0 : (int)$assessmentId,
		], $errors];
	}

	/** Ключ места для формы: «тип:воронка» или «тип:*». */
	public function placeKey(): string
	{
		return $this->categoryId === self::ANY_CATEGORY ? $this->entityTypeId.':*' : EntityType::toKey($this->entityTypeId, $this->categoryId);
	}
}
