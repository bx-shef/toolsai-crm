<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\CallScript;

use Shef\ToolsAiCrm\Deal\ProfilePicker;

/**
 * Подбор правила скрипта к звонку (#408). Чистая функция.
 *
 * От точного к общему: тип записи; воронка записи главнее «любой воронки»;
 * стадия из списка → итог (успех / провал) → любая стадия (как у профиля:
 * ProfilePicker::stageRankOf(), закрытую запись берёт только правило на итог);
 * дальше SORT, ID. Правило со скриптом, которого нет среди доступных
 * (выключен, удалён), пропускается — берётся следующее.
 */
final class RulePicker
{
	/**
	 * @param Rule[] $rules
	 * @param int[] $availableScripts ID включённых скриптов CRM
	 */
	public static function pick(array $rules, int $entityTypeId, int $categoryId, string $stageId, string $stageSemantic, array $availableScripts): ?Rule
	{
		$matched = [];
		foreach($rules as $rule)
		{
			if(!$rule->enabled || $rule->entityTypeId !== $entityTypeId || !in_array($rule->assessmentId, $availableScripts, true))
			{
				continue;
			}
			if($rule->categoryId !== Rule::ANY_CATEGORY && $rule->categoryId !== $categoryId)
			{
				continue;
			}
			$stageRank = ProfilePicker::stageRankOf($rule->stages, $rule->stageSemantic, $stageId, $stageSemantic);
			if($stageRank === null)
			{
				continue;
			}
			$matched[] = [$rule->categoryId === Rule::ANY_CATEGORY ? 1 : 0, $stageRank, $rule];
		}
		if($matched === [])
		{
			return null;
		}
		usort($matched, static fn(array $a, array $b): int => [$a[0], $a[1], $a[2]->sort, $a[2]->id] <=> [$b[0], $b[1], $b[2]->sort, $b[2]->id]);

		return $matched[0][2];
	}

	/**
	 * Запись, к которой привязан звонок: владелец дела, если это сделка, лид
	 * или смарт-процесс; иначе из привязок — сделка, потом смарт-процесс,
	 * потом лид, среди одного типа — с большим ID. Контакт и компания — нет.
	 *
	 * @param array<int, array{OWNER_TYPE_ID?: mixed, OWNER_ID?: mixed}> $bindings
	 * @return array{0: int, 1: int}|null [entityTypeId, id]
	 */
	public static function pickOwner(int $ownerTypeId, int $ownerId, array $bindings): ?array
	{
		$rank = static fn(int $type): ?int => match(true)
		{
			$type === \Shef\ToolsAiCrm\Analysis\EntityType::DEAL => 0,
			$type >= 128 => 1,
			$type === \Shef\ToolsAiCrm\Analysis\EntityType::LEAD => 2,
			default => null,
		};
		if($ownerId > 0 && $rank($ownerTypeId) !== null)
		{
			return [$ownerTypeId, $ownerId];
		}
		$best = null;
		foreach($bindings as $binding)
		{
			$type = (int)($binding['OWNER_TYPE_ID'] ?? 0);
			$id = (int)($binding['OWNER_ID'] ?? 0);
			$r = $rank($type);
			if($id <= 0 || $r === null)
			{
				continue;
			}
			if($best === null || [$r, -$id] < [$best[2], -$best[1]])
			{
				$best = [$type, $id, $r];
			}
		}

		return $best === null ? null : [$best[0], $best[1]];
	}
}
