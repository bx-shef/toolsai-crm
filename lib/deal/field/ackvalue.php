<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Deal\Field;

/**
 * Значение поля-отметки («Риск под контролем» / «Отработано»). Чистые
 * функции — без ядра.
 *
 * Формат (с 1.8.1):
 *   ""                    — риска нет (ниже LOW), отметка не нужна;
 *   "pending|<ID>"        — ждёт отметки адресата <ID> (0 — адресат неизвестен);
 *   "<ID>|<unix-время>"   — отмечено: кто и когда (формат 1.8.0, читается как раньше);
 *   "<ID>|<время>|admin"  — отметил администратор за адресата (с 1.8.2).
 *
 * Почему не пусто, пока ждём отметки: карточка CRM для ПУСТОГО значения
 * пользовательского поля HTML у типа не запрашивает и сразу пишет
 * «не заполнено» (ui/install/js/ui/entity-editor/js/user-field.js,
 * layout() -> hasContentToDisplay() -> checkIfNotEmpty(), IS_EMPTY от
 * crm/lib/Service/EditorAdapter/Normalizer/UserFieldValueNormalizer.php).
 * Кнопка поэтому живёт только в непустом значении.
 */
final class AckValue
{
	public const ROLE_SENIOR = 'senior';
	public const ROLE_MANAGER = 'manager';

	public const STATE_PENDING = 'pending';
	public const STATE_DONE = 'done';

	/** Что показать пользователю (decide()). */
	public const VIEW_EMPTY = 'empty';
	public const VIEW_BUTTON = 'button';
	public const VIEW_PENDING = 'pending';
	public const VIEW_DONE = 'done';

	public static function format(int $userId, int $timestamp, bool $byAdmin = false): string
	{
		return $userId.'|'.$timestamp.($byAdmin ? '|admin' : '');
	}

	/**
	 * Значение отметки при нажатии; null — прав нет. Адресат отмечает за
	 * себя; администратор портала — любую из двух, и если он не адресат,
	 * значение помечается «|admin» (текст: «… (за менеджера)»).
	 */
	public static function markValue(string $role, int $userId, int $assignedById, int $seniorId, bool $isAdmin, int $timestamp): ?string
	{
		if(static::canMark($role, $userId, $assignedById, $seniorId))
		{
			return static::format($userId, $timestamp);
		}
		if($isAdmin && $userId > 0 && static::role($role) !== null)
		{
			return static::format($userId, $timestamp, true);
		}

		return null;
	}

	public static function pending(int $addresseeId): string
	{
		return self::STATE_PENDING.'|'.max(0, $addresseeId);
	}

	/**
	 * Отметка поставлена: кто и когда. Ждёт отметки, пусто, мусор — null.
	 *
	 * @return array{userId: int, at: int, byAdmin: bool}|null
	 */
	public static function parse(mixed $value): ?array
	{
		$state = static::state($value);

		return $state !== null && $state['state'] === self::STATE_DONE ? ['userId' => $state['userId'], 'at' => $state['at'], 'byAdmin' => $state['byAdmin']] : null;
	}

	/**
	 * Разбор любого состояния.
	 *
	 * @return array{state: string, userId: int, at: int, byAdmin: bool}|null null — пусто или мусор
	 */
	public static function state(mixed $value): ?array
	{
		if(!is_string($value))
		{
			return null;
		}
		$value = trim($value);
		if(1 === preg_match('/^pending\|(\d+)$/', $value, $match))
		{
			return ['state' => self::STATE_PENDING, 'userId' => (int)$match[1], 'at' => 0, 'byAdmin' => false];
		}
		if(1 !== preg_match('/^(\d+)\|(\d+)(\|admin)?$/', $value, $match))
		{
			return null;
		}
		$userId = (int)$match[1];
		$at = (int)$match[2];

		return $userId > 0 && $at > 0 ? ['state' => self::STATE_DONE, 'userId' => $userId, 'at' => $at, 'byAdmin' => ($match[3] ?? '') !== ''] : null;
	}

	/**
	 * Новое значение поля после анализа; null — не трогать.
	 *
	 *   риск ниже LOW                 -> "" (если там что-то было);
	 *   сброс (зона сменилась)        -> "pending|адресат";
	 *   пусто/мусор                   -> "pending|адресат" (в т.ч. сделки,
	 *                                    проанализированные в 1.8.0);
	 *   ждёт отметки                  -> "pending|адресат" (адресат мог смениться);
	 *   отмечено, зона та же          -> не трогаем.
	 */
	public static function next(mixed $current, bool $reset, bool $riskSignificant, int $addresseeId): ?string
	{
		$state = static::state($current);
		if(!$riskSignificant)
		{
			return (is_string($current) && trim($current) === '') || $current === null ? null : '';
		}
		if(!$reset && $state !== null && $state['state'] === self::STATE_DONE)
		{
			return null;
		}
		$value = static::pending($addresseeId);

		return $value === $current ? null : $value;
	}

	/** Роль из настроек поля; чужое — null. */
	public static function role(mixed $value): ?string
	{
		return in_array($value, [self::ROLE_SENIOR, self::ROLE_MANAGER], true) ? $value : null;
	}

	/**
	 * Может ли пользователь поставить отметку: старшего — только старший
	 * профиля сделки, менеджера — только ответственный за сделку.
	 */
	public static function canMark(string $role, int $userId, int $assignedById, int $seniorId): bool
	{
		if($userId <= 0)
		{
			return false;
		}

		return match($role)
		{
			self::ROLE_SENIOR => $seniorId > 0 && $userId === $seniorId,
			self::ROLE_MANAGER => $assignedById > 0 && $userId === $assignedById,
			default => false,
		};
	}

	/**
	 * Кому кнопка, кому текст. Кнопка — только адресату (по текущим данным
	 * сделки) и администратору портала (с 1.8.2 — обе кнопки), и только
	 * пока отметка ждёт.
	 *
	 * @param array{state: string, userId: int, at: int, byAdmin?: bool}|null $state
	 */
	public static function decide(string $role, ?array $state, int $userId, int $assignedById, int $seniorId, bool $withButton = true, bool $isAdmin = false): string
	{
		if($state === null)
		{
			return self::VIEW_EMPTY;
		}
		if($state['state'] === self::STATE_DONE)
		{
			return self::VIEW_DONE;
		}

		return $withButton && ($isAdmin && $userId > 0 || static::canMark($role, $userId, $assignedById, $seniorId)) ? self::VIEW_BUTTON : self::VIEW_PENDING;
	}

	/** Текст для тех, кому кнопки нет. */
	public static function text(string $role, string $view, string $userName, int $at, bool $byAdmin = false): string
	{
		$behalf = $byAdmin ? ($role === self::ROLE_SENIOR ? ' (за старшего)' : ' (за менеджера)') : '';

		return match($view)
		{
			self::VIEW_PENDING => 'Ждёт отметки'.($userName !== '' ? ': '.$userName : ''),
			self::VIEW_DONE => ($role === self::ROLE_SENIOR ? 'Под контролем' : 'Отработано').': '.$userName.', '.date('d.m.Y H:i', $at).$behalf,
			default => '',
		};
	}

	/** Пояснение старшего «почему риски сняты» (1.46.0): не короче и не длиннее. */
	public const REASON_MIN = 15;
	public const REASON_MAX = 1000;

	/**
	 * Пояснение к отметке: старшему — обязательно (не короче REASON_MIN), менеджеру —
	 * не нужно. Ошибка — текст для пользователя, всё в порядке — null.
	 */
	public static function reasonError(string $role, string $reason): ?string
	{
		if($role !== self::ROLE_SENIOR)
		{
			return null;
		}
		$length = mb_strlen(trim($reason));
		if($length < self::REASON_MIN)
		{
			return 'Напишите, почему вы считаете, что риски устранены (не короче '.self::REASON_MIN.' символов).';
		}

		return $length > self::REASON_MAX ? 'Пояснение длиннее '.self::REASON_MAX.' символов — сократите.' : null;
	}

	/** Комментарий в ленту записи: кто снял риск и почему. Его видят люди и анализ AI (история записи). */
	public static function reasonComment(string $userName, string $reason, bool $byAdmin = false): string
	{
		return 'AI: риск под контролем — '.$userName.($byAdmin ? ' (за старшего)' : '').".\nПочему риски устранены: "
			.trim((string)preg_replace('/[ \t]+/u', ' ', $reason));
	}

	public static function buttonTitle(string $role): string
	{
		return $role === self::ROLE_SENIOR ? 'Риск под контролем' : 'Отработано';
	}

	/** Пояснение под кнопкой: о чём она и к чему приводит нажатие. */
	public static function hint(string $role, bool $asAdmin = false): string
	{
		if($asAdmin)
		{
			return 'Вы администратор: отметка будет поставлена от вашего имени.';
		}

		return $role === self::ROLE_SENIOR
			? 'Нажмите, если риски по сделке устранены: попросим коротко написать почему — пояснение ляжет комментарием в ленту. Отметку увидит менеджер и руководитель, поле «AI: старший отметил» станет «да». При новом риске отметка сбросится.'
			: 'Нажмите, когда разберётесь с риском по сделке: отметку увидит руководитель, поле «AI: менеджер отметил» станет «да». При новом риске отметка сбросится.';
	}
}
