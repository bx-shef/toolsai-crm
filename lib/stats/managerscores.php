<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Stats;

/**
 * Оценка работы менеджеров в анализе записей (1.67.0): managerScore из
 * истории анализа (HistoryTable.EXTRA, JSON) по ответственным. Заменяет в
 * «Статистике» отдельные оценки чатов и писем — теперь звонки, письма и чаты
 * оценивает одна модель при анализе сделки.
 *
 * Чистая логика — stats_test.php.
 */
final class ManagerScores
{
	/**
	 * Строки истории с оценкой и ответственным — один запрос для отчёта «Оценка работы
	 * менеджеров» и инструмента MCP toolsai_manager_scores (1.73.0: MCP брал только сделки).
	 * Даты — уже через SqlHelper; таблицы — из AnalysisOwners::tables().
	 *
	 * @param array<int, string> $ownerTables тип записи => таблица
	 */
	public static function historySql(array $ownerTables, string $historyTable, string $from, string $till, int $userId, int $limit): string
	{
		$owner = AnalysisOwners::owner($ownerTables);

		return 'SELECT '.$owner.' AS GRP, h.EXTRA
	FROM '.$historyTable.' h
	'.AnalysisOwners::joins($ownerTables, 'h').'
	WHERE h.'.AnalysisOwners::scope($ownerTables)." AND h.EXTRA LIKE '%managerScore%'"
			.' AND h.CREATED_AT >= '.$from.' AND h.CREATED_AT < '.$till
			.($userId > 0 ? ' AND '.$owner.' = '.$userId : '').'
	LIMIT '.max(1, $limit);
	}

	/**
	 * @param iterable<array{GRP: mixed, EXTRA: mixed}> $rows строки истории с ответственным
	 * @return list<array{user: int, count: int, avg: float, min: int}> худшие первыми
	 */
	public static function aggregate(iterable $rows): array
	{
		$by = [];
		foreach($rows as $row)
		{
			$extra = json_decode((string)($row['EXTRA'] ?? ''), true);
			$score = is_array($extra) ? ($extra['managerScore'] ?? null) : null;
			if(!is_int($score) || $score < 0 || $score > 100)
			{
				continue;
			}
			$user = (int)($row['GRP'] ?? 0);
			$by[$user]['sum'] = ($by[$user]['sum'] ?? 0) + $score;
			$by[$user]['count'] = ($by[$user]['count'] ?? 0) + 1;
			$by[$user]['min'] = min($by[$user]['min'] ?? 100, $score);
		}
		$result = [];
		foreach($by as $user => $item)
		{
			$result[] = ['user' => $user, 'count' => $item['count'], 'avg' => round($item['sum'] / $item['count'], 1), 'min' => $item['min']];
		}
		usort($result, static fn(array $a, array $b): int => [$a['avg'], $a['user']] <=> [$b['avg'], $b['user']]);

		return $result;
	}
}
