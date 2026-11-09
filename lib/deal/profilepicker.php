<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Deal;

/**
 * Подбор профиля к сделке. Чистая функция — без ядра.
 *
 * Правила (решение владельца, bx-shef/toolsai#29):
 *   1) только включённые профили направления сделки;
 *   2) профиль с типом клиента сделки главнее профиля «любой»;
 *      тип не определился — подходит только «любой»;
 *   3) дальше — по SORT, потом по ID (меньше — раньше).
 *
 * Лид (1.11.0): воронки у лида нет, тип клиента сделки не работает —
 * профиль лида выбирается по своим признакам (LeadClientType): «новый /
 * повторный» и «юрлицо / физлицо», у каждого есть «любой». Профиль, где
 * совпало больше заданных признаков, главнее; «любой» по обоим — последний.
 * Признаков у лида нет (не собрали) — подходят только профили «любой».
 *
 * Стадия (#408): профиль со списком стадий главнее профиля на итог
 * (успех / провал — семантика стадии S/F), тот — главнее «любой стадии»;
 * ранг стадии сравнивается раньше типа клиента.
 *
 * Не подошёл ни один — null: сделку не анализируем и модель не зовём.
 */
final class ProfilePicker
{
	/**
	 * @param Profile[] $profiles
	 */
	public static function pick(array $profiles, int $categoryId, ?int $clientType, ?LeadClientType $leadClient = null, string $stageId = '', string $stageSemantic = ''): ?Profile
	{
		$matched = [];
		foreach($profiles as $profile)
		{
			if(!$profile->enabled || $profile->categoryId !== $categoryId)
			{
				continue;
			}
			$stageRank = static::stageRank($profile, $stageId, $stageSemantic);
			if($stageRank === null)
			{
				continue;
			}
			$before = count($matched);

			if($profile->entityTypeId === \Shef\ToolsAiCrm\Analysis\EntityType::LEAD)
			{
				$score = $leadClient !== null
					? $leadClient->match($profile->leadRepeat, $profile->leadLegal)
					: ($profile->isAnyClient() ? 0 : null);
				if($score !== null)
				{
					// 2 признака совпали — ранг 0, ни одного («любой») — ранг 2.
					$matched[] = [2 - $score, $profile];
				}
			}
			elseif($profile->isAnyClient())
			{
				$matched[] = [1, $profile];
			}
			elseif($clientType !== null && in_array($clientType, $profile->clientTypes, true))
			{
				$matched[] = [0, $profile];
			}
			if(count($matched) > $before)
			{
				// Стадия точнее типа клиента (#408): сначала ранг стадии, потом клиента.
				array_unshift($matched[$before], $stageRank);
			}
		}

		if($matched === [])
		{
			return null;
		}

		usort($matched, static fn(array $a, array $b): int => [$a[0], $a[1], $a[2]->sort, $a[2]->id] <=> [$b[0], $b[1], $b[2]->sort, $b[2]->id]);

		return $matched[0][2];
	}

	/**
	 * Ранг стадии профиля (#408): 0 — стадия записи в списке профиля,
	 * 1 — итог (успех / провал) совпал с семантикой стадии, 2 — любая стадия;
	 * null — профиль записи не подходит (стадия не известна; закрытая запись
	 * и профиль не на итог).
	 */
	public static function stageRank(Profile $profile, string $stageId, string $stageSemantic): ?int
	{
		return static::stageRankOf($profile->stages, $profile->stageSemantic, $stageId, $stageSemantic);
	}

	/**
	 * То же по полям правила — для правил скриптов звонков (CallScript\RulePicker).
	 *
	 * @param string[] $stages стадии правила; пусто — любая
	 * @param string $outcome итог правила: '', 'S', 'F'
	 */
	public static function stageRankOf(array $stages, string $outcome, string $stageId, string $stageSemantic): ?int
	{
		if($outcome !== Profile::OUTCOME_ANY)
		{
			return $stageSemantic !== '' && $stageSemantic === $outcome ? 1 : null;
		}
		if($stageSemantic !== '')
		{
			// Закрытую запись берёт только правило на её итог: правила открытых
			// (профиль — с его промптом и сроками) к ней не относятся.
			return null;
		}
		if($stages !== [])
		{
			return $stageId !== '' && in_array($stageId, $stages, true) ? 0 : null;
		}

		return 2;
	}

	/**
	 * Итоги (S/F), по которым в воронке есть включённый профиль, — закрытые
	 * записи этих итогов становятся кандидатами (#408, Candidates).
	 *
	 * @param Profile[] $profiles
	 * @return array<int, string[]> ID воронки => ['S'] / ['F'] / ['F', 'S']
	 */
	public static function getCategoryOutcomes(array $profiles): array
	{
		$result = [];
		foreach($profiles as $profile)
		{
			if($profile->enabled && $profile->isOutcome())
			{
				$result[$profile->categoryId][$profile->stageSemantic] = $profile->stageSemantic;
			}
		}
		foreach($result as $id => $list)
		{
			ksort($list);
			$result[$id] = array_values($list);
		}
		ksort($result);

		return $result;
	}

	/**
	 * Пора ли снова анализировать сделку по сроку ЕЁ профиля. Кандидатов
	 * выбирают по самому короткому сроку в направлении
	 * (getCategoryReanalyzeDays()), и без этой проверки сделка с профилем
	 * «раз в 30 дней» анализировалась бы — и оплачивалась — с частотой
	 * соседнего профиля «раз в день».
	 *
	 * Профиль на итог — один раз после закрытия: проверка раньше перехода в стадию.
	 *
	 * @param int|null $checkedAt время прошлой проверки (unix), null — не проверялась
	 * @param int|null $stageMovedAt когда запись перешла в текущую стадию (unix)
	 */
	public static function isDue(Profile $profile, ?int $checkedAt, int $now, ?int $stageMovedAt = null): bool
	{
		if($profile->isOutcome())
		{
			// Закрытая запись — один раз после перехода в стадию итога (#408).
			return $checkedAt === null || ($stageMovedAt !== null && $checkedAt < $stageMovedAt);
		}

		return $checkedAt === null || $checkedAt <= $now - $profile->reanalyzeDays * 86400;
	}

	/**
	 * Направления, по которым есть хоть один включённый профиль, и самый
	 * короткий срок повторного анализа по каждому — для выборки кандидатов.
	 *
	 * @param Profile[] $profiles
	 * @return array<int, int> ID направления => дней
	 */
	public static function getCategoryReanalyzeDays(array $profiles): array
	{
		$result = [];
		foreach($profiles as $profile)
		{
			// Профиль на итог открытые записи не берёт — его срок им не указ (#408)
			if(!$profile->enabled || $profile->isOutcome())
			{
				continue;
			}

			$result[$profile->categoryId] = min($result[$profile->categoryId] ?? PHP_INT_MAX, $profile->reanalyzeDays);
		}
		ksort($result);

		return $result;
	}

	/**
	 * Срок «живой» сделки по направлению — для выборки кандидатов (SQL):
	 * самый мягкий среди включённых профилей направления, чтобы выборка не
	 * отсекла сделку, которую взял бы другой профиль. Есть профиль без
	 * ограничения (0) — у направления ограничения нет. Точная проверка по
	 * профилю самой сделки — DealFacts::isAlive().
	 *
	 * @param Profile[] $profiles
	 * @return array<int, int> ID направления => дней, 0 — без ограничения
	 */
	public static function getCategoryActiveDays(array $profiles): array
	{
		$result = [];
		foreach($profiles as $profile)
		{
			if(!$profile->enabled)
			{
				continue;
			}

			if($profile->isOutcome())
			{
				continue;
			}
			$current = $result[$profile->categoryId] ?? null;
			$result[$profile->categoryId] = ($current === 0 || $profile->activeDays === 0) ? 0 : max($current ?? 0, $profile->activeDays);
		}
		ksort($result);

		return $result;
	}
}
