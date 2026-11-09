<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Main;

use Shef\ToolsAiCrm\Analysis\Candidates;
use Shef\ToolsAiCrm\Analysis\EntityFields;
use Shef\ToolsAiCrm\Analysis\EntityTypes;
use Shef\ToolsAiCrm\Analysis\Model\EntityCheckTable;
use Shef\ToolsAiCrm\Analysis\Model\HistoryTable;
use Shef\ToolsAiCrm\Analysis\Model\PromptTable;
use Shef\ToolsAiCrm\CallScript\Model\CallScriptRuleTable;
use Shef\ToolsAiCrm\Config;
use Shef\ToolsAiCrm\Deal\Model\DealProfileTable;
use Shef\ToolsAiCrm\Deal\Profile;

/**
 * «Проверить и включить» и подготовка при установке: всё, что делает модуль рабочим, —
 * идемпотентно, повторный запуск ничего не плодит и ничего не затирает.
 *
 * Контракт: prepare() и run() возвращают отчёт — массив «шаг => [ok, message, warn?]»
 * (тот же вид, что у Main\Setup shef.toolsai: строка «✔/✖ шаг — сообщение»). Исключений наружу нет:
 * сбой шага — строка с ok = false, остальные шаги идут.
 *
 *   prepare() — установщик и доведение: таблицы; перенос данных из shef.toolsai (Main\Migration:
 *     остановить старое, копия, сверка); подписки (installEvents: тип поля «AI: …», защита
 *     полей, скрипты звонков); заглушки страниц и робота БП; агенты анализа по типам с
 *     включённым профилем; агент доведения.
 *   run() — prepare() и поля «AI: …» с разделом карточки у типов с включённым профилем.
 *     Поля, уже созданные shef.toolsai, не пересоздаются: EntityFields::ensure ищет их по коду
 *     UF_SHEF_AI_* и дописывает только пустое; раздел карточки на месте не трогается.
 */
final class Setup
{
	/** Таблицы модуля: init() создаёт недостающее и дописывает столбцы. */
	public const TABLES = [
		DealProfileTable::class,
		EntityCheckTable::class,
		HistoryTable::class,
		PromptTable::class,
		CallScriptRuleTable::class,
	];

	/** @var \Closure(): Profile[] */
	private readonly \Closure $profiles;

	/**
	 * @param (\Closure(): Profile[])|null $profiles все профили анализа; null — из таблицы (тесты подставляют свои)
	 */
	public function __construct(
		private readonly Config $config,
		private readonly ?MigrationPort $port = null,
		?\Closure $profiles = null
	)
	{
		$this->profiles = $profiles ?? static fn(): array => DealProfileTable::getProfiles(false);
	}

	/**
	 * Все профили анализа (включённые и нет). Не прочитались — исключение: вызывающий не
	 * должен принять «ничего не прочитано» за «профилей нет» и снять агентов.
	 *
	 * @return Profile[]
	 */
	public function readProfiles(): array
	{
		return ($this->profiles)();
	}

	// region Сценарии ////
	/**
	 * Подготовка (установка, обновление копированием, «Проверить и включить»).
	 *
	 * @return array<string, array{ok: bool, message: string, warn?: bool}>
	 */
	public function prepare(string $documentRoot, string $moduleDir): array
	{
		$report = [];

		$report['tables'] = $this->ensureTables();

		// Перенос — после таблиц (приёмник создан) и до агентов (профили берутся из приёмника)
		foreach($this->migrate() as $step => $row)
		{
			$report[$step] = $row;
		}

		$report['events'] = $this->ensureEvents($moduleDir);

		foreach(PublicPage::installAll($documentRoot, $moduleDir) as $file => $ok)
		{
			$report['file '.$file] = ['ok' => $ok, 'message' => $ok ? 'на месте' : 'не записана: нет прав или там чужой файл'];
		}

		try
		{
			$enabled = Candidates::groupByType(($this->profiles)());
			$report['analysis agents'] = $this->ensureAnalysisAgents(array_keys($enabled));
		}
		catch(\Throwable $throwable)
		{
			// Профили не прочитались — агентов не трогаем: пустой список снял бы их все
			$report['analysis agents'] = ['ok' => false, 'message' => 'профили не прочитаны, агенты оставлены как есть: '.$throwable->getMessage()];
		}

		Maintenance::installAgent();
		$report['maintenance agent'] = ['ok' => true, 'message' => 'доведение после обновления файлов'];

		return $report;
	}

	/**
	 * «Проверить и включить»: prepare() и поля «AI: …» у типов с включённым профилем.
	 *
	 * @return array<string, array{ok: bool, message: string, warn?: bool}>
	 */
	public function run(string $documentRoot, string $moduleDir): array
	{
		$report = $this->prepare($documentRoot, $moduleDir);

		try
		{
			$profiles = ($this->profiles)();
			$all = [];
			foreach($profiles as $profile)
			{
				$all[$profile->entityTypeId][] = $profile;
			}
			$enabled = Candidates::groupByType($profiles);
		}
		catch(\Throwable $throwable)
		{
			$report['analysis types'] = ['ok' => false, 'message' => $throwable->getMessage()];

			return $report;
		}

		foreach(array_keys($enabled) as $entityTypeId)
		{
			try
			{
				$type = EntityTypes::get($entityTypeId);
				if($type === null)
				{
					$report['fields '.$entityTypeId] = ['ok' => false, 'message' => 'тип сущности не найден (смарт-процесс удалён?)'];
					continue;
				}
				$report['fields '.$type->ufEntityId] = EntityFields::ensure($type);
				$report['card '.$type->ufEntityId] = EntityFields::ensureCard(
					$type,
					array_map(static fn(Profile $profile): int => $profile->categoryId, $all[$entityTypeId] ?? [])
				);
			}
			catch(\Throwable $throwable)
			{
				$report['fields '.$entityTypeId] = ['ok' => false, 'message' => $throwable->getMessage()];
			}
		}

		return $report;
	}
	// endregion ////

	// region Шаги ////
	/** @return array{ok: bool, message: string} */
	public function ensureTables(): array
	{
		$failed = [];
		foreach(self::TABLES as $table)
		{
			try
			{
				$table::init();
			}
			catch(\Throwable $throwable)
			{
				$failed[] = $table::getTableName().': '.$throwable->getMessage();
			}
		}

		return $failed === []
			? ['ok' => true, 'message' => 'на месте']
			: ['ok' => false, 'message' => 'не созданы: '.implode('; ', $failed)];
	}

	/**
	 * Перенос из shef.toolsai. Мягко: сбой — строка отчёта, установка идёт дальше.
	 *
	 * @return array<string, array{ok: bool, message: string, warn?: bool}>
	 */
	public function migrate(): array
	{
		try
		{
			return (new Migration($this->port ?? new CoreMigrationPort()))->run()['steps'];
		}
		catch(\Throwable $throwable)
		{
			return ['migrate' => ['ok' => false, 'message' => 'перенос из shef.toolsai не выполнен: '.$throwable->getMessage()]];
		}
	}

	/**
	 * Постоянные подписки из installEvents: тип поля, защита полей, скрипты звонков и подписки
	 * соседей. Список читается свежим из .settings.php; зарегистрировано должно быть всё.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public function ensureEvents(string $moduleDir): array
	{
		try
		{
			$events = Maintenance::installEvents($moduleDir);
			Maintenance::registerEvents($events);
			$missing = Maintenance::missingEvents($events);
			if($missing !== [])
			{
				\Bitrix\Main\EventManager::getInstance()->clearLoadedHandlers();
				$missing = Maintenance::missingEvents($events);
			}

			return $missing === []
				? ['ok' => true, 'message' => 'подписок: '.count($events)]
				: ['ok' => false, 'message' => 'не встали: '.implode(', ', $missing)];
		}
		catch(\Throwable $throwable)
		{
			return ['ok' => false, 'message' => $throwable->getMessage()];
		}
	}
	// endregion ////

	// region Агенты анализа ////
	/** Агент анализа типа: сделки — 2, лиды — 1, смарт-процесс — его entityTypeId. */
	public static function analysisAgentName(int $entityTypeId): string
	{
		return sprintf(Constants::AGENT_ANALYSIS, $entityTypeId);
	}

	/** entityTypeId по имени агента анализа этого модуля; не он — 0. Чистая функция. */
	public static function parseAnalysisAgentName(string $name): int
	{
		return 1 === preg_match('/^\\\\?Shef\\\\ToolsAiCrm\\\\Agent\\\\AnalysisAgent::run\((\d{1,9})\);$/', trim($name), $m) ? (int)$m[1] : 0;
	}

	/**
	 * Агенты анализа: по одному на тип с включённым профилем, у каждого свой интервал
	 * (Config::getAnalysisInterval()). Есть — интервал сверяется; типа больше нет среди
	 * включённых — агент снимается; лишние копии одного имени — тоже.
	 * Новый агент первый раз запускается не сразу, а через интервал: сразу после
	 * установки или переноса прогон не нужен (и не должен тратить квоту до того, как администратор осмотрелся).
	 *
	 * @param int[] $entityTypeIds типы с включённым профилем
	 * @return array{ok: bool, message: string}
	 */
	public function ensureAnalysisAgents(array $entityTypeIds): array
	{
		$existing = static::listModuleAgents();
		$plan = static::planAnalysisAgents($entityTypeIds, $existing);
		$ok = true;
		foreach($plan['remove'] as $agentId)
		{
			\CAgent::Delete($agentId);
		}
		foreach($plan['ensure'] as $entityTypeId)
		{
			$ok = $this->ensureOneAgent(static::analysisAgentName($entityTypeId), $this->config->getAnalysisInterval($entityTypeId)) && $ok;
		}

		return [
			'ok' => $ok,
			'message' => ($plan['ensure'] !== [] ? 'типы: '.implode(', ', $plan['ensure']) : 'включённых профилей нет')
				.($plan['remove'] !== [] ? '; снято лишних: '.count($plan['remove']) : ''),
		];
	}

	/**
	 * Что сделать с агентами анализа. Чистая функция.
	 *
	 * @param int[] $entityTypeIds типы с включённым профилем
	 * @param array<int, string> $existing агенты модуля: ID => имя
	 * @return array{ensure: int[], remove: int[]} типы для регистрации и ID агентов к снятию
	 */
	public static function planAnalysisAgents(array $entityTypeIds, array $existing): array
	{
		$ensure = array_values(array_unique(array_filter(array_map('intval', $entityTypeIds), static fn(int $id): bool => $id > 0)));
		sort($ensure);
		$remove = [];
		$kept = [];
		ksort($existing);
		foreach($existing as $agentId => $name)
		{
			$typeId = static::parseAnalysisAgentName($name);
			if($typeId === 0)
			{
				continue;
			}
			if(!in_array($typeId, $ensure, true) || isset($kept[$typeId]))
			{
				// типа среди включённых нет либо это вторая копия того же агента
				$remove[] = (int)$agentId;
				continue;
			}
			$kept[$typeId] = (int)$agentId;
		}

		return ['ensure' => $ensure, 'remove' => $remove];
	}

	/**
	 * Все агенты модуля: ID => имя. Фильтр ядра по NAME — LIKE с экранированием
	 * (main/classes/general/agent.php, GetList), поэтому по MODULE_ID и разбор имён здесь.
	 *
	 * @return array<int, string>
	 */
	public static function listModuleAgents(): array
	{
		return array_map(static fn(array $row): string => (string)$row['NAME'], static::fetchModuleAgents());
	}

	/**
	 * Строки агентов модуля из ядра: ID => строка b_agent.
	 *
	 * @return array<int, array>
	 */
	private static function fetchModuleAgents(): array
	{
		$result = [];
		$list = \CAgent::GetList(['ID' => 'ASC'], ['MODULE_ID' => Constants::MODULE_ID]);
		while($row = $list->Fetch())
		{
			$result[(int)$row['ID']] = $row;
		}

		return $result;
	}

	private function ensureOneAgent(string $name, int $interval): bool
	{
		foreach(static::fetchModuleAgents() as $agentId => $row)
		{
			if(ltrim((string)$row['NAME'], '\\') !== ltrim($name, '\\'))
			{
				continue;
			}
			$nextExec = isset($row['NEXT_EXEC']) && function_exists('MakeTimeStamp') ? (int)MakeTimeStamp((string)$row['NEXT_EXEC']) : null;
			$changes = static::planAgentUpdate(
				isset($row['AGENT_INTERVAL']) ? (int)$row['AGENT_INTERVAL'] : null,
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

			return (bool)\CAgent::Update($agentId, $changes);
		}

		// Первый запуск — через интервал, а не сразу
		return (bool)\CAgent::AddAgent(
			$name,
			Constants::MODULE_ID,
			'N',
			$interval,
			'',
			'Y',
			ConvertTimeStamp(time() + $interval, 'FULL')
		);
	}

	/**
	 * Что поменять у существующего агента. Чистая функция.
	 *
	 * @param int|null $current интервал агента сейчас, секунд
	 * @param int|null $nextExec следующий запуск (unix)
	 * @return array{AGENT_INTERVAL?: int, NEXT_EXEC?: int} пусто — менять нечего
	 */
	public static function planAgentUpdate(?int $current, ?int $nextExec, int $interval, int $now): array
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
	// endregion ////
}
