<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Core;

/**
 * Модель анализа для HealthAnalyzer: один запрос с JSON-схемой.
 *
 * Единственная реализация — Core\Model, она ходит в публичный API shef.toolsai
 * (LlmService::completeJson); тесты подставляют свою.
 */
interface ModelInterface
{
	/** Подпись модели для отчётов (код провайдера или название). */
	public function getCode(): string;

	/**
	 * @param array $schema JSON-схема ответа
	 * @param array|null $check поля, которые обязаны быть в ответе (как у LlmProviderInterface::completeJson)
	 * @throws ModelException модель не ответила или ответ негоден; цена, если ответ оплачен, — в исключении
	 */
	public function completeJson(string $system, string $user, array $schema, ?array $check = null): ModelResult;
}
