<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Core;

/**
 * Ответ модели и его цена: то, что нужно анализу.
 */
final class ModelResult
{
	public function __construct(
		/** Разобранный JSON ответа. */
		public readonly ?array $json = null,
		public readonly int $tokensIn = 0,
		public readonly int $tokensOut = 0,
		public readonly int $costMicro = 0,
	)
	{
	}

	public function getTokens(): int
	{
		return $this->tokensIn + $this->tokensOut;
	}
}
