<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Deal;

use Shef\ToolsAiCrm\Core\ModelResult;

/**
 * Итог анализа: вердикт и, если модель звали, её ответ с ценой.
 */
final class Analysis
{
	public function __construct(
		public readonly Verdict $verdict,
		public readonly ?ModelResult $llm = null,
		/** Факты, по которым шёл анализ: ответственный, запланированные дела. */
		public readonly ?DealFacts $facts = null,
		/** Профиль анализа; null — не нашлось. */
		public readonly ?Profile $profile = null,
	)
	{
	}
}
