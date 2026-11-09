<?php declare(strict_types=1);

/**
 * Заглушка публичного API движка shef.toolsai для тестов без портала.
 *
 * Модуль ходит в движок только через Shef\ToolsAi\Public\Service\LlmService и
 * LlmResponse (completeJson, allow, model). Настоящие классы тянут внутренности
 * shef.toolsai (провайдеры, квоту, журнал расхода), которых в этом репозитории
 * нет; тесты подставляют заглушку с теми же именами, константами и формой
 * ответа. Сам договор API держит publicapi_test.php shef.toolsai.
 *
 * LlmService здесь — по сценарию: тест задаёт, что вернут completeJson/allow/model,
 * и читает, с чем их позвали ($calls).
 */

namespace Shef\ToolsAi\Public\Service
{
	use Bitrix\Main\Error;
	use Bitrix\Main\Result;

	final class LlmResponse extends Result
	{
		public function __construct(
			private readonly string $text = '',
			private readonly string $finishReason = '',
			private readonly int $costMicro = 0,
			private readonly int $tokens = 0,
			private readonly ?array $json = null,
			private readonly string $providerCode = '',
		)
		{
			parent::__construct();
			$this->setData([
				'text' => $text,
				'finishReason' => $finishReason,
				'costMicro' => $costMicro,
				'tokens' => $tokens,
				'json' => $json,
				'providerCode' => $providerCode,
			]);
		}

		public static function failed(string $code, string $message, int $costMicro = 0, int $tokens = 0, string $providerCode = ''): self
		{
			$response = new self('', '', $costMicro, $tokens, null, $providerCode);
			$response->addError(new Error($message, $code));

			return $response;
		}

		public function getText(): string
		{
			return $this->text;
		}

		public function getFinishReason(): string
		{
			return $this->finishReason;
		}

		public function getCostMicro(): int
		{
			return $this->costMicro;
		}

		public function getTokens(): int
		{
			return $this->tokens;
		}

		public function getJson(): ?array
		{
			return $this->json;
		}

		public function getProviderCode(): string
		{
			return $this->providerCode;
		}

		public function getErrorCode(): string
		{
			$errors = $this->getErrors();

			return $errors === [] ? '' : (string)$errors[0]->getCode();
		}
	}

	final class LlmService
	{
		public const DIRECTION_ANALYSIS = 'deal';

		public const ERROR_CATEGORY = 'bad_category';
		public const ERROR_MESSAGES = 'bad_messages';
		public const ERROR_NO_MODEL = 'no_model';
		public const ERROR_QUOTA = 'quota_exceeded';
		public const ERROR_PROVIDER = 'provider_error';
		public const ERROR_EMPTY = 'empty_answer';
		public const ERROR_INTERNAL = 'internal_error';
		public const ERROR_AMOUNT = 'bad_amount';
		public const ERROR_NO_LICENSE = 'no_license';
		public const ERROR_CONTEXT = 'context_overflow';
		public const ERROR_TIMEOUT = 'timeout';

		/** @var list<array{method: string, args: array}> вызовы по порядку */
		public array $calls = [];

		/**
		 * @param \Closure|null $completeJson (category, system, user, schema, check, userId, direction): LlmResponse
		 * @param \Closure|null $allow (category): LlmResponse; по умолчанию — можно
		 * @param string $model имя модели направления
		 */
		public function __construct(
			private readonly ?\Closure $completeJson = null,
			private readonly ?\Closure $allow = null,
			private readonly string $model = 'test-model',
		)
		{
		}

		public function completeJson(string $category, string $system, string $user, array $schema, ?array $check = null, ?int $userId = null, ?string $direction = null): LlmResponse
		{
			$this->calls[] = ['method' => 'completeJson', 'args' => func_get_args()];

			return $this->completeJson !== null
				? ($this->completeJson)($category, $system, $user, $schema, $check, $userId, $direction)
				: LlmResponse::failed(self::ERROR_NO_MODEL, 'модель не подключена');
		}

		public function allow(string $category): LlmResponse
		{
			$this->calls[] = ['method' => 'allow', 'args' => func_get_args()];

			return $this->allow !== null ? ($this->allow)($category) : new LlmResponse();
		}

		public function model(string $category, ?string $direction = null): string
		{
			$this->calls[] = ['method' => 'model', 'args' => func_get_args()];

			return $this->model;
		}
	}
}
