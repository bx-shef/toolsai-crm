<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Deal;

/**
 * Когда сбрасывать отметки «Отработано» (менеджер) и «Риск под контролем»
 * (старший) в полях сделки. Чистая функция — без ядра.
 *
 * Отметка относится к уровню риска, при котором её поставили. Новый анализ
 * перевёл сделку в другую зону шкалы профиля — отметка больше не про то:
 *   - риск появился (был < LOW, стал >= LOW);
 *   - риск ушёл (был >= LOW, стал < LOW);
 *   - риск пересёк HIGH в любую сторону.
 * Внутри одной зоны отметка остаётся.
 */
final class AckReset
{
	public const ZONE_NONE = 0;
	public const ZONE_LOW = 1;
	public const ZONE_HIGH = 2;

	public static function zone(int $risk, int $lowBorder, int $highBorder): int
	{
		if($risk < $lowBorder)
		{
			return self::ZONE_NONE;
		}

		return $risk >= $highBorder ? self::ZONE_HIGH : self::ZONE_LOW;
	}

	/**
	 * Риск прошлой проверки для shouldReset() по строке проверки (1.14.7):
	 * анализ — его риск; «оценить нечего» — -1 (зона «ниже порога»);
	 * прочие пропуски и ошибки — null, прошлого риска нет.
	 *
	 * @param array<string, mixed>|null $check строка EntityCheckTable
	 */
	public static function previousRisk(?array $check): ?int
	{
		if($check === null)
		{
			return null;
		}
		if(($check['SKIPPED'] ?? 'N') !== 'Y')
		{
			return (int)$check['RISK'];
		}

		return (int)($check['RISK'] ?? 0) === Verdict::NOTHING_RISK ? Verdict::NOTHING_RISK : null;
	}

	/**
	 * @param int|null $previousRisk риск прошлого анализа; null — анализа не было
	 */
	public static function shouldReset(?int $previousRisk, int $risk, int $lowBorder, int $highBorder): bool
	{
		if($previousRisk === null)
		{
			return false;
		}

		return static::zone($previousRisk, $lowBorder, $highBorder) !== static::zone($risk, $lowBorder, $highBorder);
	}
}
