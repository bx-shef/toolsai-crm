<?php declare(strict_types=1);

/**
 * Модель анализа (Core\Model) поверх публичного API shef.toolsai — без портала.
 *
 * Что держит:
 *
 * * успех: JSON, токены и цена ответа уходят в ModelResult; в LlmService
 *   completeJson зовётся с категорией модуля, направлением «Анализ сделок»,
 *   схемой, проверкой полей и служебным пользователем агента;
 * * ошибка: ModelException с текстом движка, кодом (код провайдера главнее
 *   кода ответа) и ЦЕНОЙ оплаченного, но негодного ответа — Runner кладёт её
 *   в отчёт; расход в журнал уже записал LlmService;
 * * таймаут (ERROR_TIMEOUT) — код provider_timeout, а значит isDown();
 * * isDown() — по коду: провайдер, квота, лицензия, нет модели — да;
 *   негодный ответ — нет;
 * * имя модели — из LlmService::model(), без имени — «shef.toolsai».
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAi\Public\Service\LlmResponse;
use Shef\ToolsAi\Public\Service\LlmService;
use Shef\ToolsAiCrm\Core\Model;
use Shef\ToolsAiCrm\Core\ModelException;
use Shef\ToolsAiCrm\Main\Constants;

$schema = ['type' => 'object', 'properties' => ['risk' => ['type' => 'integer']]];
$check = ['type' => 'object', 'required' => ['risk']];

$caught = static function(\Closure $call): ?ModelException
{
	try
	{
		$call();
	}
	catch(ModelException $exception)
	{
		return $exception;
	}

	return null;
};

Check::group('успех');

$service = new LlmService(static fn(): LlmResponse => new LlmResponse('{"risk":40}', 'stop', 1500, 320, ['risk' => 40]));
$result = (new Model($service, 7))->completeJson('система', 'пользователь', $schema, $check);
Check::same('JSON, токены, цена', [$result->json, $result->getTokens(), $result->costMicro], [['risk' => 40], 320, 1500]);
Check::same('вызов движка: категория модуля, направление анализа, схема, проверка, пользователь', $service->calls[0]['args'], [Constants::CATEGORY, 'система', 'пользователь', $schema, $check, 7, LlmService::DIRECTION_ANALYSIS]);

$service = new LlmService(static fn(): LlmResponse => new LlmResponse('{}', '', 0, 0, ['risk' => 1]));
(new Model($service))->completeJson('s', 'u', $schema);
Check::same('без пользователя и проверки — null, null', [$service->calls[0]['args'][4], $service->calls[0]['args'][5]], [null, null]);

Check::group('ошибка: ModelException с ценой');

$service = new LlmService(static fn(): LlmResponse => LlmResponse::failed(LlmService::ERROR_PROVIDER, 'Ответ без поля risk', 123, 45, 'provider_bad_response'));
$exception = $caught(static fn() => (new Model($service))->completeJson('s', 'u', $schema));
Check::same('код провайдера главнее; цена и токены оплаченного ответа — в исключении', [$exception?->getMessage(), $exception?->errorCode, $exception?->spentMicro, $exception?->spentUnits], ['Ответ без поля risk', 'provider_bad_response', 123, 45]);
Check::same('негодный ответ — не isDown (запись помечается проверенной)', $exception?->isDown(), false);

$service = new LlmService(static fn(): LlmResponse => LlmResponse::failed(LlmService::ERROR_EMPTY, 'Пустой ответ', 0, 0));
$exception = $caught(static fn() => (new Model($service))->completeJson('s', 'u', $schema));
Check::same('без кода провайдера — код ответа движка', [$exception?->errorCode, $exception?->isDown()], [LlmService::ERROR_EMPTY, false]);

$service = new LlmService(static fn(): LlmResponse => LlmResponse::failed(LlmService::ERROR_PROVIDER, 'Нет ответа', 0, 0, 'provider_unavailable'));
$exception = $caught(static fn() => (new Model($service))->completeJson('s', 'u', $schema));
Check::same('провайдер недоступен — isDown', [$exception?->errorCode, $exception?->isDown()], ['provider_unavailable', true]);

Check::group('таймаут');

$service = new LlmService(static fn(): LlmResponse => LlmResponse::failed(LlmService::ERROR_TIMEOUT, 'Модель не успела ответить', 0, 0, 'provider_bad_response'));
$exception = $caught(static fn() => (new Model($service))->completeJson('s', 'u', $schema));
Check::same('таймаут — provider_timeout (даже если провайдер прислал другой код) и isDown', [$exception?->errorCode, $exception?->isDown(), $exception?->getMessage()], ['provider_timeout', true, 'Модель не успела ответить']);

Check::group('квота и лицензия — isDown');

foreach([LlmService::ERROR_QUOTA, LlmService::ERROR_NO_LICENSE, LlmService::ERROR_NO_MODEL, LlmService::ERROR_INTERNAL] as $code)
{
	$service = new LlmService(static fn(): LlmResponse => LlmResponse::failed($code, 'отказ'));
	$exception = $caught(static fn() => (new Model($service))->completeJson('s', 'u', $schema));
	Check::same('код '.$code, [$exception?->errorCode, $exception?->isDown()], [$code, true]);
}

Check::group('имя модели');

Check::same('из движка, для направления анализа', [(new Model(new LlmService(null, null, 'test-model')))->getCode(), (new LlmService(null, null, 'x'))->calls], ['test-model', []]);
$service = new LlmService(null, null, 'm1');
(new Model($service))->getCode();
Check::same('model(категория, направление)', $service->calls[0]['args'], [Constants::CATEGORY, LlmService::DIRECTION_ANALYSIS]);
Check::same('имя пусто — «shef.toolsai»', (new Model(new LlmService(null, null, '')))->getCode(), 'shef.toolsai');

Check::finish();
