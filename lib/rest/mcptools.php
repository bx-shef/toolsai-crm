<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Rest;

use Shef\ToolsAiCrm\Integration\Links;

/**
 * Отчёт «Оценка работы менеджеров» для AI-помощника через MCP-сервер
 * toolsai-mcp: манифест инструмента, разбор аргументов и счёт ответа.
 * REST-методы — Integration\Rest\Service, область shef_toolsaicrm.
 *
 * | инструмент | метод | страница | право |
 * |---|---|---|---|
 * | toolsai_manager_scores | shef.toolsaicrm.report.managerscores | «Оценка работы менеджеров» | shef.toolsai: «Статистика по всему порталу» (reports.all) |
 *
 * Имя инструмента то же, что было у shef.toolsai (shef.toolsai.report.managerscores),
 * — клиенты MCP его знают. Право — того, чьим токеном вызван метод, не шире
 * страницы: нет права — инструмента нет в манифесте и метод отказывает. Ответ —
 * для модели: имена и ID, период, ссылка на страницу, без HTML; список — до 50.
 *
 * Чистый класс — tests/mcp_test.php.
 */
final class McpTools
{
	public const SCOPE = 'shef_toolsaicrm';
	public const METHOD_MANIFEST = 'shef.toolsaicrm.mcp.tools';
	public const METHOD_MANAGER_SCORES = 'shef.toolsaicrm.report.managerscores';
	public const TOOL_MANAGER_SCORES = 'toolsai_manager_scores';

	public const LIMIT_DEFAULT = 30;
	public const LIMIT_MAX = 50;
	/** Потолок текста имени в ответе модели. */
	public const TEXT_MAX = 200;

	/**
	 * Манифест для сотрудника: $all — есть право «Статистика по всему порталу».
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function manifest(bool $all): array
	{
		if(!$all)
		{
			return [];
		}

		return [[
			'name' => self::TOOL_MANAGER_SCORES,
			'title' => 'Оценка работы менеджеров по сделкам',
			'description' => 'Оценка работы менеджеров (0–100 баллов), которую AI ставит при анализе сделок по звонкам, письмам и чатам, — по ответственным за период: имя, ID, оценок, средний балл, минимум, место. Для вопросов «кто сколько баллов набрал», «как работают ответственные». Возвращает период и ссылку на отчёт «Оценка работы менеджеров».',
			'method' => self::METHOD_MANAGER_SCORES,
			'kind' => 'read',
			'input' => ['type' => 'object', 'properties' => static::filterSchema() + [
				'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::LIMIT_MAX, 'description' => 'Сколько менеджеров показать, по умолчанию 30'],
			]],
		]];
	}

	/** @return array<string, array<string, mixed>> */
	private static function filterSchema(): array
	{
		return [
			'from' => ['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$', 'description' => 'С даты включительно, ГГГГ-ММ-ДД. Нет — с 1-го числа текущего месяца'],
			'to' => ['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$', 'description' => 'По дату включительно, ГГГГ-ММ-ДД. Нет — по сегодня. За один день — from и to одинаковые'],
			'userId' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Только этот сотрудник (ID)'],
			'departmentId' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Только сотрудники отдела (ID) с подотделами'],
		];
	}

	/**
	 * Строгий разбор аргументов: негодное значение — ошибка, а не молчаливое умолчание
	 * (иначе модель ответит цифрами не за тот период).
	 *
	 * @return array{0: ?array{period: Period, userId: int, departmentId: int, limit: int}, 1: ?array{0: string, 1: string}}
	 */
	public static function parse(array $query, \DateTimeImmutable $now): array
	{
		foreach(['from', 'to'] as $key)
		{
			if(isset($query[$key]) && $query[$key] !== '' && Period::parseDate($query[$key]) === null)
			{
				return [null, ['INVALID_ARGUMENT', $key.' — дата ГГГГ-ММ-ДД, например '.$now->format('Y-m-d')]];
			}
		}
		$ints = [];
		foreach(['userId' => 0, 'departmentId' => 0, 'limit' => self::LIMIT_DEFAULT] as $key => $default)
		{
			$value = $query[$key] ?? null;
			if($value === null || $value === '')
			{
				$ints[$key] = $default;
				continue;
			}
			$id = static::positive($value);
			if($id === null)
			{
				return [null, ['INVALID_ARGUMENT', $key.' — целое больше 0 или не передавать']];
			}
			$ints[$key] = $id;
		}

		return [[
			'period' => Period::fromRequest($query['from'] ?? null, $query['to'] ?? null, $now),
			'userId' => $ints['userId'],
			'departmentId' => $ints['departmentId'],
			'limit' => min(self::LIMIT_MAX, $ints['limit']),
		], null];
	}

	/**
	 * Ответ toolsai_manager_scores: строки ManagerScores::aggregate (худшие первыми) —
	 * здесь рейтинг, лучшие первыми.
	 *
	 * @param list<array{user: int, count: int, avg: float, min: int}> $scores
	 * @param ?list<int> $userIds сотрудники отдела; null — без фильтра по отделу
	 * @param array<int, string> $names ID => имя
	 */
	public static function managerScores(array $scores, array $args, ?array $userIds, bool $truncated, array $names, string $url): array
	{
		$filter = $userIds === null ? null : array_flip($userIds);
		$scores = array_values(array_filter($scores, static fn(array $s): bool =>
			($args['userId'] <= 0 || $s['user'] === $args['userId']) && ($filter === null || isset($filter[$s['user']]))
		));
		usort($scores, static fn(array $a, array $b): int => [$b['avg'], $b['count'], $a['user']] <=> [$a['avg'], $a['count'], $b['user']]);
		$out = [];
		foreach(array_slice($scores, 0, $args['limit']) as $i => $s)
		{
			$out[] = ['rank' => $i + 1, 'userId' => $s['user'], 'name' => static::name($s['user'], $names), 'scores' => $s['count'], 'avg' => $s['avg'], 'min' => $s['min']];
		}
		$result = ['period' => ['from' => $args['period']->getFromValue(), 'to' => $args['period']->getToValue()], 'url' => $url, 'managers' => $out];
		if(count($scores) > count($out))
		{
			$result['more'] = count($scores) - count($out);
		}
		if($truncated)
		{
			$result['truncated'] = true;
			$result['note'] = 'Отчёт неполный: за период слишком много оценок, учтены последние. Сузьте период.';
		}
		elseif($scores === [])
		{
			$result['note'] = 'Оценок работы менеджеров за период нет: их ставит анализ сделок, когда он включён.';
		}

		return $result;
	}

	/** Ссылка на раздел отчёта с периодом и сотрудником. */
	public static function url(string $host, array $args): string
	{
		return $host.Links::section(Links::SECTION_MANAGERS, array_filter([
			'from' => $args['period']->getFromValue(),
			'to' => $args['period']->getToValue(),
		]));
	}

	/**
	 * ID отделов с подотделами: $parents — отдел => родитель (0 — корень).
	 *
	 * @param array<int, int> $parents
	 * @return list<int>
	 */
	public static function expandDepartment(int $department, array $parents): array
	{
		$children = [];
		foreach($parents as $id => $parent)
		{
			$children[(int)$parent][] = (int)$id;
		}
		$result = [];
		$queue = [$department];
		while($queue !== [])
		{
			$id = array_shift($queue);
			if(isset($result[$id]))
			{
				continue;
			}
			$result[$id] = true;
			array_push($queue, ...($children[$id] ?? []));
		}
		$ids = array_keys($result);
		sort($ids);

		return $ids;
	}

	/** ID сотрудников, о которых ответ (для имён). @return list<int> */
	public static function userIds(array $rows, string $key = 'user'): array
	{
		return array_values(array_unique(array_filter(array_map(static fn(array $r): int => (int)($r[$key] ?? 0), $rows))));
	}

	private static function name(int $id, array $names): string
	{
		return isset($names[$id]) ? static::text($names[$id], 80) : ($id > 0 ? '#'.$id : 'не указан');
	}

	/** Текст портала в ответ модели: одна строка, без управляющих и bidi-символов, с потолком. */
	public static function text(string $value, int $max = self::TEXT_MAX): string
	{
		$value = trim(preg_replace('/[\p{Cc}\p{Cf}\s]+/u', ' ', $value) ?? '');

		return mb_strlen($value) > $max ? rtrim(mb_substr($value, 0, $max - 1)).'…' : $value;
	}

	private static function positive(mixed $value): ?int
	{
		if(is_int($value))
		{
			return $value > 0 ? $value : null;
		}
		if(is_string($value) && preg_match('/^[1-9]\d{0,9}$/', $value))
		{
			return (int)$value;
		}

		return null;
	}
}
