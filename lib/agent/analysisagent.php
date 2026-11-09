<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Agent;

use Bitrix\Main\Loader;
use Shef\Options\Main\TempFile\Pid;
use Shef\ToolsAiCrm\Analysis\Candidates;
use Shef\ToolsAiCrm\Analysis\EntityFields;
use Shef\ToolsAiCrm\Analysis\EntityType;
use Shef\ToolsAiCrm\Analysis\EntityTypes;
use Shef\ToolsAiCrm\Analysis\History;
use Shef\ToolsAiCrm\Analysis\Model\EntityCheckTable;
use Shef\ToolsAiCrm\Analysis\Model\HistoryTable;
use Shef\ToolsAiCrm\Analysis\Runner;
use Shef\ToolsAiCrm\Container;
use Shef\ToolsAiCrm\Deal\Model\DealProfileTable;
use Shef\ToolsAiCrm\Main\Constants;

// shef.options — Pid (блокировка прогона) и Constants служебного пользователя.
if(!\Bitrix\Main\Loader::includeModule('shef.options'))
{
	return;
}

/**
 * Агент анализа одного типа сущности (1.9.1): сделки, лиды или один
 * смарт-процесс. Имя агента — `AnalysisAgent::run(<entityTypeId>);`
 * (Main\Setup::analysisAgentName()), у каждого типа:
 *
 *   - свой интервал — Config::getAnalysisInterval() (по умолчанию DEAL_interval, час);
 *   - свой лимит анализов за прогон — Config::getAnalysisMaxPerRun()
 *     (по умолчанию DEAL_maxperrun, 50);
 *   - своя блокировка — группа Constants::LOCK_GROUP_ANALYSIS_PREFIX + тип.
 *
 * Агентов заводит и снимает «Проверить и включить» (Main\Setup::ensureAnalysisAgents())
 * по включённым профилям. Работают — только при включённом анализе
 * (DEAL_enabled) и хотя бы одном включённом профиле своего типа.
 *
 * ВАЖНО ПРО РАСХОД: агенты — главный потребитель квоты. Ограничители:
 *   - дешёвый фильтр DealFacts::isWorthAnalyzing() ДО обращения к модели
 *     (IDLE_DAYS профиля);
 *   - не больше лимита типа анализов за прогон;
 *   - повторный анализ одной записи не чаще раза в REANALYZE_DAYS профиля;
 *   - запись без подходящего профиля модель не зовёт вовсе.
 *
 * Проход по записям — общий блок (Analysis\Runner), выборка — Analysis\Candidates.
 */
final class AnalysisAgent
{

	/** Во сколько раз больше кандидатов просматривать, чем анализировать. */
	public const SCAN_FACTOR = 5;

	/** Точка входа агента. Возвращает свой вызов для следующего запуска. */
	public static function run(int $entityTypeId = EntityType::DEAL): string
	{
		$self = '\\'.static::class.'::run('.$entityTypeId.');';

		if(!Container::getConfig()->isDealHealthEnabled() || !Loader::includeModule('crm') || !Container::isLicensed())
		{
			return $self;
		}

		// До лимита запросов к модели по 2-4 с, а при сбоях — до таймаута
		// провайдера: прогон идёт минуты. Агент на хитах (не на cron) иначе
		// упёрся бы в max_execution_time посреди записи — оплаченный ответ без
		// записи о проверке. Рекомендация — агенты на cron (docs/guide/12-install-ops.md).
		if(function_exists('set_time_limit'))
		{
			@set_time_limit(0);
		}
		if(function_exists('ignore_user_abort'))
		{
			@ignore_user_abort(true);
		}

		// История риска: старше History::KEEP_DAYS — прочь. Один DELETE по
		// индексу даты; сбой анализ не рвёт.
		try
		{
			HistoryTable::cleanup(History::KEEP_DAYS);
		}
		catch(\Throwable $throwable)
		{
			Container::getLogger(static::class)?->error($throwable);
		}

		try
		{
			static::runLocked($entityTypeId, static fn(): array => static::process($entityTypeId));
		}
		catch(\Throwable $throwable)
		{
			Container::getLogger(static::class)?->error($throwable);
		}

		return $self;
	}

	/** Группа блокировки агента типа. */
	public static function lockGroup(int $entityTypeId): string
	{
		return Constants::LOCK_GROUP_ANALYSIS_PREFIX.$entityTypeId;
	}

	/**
	 * Выполнить под блокировкой типа и от служебного пользователя. Этим
	 * пользуются и агент, и cli/deal-health.php: ручной прогон рядом с
	 * агентом платил бы за те же записи дважды.
	 *
	 * @param callable(): array $work
	 * @return array|null null — прогон этого типа уже идёт в другом процессе
	 */
	public static function runLocked(int $entityTypeId, callable $work): ?array
	{
		$group = static::lockGroup($entityTypeId);
		$lock = new Pid($group);
		if(!$lock->add())
		{
			return null;
		}

		try
		{
			// После add() в каталоге группы только живые блокировки: больше
			// одной — прогон уже идёт в другом процессе.
			if(count(glob(Pid::getBasePath($group).'/*.lock') ?: []) > 1)
			{
				return null;
			}

			// Без подмены пользователя (1.64.6, #291): FixUser писал служебного в сессию
			// посетителя на хите; права CRM в работе агента сняты явно (фабрика, дела)
			return $work();
		}
		finally
		{
			$lock->remove();
		}
	}

	/**
	 * Один прогон по типу. Звать через runLocked().
	 *
	 * @param int[]|null $onlyIds только эти записи, без выборки кандидатов и срока повтора
	 * @return array<int, array{risk: int, needSenior: bool, skipped: bool, why: string, escalated: string[], error: string, profile: int, action: string, costMicro: int}>
	 */
	public static function process(int $entityTypeId, ?array $onlyIds = null): array
	{
		$config = Container::getConfig();

		// Обновление без установщика: таблицы модуля должны быть на месте
		DealProfileTable::init();
		EntityCheckTable::init();

		$type = EntityTypes::get($entityTypeId);
		$profiles = DealProfileTable::getProfiles(true, $entityTypeId);
		if($type === null || $profiles === [])
		{
			return [];
		}

		// Поля «AI: …» (bx-shef/toolsai#281): создаёт «Проверить и включить», но кнопку после
		// установки или нового смарт-процесса забывают — анализ шёл, а в карточку не писалось ничего.
		// Недостающие создаём здесь; существующие не трогаем (EntityFields::ensure идемпотентен).
		$fields = EntityFields::ensure($type);
		if(!$fields['ok'])
		{
			Container::getLogger(static::class)?->warning('Поля «AI: …» для '.$type->ufEntityId.': '.$fields['message']);
		}

		$limit = $config->getAnalysisMaxPerRun($entityTypeId);
		$ids = $onlyIds ?? Candidates::get($type, $profiles, $limit * self::SCAN_FACTOR, Container::getAgentUserId());

		return (new Runner($type))->process($ids, $profiles, $limit, $onlyIds === null);
	}
}
