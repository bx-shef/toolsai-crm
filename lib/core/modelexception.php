<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Core;

/**
 * Модель не ответила или ответ негоден. Журнал расхода уже записан shef.toolsai
 * (LlmService), здесь — только причина и цена оплаченного, но негодного ответа.
 */
final class ModelException extends \RuntimeException
{
	/** Коды, при которых виноват провайдер, квота или лицензия, а не запись CRM. */
	public const DOWN_CODES = [
		'provider_unavailable', 'provider_timeout', 'provider_rate_limit', 'provider_auth',
		'no_model', 'quota_exceeded', 'no_license', 'internal_error',
	];

	public function __construct(
		string $message,
		/** Код провайдера (provider_timeout и т. п.) или LlmService::ERROR_*. */
		public readonly string $errorCode = '',
		public readonly int $spentMicro = 0,
		public readonly int $spentUnits = 0,
	)
	{
		parent::__construct($message);
	}

	public function isDown(): bool
	{
		return in_array($this->errorCode, self::DOWN_CODES, true);
	}
}
