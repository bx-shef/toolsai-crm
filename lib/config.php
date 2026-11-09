<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm;

use Shef\ToolsAiCrm\Main\Constants;
use Shef\ToolsAiCrm\Main\OptionParser;

/**
 * Настройки модуля: агент анализа, запись в CRM, скрипты звонков.
 *
 * Коды те же, что были у shef.toolsai (DEAL_*, ANALYSIS_*): значения переносит
 * установщик. Чтение — через $reader (тесты подставляют свой); по умолчанию —
 * Option::get модуля, умолчания — из default_option.php.
 */
final class Config
{
	public const DEFAULT_MAX_PER_RUN = 50;
	public const DEFAULT_AGENT_INTERVAL_MINUTES = 60;
	public const AGENT_INTERVAL_MIN = 10;
	public const AGENT_INTERVAL_MAX = 10080;

	/** Префиксы своих настроек агента анализа типа: ANALYSIS_interval_{тип}, ANALYSIS_maxperrun_{тип}. */
	public const OPTION_ANALYSIS_INTERVAL = 'ANALYSIS_interval_';
	public const OPTION_ANALYSIS_MAX_PER_RUN = 'ANALYSIS_maxperrun_';

	/** @var \Closure(string): mixed */
	private readonly \Closure $reader;

	/**
	 * @param (\Closure(string): mixed)|null $reader имя настройки -> значение; null — Option::get модуля
	 */
	public function __construct(?\Closure $reader = null)
	{
		$this->reader = $reader ?? static fn(string $name): mixed => \Bitrix\Main\Config\Option::get(Constants::MODULE_ID, $name);
	}

	private function get(string $name): mixed
	{
		return ($this->reader)($name);
	}

	/** Агент анализа включён (DEAL_enabled). */
	public function isDealHealthEnabled(): bool
	{
		return OptionParser::flag($this->get('DEAL_enabled'));
	}

	/** Запись модуля в CRM запускает роботы, БП и историю (DEAL_crmevents; по умолчанию нет). */
	public function isCrmEventsEnabled(): bool
	{
		return OptionParser::flag($this->get(Constants::OPTION_CRMEVENTS));
	}

	public function getMaxPerRun(): int
	{
		return OptionParser::int($this->get('DEAL_maxperrun'), self::DEFAULT_MAX_PER_RUN, 1, 500);
	}

	/** Анализов за прогон агента типа. Своя настройка пуста — общая DEAL_maxperrun (по умолчанию 50). */
	public function getAnalysisMaxPerRun(int $entityTypeId): int
	{
		return OptionParser::int($this->get(self::OPTION_ANALYSIS_MAX_PER_RUN.$entityTypeId), $this->getMaxPerRun(), 1, 500);
	}

	/** Интервал агента анализа типа в секундах: минуты, 10..10080. Своя настройка пуста — общая DEAL_interval (по умолчанию час). */
	public function getAnalysisInterval(int $entityTypeId): int
	{
		return 60 * OptionParser::int($this->get(self::OPTION_ANALYSIS_INTERVAL.$entityTypeId), intdiv($this->getAgentInterval(), 60), self::AGENT_INTERVAL_MIN, self::AGENT_INTERVAL_MAX);
	}

	/** Свои настройки агента типа как есть (для формы): '' — не задано. */
	public function getAnalysisAgentRaw(int $entityTypeId): array
	{
		return [
			'interval' => trim((string)$this->get(self::OPTION_ANALYSIS_INTERVAL.$entityTypeId)),
			'maxPerRun' => trim((string)$this->get(self::OPTION_ANALYSIS_MAX_PER_RUN.$entityTypeId)),
		];
	}

	/** Интервал агента анализа в секундах: DEAL_interval — минуты, 10..10080 (неделя). */
	public function getAgentInterval(): int
	{
		return 60 * OptionParser::int($this->get('DEAL_interval'), self::DEFAULT_AGENT_INTERVAL_MINUTES, self::AGENT_INTERVAL_MIN, self::AGENT_INTERVAL_MAX);
	}
}
