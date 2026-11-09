<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Main;

/**
 * Перенос данных анализа из shef.toolsai в этот модуль (bx-shef/toolsai#639).
 *
 * Порядок:
 *
 *   1. Остановить старое — ДО копии, иначе старый агент допишет строки уже после неё:
 *      агенты анализа и DealHealthAgent shef.toolsai снимаются, его подписки на события
 *      полей и скриптов звонков — тоже (теперь их держит этот модуль). Снимается
 *      стандартными вызовами ядра и по строковым именам: классов shef.toolsai модуль не трогает.
 *      Если старый прогон ещё идёт (его блокировка жива), ждём до WAIT_SECONDS.
 *   2. Копия пакетами `INSERT IGNORE ... SELECT` с теми же ID (промпты — по CODE: у них свой
 *      уникальный индекс). Таблицы нет в источнике или в ней нет строк — пропуск.
 *      Повтор добирает недостающее и ничего не затирает.
 *   3. Настройки: под теми же кодами, только если у приёмника значения нет — то, что
 *      администратор поправил уже здесь, не затирается. DEAL_crmevents копируется, но
 *      в shef.toolsai остаётся: оттуда ничего не удаляется.
 *   4. Сверка по каждой таблице: строк источника без пары в приёмнике — 0, суммы ключей сошлись;
 *      у настроек — каждая на месте. Сошлась — отметки: SYS_migrated_from_toolsai в настройках
 *      этого модуля и SYS_crm_moved в настройках shef.toolsai («время|числа»). Только по этой
 *      отметке установщик shef.toolsai убирает свои старые таблицы и настройки.
 *      Не сошлась — ничего не помечается (прежние отметки снимаются: убирать старое по ним
 *      нельзя), старое остаётся как есть, отчёт говорит, что не так.
 *
 * run() исключений не бросает: установка модуля из-за переноса не падает.
 * Класс чистый — ядро только через MigrationPort; проверяется tests/migration_test.php.
 */
final class Migration
{
	/** Модуль-источник: до выноса анализ жил в нём. */
	public const SOURCE_MODULE = 'shef.toolsai';

	/** Строк в пакете копии. */
	public const BATCH = 1000;

	/** Сколько секунд ждать конца прогона старого агента. */
	public const WAIT_SECONDS = 30;

	/**
	 * Таблицы: источник (сырые имена shef.toolsai), приёмник, ключ сверки, префикс CODE.
	 * Имена приёмника повторяют getTableName() классов модуля (держит migration_test.php).
	 *
	 * @var list<array{0: string, 1: string, 2: string, 3: string|null}>
	 */
	public const TABLES = [
		['shef_toolsai_deal_profile', 'shef_toolsaicrm_deal_profile', 'ID', null],
		['shef_toolsai_entity_check', 'shef_toolsaicrm_entity_check', 'ID', null],
		['shef_toolsai_analysis_history', 'shef_toolsaicrm_analysis_history', 'ID', null],
		['shef_toolsai_call_script_rule', 'shef_toolsaicrm_call_script_rule', 'ID', null],
		// Промпты анализа (CODE = «analysis.…»): ключ — CODE, ID приёмник выдаёт сам
		['shef_toolsai_prompt', 'shef_toolsaicrm_prompt', 'CODE', 'analysis.'],
	];

	/** Настройки, которые переносятся по имени. */
	public const OPTIONS = [
		'DEAL_enabled',
		'DEAL_maxperrun',
		'DEAL_interval',
		'DEAL_crmevents',
		'CALLSCRIPT_mode',
		'CALLSCRIPT_fallback',
	];

	/** Настройки, которые переносятся все по префиксу: ANALYSIS_interval_<тип>, ANALYSIS_maxperrun_<тип>. */
	public const OPTION_PREFIXES = ['ANALYSIS_interval_', 'ANALYSIS_maxperrun_'];

	/**
	 * Подписки shef.toolsai, которые переходят к этому модулю: [модуль, событие, класс, метод].
	 * Классы — как их пишет ядро в b_module_to_module (без ведущего «\»); снимаются и с ним.
	 *
	 * @var list<array{0: string, 1: string, 2: string, 3: string}>
	 */
	public const OLD_EVENTS = [
		['main', 'OnUserTypeBuildList', 'Shef\\ToolsAi\\Deal\\Field\\AckType', 'getUserTypeDescription'],
		['main', 'onGetPublicEdit', 'Shef\\ToolsAi\\Deal\\Field\\Handlers', 'onGetPublicEdit'],
		['crm', 'OnBeforeCrmDealUpdate', 'Shef\\ToolsAi\\Deal\\Field\\Handlers', 'onBeforeDealUpdate'],
		['crm', 'OnBeforeCrmLeadUpdate', 'Shef\\ToolsAi\\Deal\\Field\\Handlers', 'onBeforeLeadUpdate'],
		['crm', 'OnBeforeCrmDealAdd', 'Shef\\ToolsAi\\Deal\\Field\\Handlers', 'onBeforeDealAdd'],
		['crm', 'onCrmDynamicItemAdd', 'Shef\\ToolsAi\\Deal\\Field\\Handlers', 'onDynamicItemAdd'],
		['crm', 'OnBeforeCrmActivityAdd', 'Shef\\ToolsAi\\CallScript\\Events', 'onBeforeActivityAdd'],
	];

	public function __construct(private readonly MigrationPort $port)
	{
	}

	/**
	 * Агент анализа shef.toolsai: `\Shef\ToolsAi\Agent\AnalysisAgent::run(<тип>);` и общий
	 * `\Shef\ToolsAi\Agent\DealHealthAgent::run();`. Чистая функция.
	 */
	public static function isOldAnalysisAgent(string $name): bool
	{
		return 1 === preg_match('/^\\\\?Shef\\\\ToolsAi\\\\Agent\\\\(?:AnalysisAgent::run\(\d*\)|DealHealthAgent::run\(\));?$/', trim($name));
	}

	/**
	 * Что переносить из настроек источника: имена по списку и по префиксам. Чистая функция.
	 *
	 * @param array<string, string> $source
	 * @return array<string, string>
	 */
	public static function pickOptions(array $source): array
	{
		$picked = [];
		foreach($source as $name => $value)
		{
			$name = (string)$name;
			$wanted = in_array($name, self::OPTIONS, true);
			foreach(self::OPTION_PREFIXES as $prefix)
			{
				// «ANALYSIS_interval_» без типа — не настройка типа: нужен хотя бы один знак
				$wanted = $wanted || (str_starts_with($name, $prefix) && strlen($name) > strlen($prefix));
			}
			if($wanted)
			{
				$picked[$name] = (string)$value;
			}
		}
		ksort($picked);

		return $picked;
	}

	/**
	 * Отметка переноса: «время|числа». Чистая функция.
	 *
	 * @param array<string, int> $counts
	 */
	public static function stamp(int $now, array $counts): string
	{
		$parts = [];
		foreach($counts as $name => $count)
		{
			$parts[] = $name.'='.$count;
		}

		return date('Y-m-d H:i:s', $now).'|'.implode(',', $parts);
	}

	/**
	 * Сошлась ли сверка таблицы. Чистая функция.
	 *
	 * @param array{source: int, missing: int, sumSource: string, sumTarget: string} $compare
	 */
	public static function isMatched(array $compare): bool
	{
		return $compare['missing'] === 0 && $compare['sumSource'] === $compare['sumTarget'];
	}

	/**
	 * Выполнить перенос.
	 *
	 * @return array{ok: bool, marked: bool, steps: array<string, array{ok: bool, message: string, warn?: bool}>}
	 *         ok — перенос завершён и сверен (или переносить было нечего); marked — отметки поставлены
	 */
	public function run(): array
	{
		$steps = [];
		$hadSource = false;
		$counts = [];

		// 1. Старое — до копии
		$stopped = $this->stopOld();
		$steps['stop old'] = $stopped;
		$allOk = $stopped['ok'];

		// 2. Копия и сверка таблиц
		foreach(self::TABLES as [$source, $target, $key, $prefix])
		{
			try
			{
				$result = $this->copyTable($source, $target, $key, $prefix);
			}
			catch(\Throwable $throwable)
			{
				$result = ['ok' => false, 'message' => 'сбой: '.$throwable->getMessage(), 'had' => true, 'rows' => 0];
			}
			$steps['migrate '.$source] = ['ok' => $result['ok'], 'message' => $result['message']];
			$allOk = $result['ok'] && $allOk;
			if($result['had'])
			{
				$hadSource = true;
				$counts[substr($source, strlen('shef_toolsai_'))] = $result['rows'];
			}
		}

		// 3. Настройки
		try
		{
			$options = $this->copyOptions();
		}
		catch(\Throwable $throwable)
		{
			$options = ['ok' => false, 'message' => 'сбой: '.$throwable->getMessage(), 'had' => true, 'count' => 0];
		}
		$steps['migrate options'] = ['ok' => $options['ok'], 'message' => $options['message']];
		$allOk = $options['ok'] && $allOk;
		if($options['had'])
		{
			$hadSource = true;
			$counts['options'] = $options['count'];
		}

		// 4. Отметки
		$marked = false;
		try
		{
			if(!$allOk)
			{
				$this->unmark();
				$steps['migrate mark'] = ['ok' => false, 'message' => 'сверка не сошлась или старое не остановлено: отметок нет, старые данные не тронуты — повторите «Проверить и включить»'];
			}
			elseif(!$hadSource)
			{
				$steps['migrate mark'] = ['ok' => true, 'message' => 'переносить нечего'];
			}
			else
			{
				$stamp = $this->mark($counts);
				$marked = true;
				$steps['migrate mark'] = ['ok' => true, 'message' => 'сверено, отметка: '.$stamp];
			}
		}
		catch(\Throwable $throwable)
		{
			$steps['migrate mark'] = ['ok' => false, 'message' => 'отметки не поставлены: '.$throwable->getMessage()];
			$allOk = false;
		}

		return ['ok' => $allOk, 'marked' => $marked, 'steps' => $steps];
	}

	/**
	 * Остановить старое: агенты, подписки, ожидание конца идущего прогона.
	 *
	 * @return array{ok: bool, message: string, warn?: bool}
	 */
	public function stopOld(): array
	{
		$agents = 0;
		$events = 0;
		try
		{
			foreach($this->port->agents(self::SOURCE_MODULE) as $agentId => $name)
			{
				if(self::isOldAnalysisAgent($name))
				{
					$this->port->deleteAgent((int)$agentId);
					$agents++;
				}
			}
			foreach(self::OLD_EVENTS as [$from, $event, $class, $method])
			{
				// ядро сравнивает класс строкой как есть: без ведущего «\» и с ним
				foreach([$class, '\\'.$class] as $variant)
				{
					$this->port->unregisterEvent($from, $event, self::SOURCE_MODULE, $variant, $method);
				}
				$events++;
			}

			$waited = 0;
			while($this->port->oldRunInProgress() && $waited < self::WAIT_SECONDS)
			{
				$this->port->sleep(1);
				$waited++;
			}
			if($this->port->oldRunInProgress())
			{
				return [
					'ok' => false,
					'warn' => true,
					'message' => 'агентов снято: '.$agents.', подписок: '.$events.'; прогон старого агента ещё идёт — перенос завершится при следующем запуске',
				];
			}
		}
		catch(\Throwable $throwable)
		{
			return ['ok' => false, 'message' => 'не снято: '.$throwable->getMessage()];
		}

		return ['ok' => true, 'message' => 'агентов shef.toolsai снято: '.$agents.', подписок: '.$events];
	}

	/**
	 * Копия одной таблицы и её сверка.
	 *
	 * @return array{ok: bool, message: string, had: bool, rows: int}
	 */
	private function copyTable(string $source, string $target, string $key, ?string $prefix): array
	{
		if(!$this->port->tableExists($source))
		{
			return ['ok' => true, 'message' => 'таблицы нет в shef.toolsai — пропуск', 'had' => false, 'rows' => 0];
		}
		if(!$this->port->tableExists($target))
		{
			return ['ok' => false, 'message' => 'нет таблицы '.$target.' — модуль не создал её', 'had' => true, 'rows' => 0];
		}

		$sourceColumns = $this->port->columns($source);
		$common = array_values(array_intersect($sourceColumns, $this->port->columns($target)));
		if($key === 'CODE')
		{
			// ID приёмник выдаёт сам: у промптов уникальность держит CODE
			$common = array_values(array_diff($common, ['ID']));
		}
		if(!in_array($key, $common, true) || !in_array('ID', $sourceColumns, true))
		{
			return ['ok' => false, 'message' => 'у таблиц нет общего ключа '.$key, 'had' => true, 'rows' => 0];
		}

		$total = $this->port->compare($source, $target, $key, $prefix);
		if($total['source'] === 0)
		{
			// Таблица есть, строк нет: переносить нечего, но отметка нужна — по ней shef.toolsai уберёт пустую таблицу
			return ['ok' => true, 'message' => 'строк нет — пропуск', 'had' => true, 'rows' => 0];
		}

		$copied = 0;
		$from = 0;
		while(($end = $this->port->batchEnd($source, $prefix, $from, self::BATCH)) !== null)
		{
			$copied += $this->port->copyRange($source, $target, $common, $key, $prefix, $from, $end);
			$from = $end + 1;
		}

		$after = $this->port->compare($source, $target, $key, $prefix);
		if(!self::isMatched($after))
		{
			return [
				'ok' => false,
				'message' => 'сверка не сошлась: строк '.$after['source'].', без пары '.$after['missing'].' (суммы '.$after['sumSource'].' и '.$after['sumTarget'].')',
				'had' => true,
				'rows' => $after['source'],
			];
		}

		return [
			'ok' => true,
			'message' => 'строк '.$after['source'].', добавлено '.$copied.', сверено',
			'had' => true,
			'rows' => $after['source'],
		];
	}

	/**
	 * Настройки: недостающие — копировать, затем сверить, что каждая на месте.
	 *
	 * @return array{ok: bool, message: string, had: bool, count: int}
	 */
	private function copyOptions(): array
	{
		$wanted = self::pickOptions($this->port->options(self::SOURCE_MODULE));
		if($wanted === [])
		{
			return ['ok' => true, 'message' => 'настроек нет — пропуск', 'had' => false, 'count' => 0];
		}

		$present = $this->port->options(Constants::MODULE_ID);
		$copied = 0;
		foreach($wanted as $name => $value)
		{
			// Есть своё значение — не трогаем: его могли поправить уже здесь
			if(!array_key_exists($name, $present))
			{
				$this->port->setOption(Constants::MODULE_ID, $name, $value);
				$copied++;
			}
		}

		$after = $this->port->options(Constants::MODULE_ID);
		$missing = array_values(array_filter(array_keys($wanted), static fn(string $name): bool => !array_key_exists($name, $after)));
		if($missing !== [])
		{
			return ['ok' => false, 'message' => 'не перенесены: '.implode(', ', $missing), 'had' => true, 'count' => count($wanted)];
		}

		return ['ok' => true, 'message' => 'настроек '.count($wanted).', добавлено '.$copied.', своих оставлено '.(count($wanted) - $copied), 'had' => true, 'count' => count($wanted)];
	}

	/**
	 * Поставить отметки. Прежняя отметка этого модуля сохраняется (время первого переноса).
	 *
	 * @param array<string, int> $counts
	 */
	private function mark(array $counts): string
	{
		$own = $this->port->options(Constants::MODULE_ID);
		$stamp = (string)($own[Constants::OPTION_MIGRATED] ?? '');
		if($stamp === '')
		{
			$stamp = self::stamp($this->port->now(), $counts);
		}
		$this->port->setOption(Constants::MODULE_ID, Constants::OPTION_MIGRATED, $stamp);
		$this->port->setOption(self::SOURCE_MODULE, Constants::CORE_OPTION_MIGRATED, $stamp);

		return $stamp;
	}

	/** Сверка не сошлась: прежние отметки не должны разрешать shef.toolsai убирать старое. */
	private function unmark(): void
	{
		$this->port->deleteOption(self::SOURCE_MODULE, Constants::CORE_OPTION_MIGRATED);
		$this->port->deleteOption(Constants::MODULE_ID, Constants::OPTION_MIGRATED);
	}
}
