<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Analysis;

/**
 * Блок «Последние проверки агента» в разделе «Авто-анализ CRM»
 * (1.24.7; до него — «Анализ сделок: последние проверки» в «Расходе и
 * включении», только сделки).
 *
 * Проверки всех типов лежат в одной таблице shef_toolsaicrm_entity_check с
 * ENTITY_TYPE_ID (Model\EntityCheckTable, 1.9.1) — блок показывает их по
 * типу: вкладка на каждый тип, у которого есть профиль. Здесь — чистая
 * часть: порядок вкладок, выбранная вкладка и запасной адрес карточки.
 * Штатный адрес даёт роутер CRM (Service\Router::getItemDetailUrl()),
 * запасной нужен, если роутер не ответил.
 */
final class LastChecks
{
	/** Сколько последних проверок показывать на тип. */
	public const LIMIT = 20;

	/** Параметр адреса с выбранной вкладкой. */
	public const PARAM = 'checks';
	/** Показать и пропуски без модели (по умолчанию — только проверки моделью, 1.24.8). */
	public const ALL_PARAM = 'checks_all';

	/**
	 * Типы для вкладок: сделки, лиды, затем смарт-процессы по возрастанию.
	 * Повторы и неподдерживаемые типы отбрасываются.
	 *
	 * @param int[] $entityTypeIds
	 * @return int[]
	 */
	public static function order(array $entityTypeIds): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $entityTypeIds), [EntityType::class, 'isSupportedId'])));
		usort($ids, static fn(int $a, int $b): int => [self::rank($a), $a] <=> [self::rank($b), $b]);

		return $ids;
	}

	/** Выбранная вкладка: из запроса, если такой тип есть, иначе первая. */
	public static function active(mixed $requested, array $orderedTypeIds): ?int
	{
		if((is_string($requested) || is_int($requested)) && in_array((int)$requested, $orderedTypeIds, true))
		{
			return (int)$requested;
		}

		return $orderedTypeIds[0] ?? null;
	}

	/**
	 * Адрес карточки без роутера — как штатные: /crm/deal/details/5/,
	 * /crm/lead/details/5/, /crm/type/128/details/5/.
	 */
	public static function fallbackUrl(int $entityTypeId, int $id): string
	{
		return match($entityTypeId)
		{
			EntityType::DEAL => '/crm/deal/details/'.$id.'/',
			EntityType::LEAD => '/crm/lead/details/'.$id.'/',
			default => '/crm/type/'.$entityTypeId.'/details/'.$id.'/',
		};
	}

	/**
	 * Адрес карточки: штатный от роутера, иначе запасной.
	 *
	 * @param null|callable(int, int): mixed $router адрес от ядра (Uri, строка или null)
	 */
	public static function url(int $entityTypeId, int $id, ?callable $router = null): string
	{
		if($router !== null)
		{
			try
			{
				$url = (string)($router($entityTypeId, $id) ?? '');
				if($url !== '')
				{
					return $url;
				}
			}
			catch(\Throwable)
			{
			}
		}

		return static::fallbackUrl($entityTypeId, $id);
	}

	private static function rank(int $entityTypeId): int
	{
		return match($entityTypeId)
		{
			EntityType::DEAL => 0,
			EntityType::LEAD => 1,
			default => 2,
		};
	}

	/**
	 * Колонка «Что сделано» (1.24.8): агент пишет «ничего: <причина>», а причина
	 * уже стоит в «Почему» — повтор убираем, остаётся «ничего».
	 */
	public static function action(string $action, string $why): string
	{
		$action = trim($action);
		if(preg_match('/^ничего\s*[:—-]\s*(.*)$/us', $action, $m))
		{
			$rest = trim($m[1]);
			return $rest === '' || $rest === trim($why) || str_starts_with(mb_strtolower(trim($why)), mb_strtolower(mb_substr($rest, 0, 40))) || str_starts_with(mb_strtolower($rest), 'пропуск') ? 'ничего' : 'ничего: '.$rest;
		}

		return $action;
	}
}
