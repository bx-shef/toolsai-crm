<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Settings;

use Shef\ToolsAiCrm\Config;
use Shef\ToolsAiCrm\Main\Constants;

/**
 * Агенты анализа по типам (сделки, лиды, смарт-процессы): по одному на тип с
 * включённым профилем, у каждого свой интервал (Config::getAnalysisInterval).
 * Страница профилей сверяет их сразу после сохранения: первый включённый профиль
 * типа заводит агента, выключен последний — агент снимается. Первый запуск нового
 * агента — через интервал.
 *
 * Планирование — чистые функции (тест analysisagents_test.php).
 */
final class AnalysisAgents
{
	/** entityTypeId по имени агента анализа; не агент анализа — 0. Чистая функция. */
	public static function parseName(string $name): int
	{
		return 1 === preg_match('/^\\\\?Shef\\\\ToolsAiCrm\\\\Agent\\\\AnalysisAgent::run\((\d{1,9})\);$/', trim($name), $m) ? (int)$m[1] : 0;
	}

	public static function name(int $entityTypeId): string
	{
		return sprintf(Constants::AGENT_ANALYSIS, $entityTypeId);
	}

	/**
	 * Что сделать с агентами. Чистая функция.
	 *
	 * @param int[] $entityTypeIds типы с включённым профилем
	 * @param array<int, string> $existing агенты модуля: ID => имя
	 * @return array{ensure: int[], remove: int[]} типы для регистрации и ID агентов к снятию
	 */
	public static function plan(array $entityTypeIds, array $existing): array
	{
		$ensure = array_values(array_unique(array_filter(array_map('intval', $entityTypeIds), static fn(int $id): bool => $id > 0)));
		sort($ensure);
		$remove = [];
		foreach($existing as $agentId => $name)
		{
			$typeId = static::parseName($name);
			if($typeId > 0 && !in_array($typeId, $ensure, true))
			{
				$remove[] = (int)$agentId;
			}
		}

		return ['ensure' => $ensure, 'remove' => $remove];
	}

	/**
	 * Что поменять у существующего агента. Чистая функция.
	 *
	 * @param int|null $current интервал агента сейчас, секунд
	 * @param int|null $nextExec следующий запуск (unix)
	 * @return array{AGENT_INTERVAL?: int, NEXT_EXEC?: int} пусто — менять нечего
	 */
	public static function planUpdate(?int $current, ?int $nextExec, int $interval, int $now): array
	{
		$changes = [];
		if($current !== $interval)
		{
			$changes['AGENT_INTERVAL'] = $interval;
		}
		if($nextExec !== null && $nextExec > $now + $interval)
		{
			$changes['NEXT_EXEC'] = $now + $interval;
		}

		return $changes;
	}

	/**
	 * Все агенты модуля: ID => имя. Фильтр ядра по NAME — LIKE, поэтому по
	 * MODULE_ID, а имена разбираются здесь.
	 *
	 * @return array<int, string>
	 */
	public static function listModuleAgents(): array
	{
		$result = [];
		$list = \CAgent::GetList(['ID' => 'ASC'], ['MODULE_ID' => Constants::MODULE_ID]);
		while($row = $list->Fetch())
		{
			$result[(int)$row['ID']] = (string)$row['NAME'];
		}

		return $result;
	}

	/**
	 * Типы, для которых агент зарегистрирован сейчас.
	 *
	 * @return int[]
	 */
	public static function registeredTypes(): array
	{
		return array_values(array_filter(array_map([static::class, 'parseName'], static::listModuleAgents())));
	}

	/**
	 * Свести агентов с типами, у которых есть включённый профиль. Идемпотентно.
	 *
	 * @param int[] $entityTypeIds типы с включённым профилем
	 * @return array{ok: bool, message: string}
	 */
	public static function ensure(Config $config, array $entityTypeIds): array
	{
		$plan = static::plan($entityTypeIds, static::listModuleAgents());
		$ok = true;
		foreach($plan['ensure'] as $entityTypeId)
		{
			$ok = static::ensureOne(static::name($entityTypeId), $config->getAnalysisInterval($entityTypeId)) && $ok;
		}
		foreach($plan['remove'] as $agentId)
		{
			\CAgent::Delete($agentId);
		}

		return [
			'ok' => $ok,
			'message' => ($plan['ensure'] !== [] ? 'типы: '.implode(', ', $plan['ensure']) : 'включённых профилей нет')
				.($plan['remove'] !== [] ? '; снято лишних: '.count($plan['remove']) : ''),
		];
	}

	private static function ensureOne(string $name, int $interval): bool
	{
		$existing = \CAgent::GetList([], ['NAME' => $name, 'MODULE_ID' => Constants::MODULE_ID])->Fetch();
		if($existing)
		{
			$nextExec = isset($existing['NEXT_EXEC']) && function_exists('MakeTimeStamp') ? (int)MakeTimeStamp((string)$existing['NEXT_EXEC']) : null;
			$changes = static::planUpdate(
				isset($existing['AGENT_INTERVAL']) ? (int)$existing['AGENT_INTERVAL'] : null,
				$nextExec ?: null,
				$interval,
				time()
			);
			if($changes === [])
			{
				return true;
			}
			if(isset($changes['NEXT_EXEC']))
			{
				$changes['NEXT_EXEC'] = ConvertTimeStamp($changes['NEXT_EXEC'], 'FULL');
			}

			return (bool)\CAgent::Update((int)$existing['ID'], $changes);
		}

		// Первый запуск — через интервал, а не сразу
		return (bool)\CAgent::AddAgent($name, Constants::MODULE_ID, 'N', $interval, '', 'Y', ConvertTimeStamp(time() + $interval, 'FULL'));
	}
}
