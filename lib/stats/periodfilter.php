<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Stats;

/**
 * Фильтр периода отчётов статистики — штатный main.ui.filter в верхней панели
 * страницы, с пресетами и сохранением у пользователя.
 *
 * Отчёт считает по параметрам from и to (Y-m-d) — их понимают ссылки на отчёт.
 * Здесь — перевод в обе стороны:
 *
 *   * values() — значения фильтра -> параметры отчёта;
 *   * settings() — параметры из ссылки -> состояние фильтра, чтобы фильтр
 *     показывал то, что сейчас отобрано.
 *
 * Чистая логика, без ядра — periodfilter_test.php.
 */
final class PeriodFilter
{
	public const ID = 'shef_toolsaicrm_stats';
	/** Параметры отчёта, которые несёт фильтр */
	public const PARAMS = ['from', 'to'];

	/** Поля фильтра: только период. */
	public static function fields(): array
	{
		return [['id' => 'PERIOD', 'name' => 'Период', 'type' => 'date', 'default' => true]];
	}

	/**
	 * Значения фильтра (Options::getFilter) -> параметры отчёта.
	 *
	 * @return array<string, string> только заданные
	 */
	public static function values(array $filter): array
	{
		return array_filter([
			'from' => self::date($filter['PERIOD_from'] ?? null),
			'to' => self::date($filter['PERIOD_to'] ?? null),
		], static fn(string $v): bool => $v !== '');
	}

	/** В ссылке есть параметры отчёта — фильтр надо выставить по ним. */
	public static function hasParams(array $query): bool
	{
		foreach(self::PARAMS as $key)
		{
			if(isset($query[$key]) && $query[$key] !== '')
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Параметры отчёта из ссылки -> состояние фильтра (Options::setFilterSettings).
	 *
	 * @param string $dateFormat формат даты сайта (PHP), в нём фильтр хранит даты
	 * @return array{fields: array<string, mixed>, rows: list<string>}
	 */
	public static function settings(array $query, string $dateFormat = 'd.m.Y'): array
	{
		$fields = [];
		$from = Period::parseDate($query['from'] ?? null);
		$to = Period::parseDate($query['to'] ?? null);
		if($from !== null || $to !== null)
		{
			$fields += ['PERIOD_datesel' => 'RANGE', 'PERIOD_from' => $from?->format($dateFormat) ?? '', 'PERIOD_to' => $to?->format($dateFormat) ?? ''];
		}

		return ['fields' => $fields, 'rows' => $fields === [] ? [] : ['PERIOD']];
	}

	private static function date(mixed $value): string
	{
		if(!is_string($value) || trim($value) === '')
		{
			return '';
		}
		foreach(['d.m.Y H:i:s', 'd.m.Y', 'Y-m-d H:i:s', 'Y-m-d', 'm/d/Y h:i:s a', 'm/d/Y'] as $format)
		{
			$date = \DateTimeImmutable::createFromFormat('!'.$format, trim($value));
			if($date !== false)
			{
				return $date->format('Y-m-d');
			}
		}

		return '';
	}
}
