<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Core;

use Shef\ToolsAi\Public\Service\LlmService;
use Shef\ToolsAiCrm\Main\Constants;

/**
 * Модель направления «Анализ сделок» shef.toolsai — через его публичный API.
 *
 * Журнал расхода, квоту и лицензию ведёт LlmService::completeJson(): здесь их
 * не дублируем. Размышления модели (API_dealthinking) сервис берёт сам.
 */
final class Model implements ModelInterface
{
	public function __construct(
		private readonly ?LlmService $service = null,
		private readonly ?int $userId = null,
	)
	{
	}

	public function getCode(): string
	{
		$name = ($this->service ?? new LlmService())->model(Constants::CATEGORY, LlmService::DIRECTION_ANALYSIS);

		return $name !== '' ? $name : 'shef.toolsai';
	}

	public function completeJson(string $system, string $user, array $schema, ?array $check = null): ModelResult
	{
		$response = ($this->service ?? new LlmService())->completeJson(
			Constants::CATEGORY,
			$system,
			$user,
			$schema,
			$check,
			$this->userId,
			LlmService::DIRECTION_ANALYSIS,
		);
		if(!$response->isSuccess())
		{
			$code = $response->getProviderCode() !== '' ? $response->getProviderCode() : $response->getErrorCode();
			if($response->getErrorCode() === LlmService::ERROR_TIMEOUT)
			{
				$code = 'provider_timeout';
			}

			throw new ModelException(implode('; ', $response->getErrorMessages()), $code, $response->getCostMicro(), $response->getTokens());
		}

		return new ModelResult($response->getJson(), $response->getTokens(), 0, $response->getCostMicro());
	}
}
