<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm;

use Psr\Log\LoggerInterface;
use Shef\ToolsAi\Public\Service\LlmResponse;
use Shef\ToolsAi\Public\Service\LlmService;
use Shef\ToolsAiCrm\Core\Model;
use Shef\ToolsAiCrm\Core\ModelInterface;
use Shef\ToolsAiCrm\Deal\ContextBuilder;
use Shef\ToolsAiCrm\Deal\Escalation;
use Shef\ToolsAiCrm\Deal\HealthAnalyzer;
use Shef\ToolsAiCrm\Main\Constants;

/**
 * Сборка зависимостей модуля. Движок — модель, расход, квота, лицензия — берётся
 * из публичного API shef.toolsai (LlmService); больше модуль в его классы не ходит.
 */
final class Container
{
	private static ?Config $config = null;
	private static ?LlmService $llmService = null;
	private static ?ModelInterface $model = null;

	public static function getConfig(): Config
	{
		return self::$config ??= new Config();
	}

	/** Подмена для тестов (@internal); без аргументов — вернуть настоящие. */
	public static function override(?Config $config = null, ?LlmService $llmService = null, ?ModelInterface $model = null): void
	{
		self::$config = $config;
		self::$llmService = $llmService;
		self::$model = $model;
	}

	public static function getLlmService(): LlmService
	{
		return self::$llmService ??= new LlmService();
	}

	/** Модель направления «Анализ сделок» shef.toolsai. */
	public static function getModel(): ModelInterface
	{
		return self::$model ?? new Model(self::getLlmService(), self::getAgentUserId() ?: null);
	}

	/** Можно ли тратить: лицензия shef.toolsai действует и месячная квота не исчерпана (LlmService::allow). */
	public static function allow(): LlmResponse
	{
		return self::getLlmService()->allow(Constants::CATEGORY);
	}

	/** Лицензия shef.toolsai действует (причина отказа — licenseReason()). */
	public static function isLicensed(): bool
	{
		return self::allow()->getErrorCode() !== LlmService::ERROR_NO_LICENSE;
	}

	/** Причина «нет лицензии» словами; '' — лицензия действует. */
	public static function licenseReason(): string
	{
		$response = self::allow();

		return $response->getErrorCode() === LlmService::ERROR_NO_LICENSE ? implode(' ', $response->getErrorMessages()) : '';
	}

	/** Анализатор блока анализа; без типа — сделка. */
	public static function getHealthAnalyzer(?Analysis\EntityType $type = null): HealthAnalyzer
	{
		return new HealthAnalyzer(new ContextBuilder(self::getAgentUserId(), $type), self::getModel());
	}

	/** Служебный пользователь агента (shef.options); нет модуля — 0. */
	public static function getAgentUserId(): int
	{
		return class_exists(\Shef\Options\Main\Constants::class) ? (int)\Shef\Options\Main\Constants::getSystemUserId() : 0;
	}

	public static function getEscalation(): Escalation
	{
		return new Escalation();
	}

	/**
	 * Логгер проблем shef.problems: сбой ложится в журнал событий Битрикс24
	 * с типом «Продажи и CRM» и ответственным из его настроек — без файла
	 * каталога логов. Без shef.problems — null: логгер не повод ронять анализ.
	 */
	public static function getLogger(string $className = ''): ?LoggerInterface
	{
		try
		{
			if(!\Bitrix\Main\Loader::includeModule('shef.problems'))
			{
				return null;
			}

			$logger = \Shef\Problems\Factory\SystemLoggerFactory::build(
				logLevel: \Monolog\Level::Warning,
				auditType: \Shef\Problems\Main\Constants::AuditTypeSale,
				moduleId: Constants::MODULE_ID,
				className: $className !== '' ? $className : self::class,
				assigned: \Shef\Problems\Main\Constants::getSaleUserId(),
			);
			$logger->setHandlers(Log\Handlers::withoutFiles($logger->getHandlers()));

			return $logger;
		}
		catch(\Throwable)
		{
			return null;
		}
	}
}
