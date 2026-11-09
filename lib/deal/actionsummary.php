<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Deal;

/**
 * Что сделано по сделке за проверку, а если ничего — почему. Одна строка
 * для колонки «Эскалация» на странице расхода и для отчёта прогона
 * (cli/deal-health.php); хранится в EntityCheckTable.ACTION.
 *
 * Зачем: раньше колонка показывала только дату дела старшему, и сделка,
 * где менеджер дело получил, а старшего не звали, выглядела так, будто AI
 * ничего не сделал (bx-shef/toolsai#41). Чистая функция — без ядра.
 */
final class ActionSummary
{
	public const LABELS = [
		'manager_todo' => 'дело менеджеру',
		'todo' => 'дело старшему',
		'overdue_todo' => 'дело старшему о просрочке',
		'comment' => 'комментарий',
		'note' => 'комментарий «старшего не привлекаем»',
	];

	/**
	 * @param string[] $done что вернула Escalation::apply()
	 * @param int|null $managerTodoAt когда ставили дело менеджеру (unix), если недавно
	 * @param int|null $escalatedAt когда была эскалация (unix), если недавно
	 */
	public static function build(
		array $done,
		Verdict $verdict,
		Profile $profile,
		int $managerId,
		int $openActivities,
		?int $managerTodoAt = null,
		?int $escalatedAt = null,
	): string
	{
		$actions = [];
		foreach($done as $code)
		{
			$actions[] = static::LABELS[$code] ?? $code;
		}
		if($actions !== [])
		{
			return implode(', ', $actions);
		}

		return 'ничего: '.implode('; ', static::reasons($verdict, $profile, $managerId, $openActivities, $managerTodoAt, $escalatedAt));
	}

	/** Строка сбоя: «ошибка: дело менеджеру не поставлено — причина». */
	public static function error(string $what, string $reason): string
	{
		$reason = trim($reason);

		return mb_substr('ошибка: '.$what.' не поставлено'.($reason !== '' ? ' — '.$reason : ''), 0, 300);
	}

	/**
	 * Дописать сбои к строке «Эскалации»: сделанное остаётся, сбои — после.
	 * «ничего: дела не поставились» при сбое заменяется самим сбоем.
	 *
	 * @param string[] $errors Escalation::$errors
	 */
	public static function withErrors(string $action, array $errors): string
	{
		if($errors === [])
		{
			return $action;
		}
		if($action === 'ничего: дела не поставились' || $action === '')
		{
			return implode('; ', $errors);
		}

		return $action.'; '.implode('; ', $errors);
	}

	/**
	 * Почему ничего не поставлено.
	 *
	 * @return string[]
	 */
	public static function reasons(
		Verdict $verdict,
		Profile $profile,
		int $managerId,
		int $openActivities,
		?int $managerTodoAt = null,
		?int $escalatedAt = null,
	): array
	{
		if($verdict->skipped)
		{
			// С 1.14.7 причина сама начинается с «Пропуск:» / «Оценить нечего:».
			$reason = $verdict->skipReason !== '' ? $verdict->skipReason : 'без причины';
			return [preg_match('/^(Пропуск|Оценить нечего):/u', $reason) ? mb_strtolower(mb_substr($reason, 0, 1)).mb_substr($reason, 1) : 'пропуск — '.$reason];
		}
		if($verdict->risk < $profile->lowBorder)
		{
			return ['риск ниже порога '.$profile->lowBorder.'%'];
		}

		$reasons = [];
		$high = $verdict->risk >= $profile->highBorder;
		if($high && !$verdict->needSenior && !$profile->totalControl)
		{
			$reasons[] = 'старший не нужен по мнению модели';
		}
		if($high && ($verdict->needSenior || $profile->totalControl) && $escalatedAt !== null)
		{
			$reasons[] = 'старшему уже ставилось '.date('d.m.Y', $escalatedAt);
		}
		if($managerId <= 0)
		{
			$reasons[] = 'нет ответственного';
		}
		elseif($managerTodoAt !== null)
		{
			$reasons[] = 'дело менеджеру уже ставилось '.date('d.m.Y', $managerTodoAt);
		}
		elseif($openActivities > 0)
		{
			$reasons[] = 'есть запланированные дела';
		}

		return $reasons !== [] ? $reasons : ['дела не поставились'];
	}
}
