<?php declare(strict_types=1);

/**
 * Модель-заглушка для тестов: без сети, без shef.toolsai.
 *
 * Тот же вердикт, что отдавала EchoLlm ядра: чем дольше сделка без дел, тем
 * выше риск (сделка без дел 14+ дней — риск 80 и «звать старшего»).
 * Тесты подставляют её вместо Core\Model (Container::override или аргумент
 * HealthAnalyzer).
 */

namespace Shef\ToolsAiCrm\Tests;

use Shef\ToolsAiCrm\Core\ModelInterface;
use Shef\ToolsAiCrm\Core\ModelResult;

final class EchoModel implements ModelInterface
{
	public function getCode(): string
	{
		return 'echo';
	}

	public function completeJson(string $system, string $user, array $schema, ?array $check = null): ModelResult
	{
		$idle = preg_match('/Дней без активности: (\d+)/u', $user, $match) ? (int)$match[1] : 0;
		$risk = min(95, $idle * 5 + 10);

		$json = [
			'risk' => $risk,
			'needSenior' => $risk >= 70,
			'why' => '[заглушка] Дней без активности: '.$idle.'.',
			'nextStep' => '[заглушка] Позвонить клиенту и договориться о следующем шаге.',
		];
		if(isset($schema['properties']['managerScore']))
		{
			$json['managerScore'] = -1;
			$json['managerReview'] = '[заглушка] Работа менеджера не оценивалась.';
		}

		return new ModelResult($json);
	}
}
