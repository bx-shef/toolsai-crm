<?php declare(strict_types=1);

/**
 * Прогон анализа (Analysis\Runner) на шве к shef.toolsai — без портала.
 *
 * Что держит:
 *
 * * остановка по квоте и по лицензии: решение даёт LlmService::allow() (через
 *   Container::allow()), код остановки — quota или license, причина — словами
 *   движка; модель не зовётся, записей о проверке нет; ручному прогону по ID
 *   записи, до которых не дошло, отдаются со строкой stop;
 * * лицензия или квота кончились посреди прогона — первая запись обработана,
 *   вторая нет;
 * * ModelException: провайдер лежит (isDown) — запись НЕ помечается
 *   проверенной, три сбоя подряд останавливают прогон; негодный ответ
 *   (не isDown) — запись помечается проверенной с ошибкой, цена оплаченного
 *   ответа попадает в отчёт;
 * * расход Runner сам не пишет: журнал и квоту ведёт LlmService внутри
 *   completeJson — Runner только спрашивает allow() и пишет свои таблицы
 *   (проверка, история).
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Type\DateTime;
use Shef\ToolsAi\Public\Service\LlmResponse;
use Shef\ToolsAi\Public\Service\LlmService;
use Shef\ToolsAiCrm\Analysis\EntityType;
use Shef\ToolsAiCrm\Analysis\Model\EntityCheckTable;
use Shef\ToolsAiCrm\Analysis\Model\HistoryTable;
use Shef\ToolsAiCrm\Analysis\Runner;
use Shef\ToolsAiCrm\Container;
use Shef\ToolsAiCrm\Core\ModelException;
use Shef\ToolsAiCrm\Core\ModelInterface;
use Shef\ToolsAiCrm\Core\ModelResult;
use Shef\ToolsAiCrm\Deal\DealFacts;
use Shef\ToolsAiCrm\Deal\FactsSourceInterface;
use Shef\ToolsAiCrm\Deal\Profile;

/** Источник фактов: сделка без дел 20 дней — модель позвать стоит. */
$source = new class implements FactsSourceInterface {
	public function build(int $dealId): DealFacts
	{
		return new DealFacts(
			dealId: $dealId, title: 'Сделка '.$dealId, stage: 'Переговоры', opportunity: 1000.0, currency: 'BYN',
			daysSinceCreated: 40, daysSinceLastActivity: 20, stageRollbacks: 0, callsTotal: 2, callsIncoming: 1,
			outgoingWithoutAnswer: 0, recentNotes: ['01.09.2026, Звонок: просил скидку'], assignedById: 7,
		);
	}
};

/** Модель по сценарию: каждый вызов — следующий пункт; Throwable — бросить. */
$scriptedModel = static fn(array $script): object => new class($script) implements ModelInterface {
	public int $calls = 0;

	public function __construct(private array $script) {}

	public function getCode(): string
	{
		return 'scripted';
	}

	public function completeJson(string $system, string $user, array $schema, ?array $check = null): ModelResult
	{
		$step = $this->script[min($this->calls, count($this->script) - 1)];
		$this->calls++;
		if($step instanceof \Throwable)
		{
			throw $step;
		}

		return $step;
	}
};

/** LlmService: allow() по списку ответов (последний повторяется); больше ничего не нужно. */
$serviceWith = static fn(array $allow): LlmService => (function() use ($allow): LlmService
{
	$i = 0;

	return new LlmService(null, static function(string $category) use (&$i, $allow): LlmResponse
	{
		return $allow[min($i++, count($allow) - 1)];
	});
})();

$ok = static fn(): LlmResponse => new LlmResponse();
$quota = static fn(): LlmResponse => LlmResponse::failed(LlmService::ERROR_QUOTA, 'Месячная квота исчерпана.');
$noLicense = static fn(): LlmResponse => LlmResponse::failed(LlmService::ERROR_NO_LICENSE, 'Срок лицензии закончился.');

$low = new ModelResult(['risk' => 10, 'needSenior' => false, 'why' => 'Всё идёт', 'nextStep' => 'Ждать'], 150, 50, 500);
$profiles = [Profile::fromRow(['ID' => 4, 'TITLE' => 'Общий', 'IS_ENABLED' => 'Y', 'IDLE_DAYS' => '3', 'CATEGORY_ID' => '-1'])];
$written = static fn(string $table): array => \Bitrix\Main\ORM\Data\DataManager::$written[$table] ?? [];

$run = static function(array $ids, ModelInterface $model, LlmService $service, bool $checkDue = true) use ($source, $profiles): array
{
	\Bitrix\Main\ORM\Data\DataManager::$written = [];
	Container::override(null, $service, $model, $source);
	$runner = new Runner(EntityType::deal());
	$report = $runner->process($ids, $profiles, 50, $checkDue);

	return [$runner, $report];
};

Check::group('шов: подмена в Container');

Container::override(null, $serviceWith([$quota()]), null, null);
Check::same('Container::allow() — ответ LlmService::allow()', [Container::allow()->isSuccess(), Container::allow()->getErrorCode()], [false, LlmService::ERROR_QUOTA]);
Check::same('isLicensed(): квота — лицензия есть', Container::isLicensed(), true);
Container::override(null, $serviceWith([$noLicense()]), null, null);
Check::same('isLicensed(): нет лицензии', [Container::isLicensed(), Container::licenseReason()], [false, 'Срок лицензии закончился.']);
Container::override();

Check::group('остановка по квоте');

$model = $scriptedModel([$low]);
[$runner, $report] = $run([1, 2], $model, $serviceWith([$quota()]));
Check::same('прогон остановлен с кодом quota и причиной движка', [$runner->stopped, $runner->stopCode, $runner->stopReason], [true, Runner::STOP_QUOTA, 'Месячная квота исчерпана.']);
Check::same('модель не звали, ничего не записано', [$model->calls, $runner->analyzed, $written(EntityCheckTable::class), $written(HistoryTable::class), $report], [0, 0, [], [], []]);

[$runner, $report] = $run([1, 2], $model, $serviceWith([$quota()]), false);
Check::same('ручной прогон по ID: записи, до которых не дошло, — со строкой stop', [array_keys($report), $report[1]['stop'], $report[2]['stop'], $report[1]['error']], [[1, 2], Runner::STOP_QUOTA, Runner::STOP_QUOTA, 'прогон остановлен: Месячная квота исчерпана.']);

Check::group('остановка по лицензии');

$model = $scriptedModel([$low]);
[$runner, $report] = $run([1, 2], $model, $serviceWith([$noLicense()]), false);
Check::same('код license, причина — словами движка, модель не звали', [$runner->stopCode, $runner->stopReason, $model->calls, $report[2]['stop']], [Runner::STOP_LICENSE, 'Срок лицензии закончился.', 0, Runner::STOP_LICENSE]);

$model = $scriptedModel([$low]);
[$runner, $report] = $run([1, 2, 3], $model, $serviceWith([$ok(), $noLicense()]), false);
Check::same('лицензия кончилась посреди прогона: первая запись сделана, остальные — stop license', [$model->calls, $runner->analyzed, $runner->stopCode, isset($report[1]['stop']), $report[2]['stop'] ?? null, $report[3]['stop'] ?? null], [1, 1, Runner::STOP_LICENSE, false, Runner::STOP_LICENSE, Runner::STOP_LICENSE]);

$model = $scriptedModel([$low]);
[$runner] = $run([1, 2, 3], $model, $serviceWith([$ok(), $ok(), $quota()]));
Check::same('квота кончилась посреди прогона: две записи сделаны', [$model->calls, $runner->analyzed, $runner->stopCode], [2, 2, Runner::STOP_QUOTA]);

$empty = LlmResponse::failed(LlmService::ERROR_QUOTA, '');
[$runner] = $run([1], $scriptedModel([$low]), $serviceWith([$empty]));
Check::same('движок отказал без текста — запасная причина', $runner->stopReason, Runner::EXCEEDED_REASON);

Check::group('успех: расход Runner не пишет');

$model = $scriptedModel([$low]);
$service = $serviceWith([$ok()]);
[$runner, $report] = $run([1], $model, $service);
Check::same('вердикт и цена в отчёте', [$report[1]['risk'], $report[1]['skipped'], $report[1]['costMicro'], $runner->analyzed, $runner->stopped], [10, false, 500, 1, false]);
$checks = $written(EntityCheckTable::class);
Check::same('строка проверки записана, ошибки нет', [count($checks), $checks[0]['fields']['RISK'] ?? null, $checks[0]['fields']['SKIPPED'] ?? null, $report[1]['error']], [1, 10, 'N', '']);
$history = $written(HistoryTable::class);
Check::same('строка истории — с ценой ответа', [count($history), $history[0]['fields']['COST_MICRO'] ?? null], [1, 500]);
Check::same('пишет только свои таблицы: проверка и история', array_keys(\Bitrix\Main\ORM\Data\DataManager::$written), [EntityCheckTable::class, HistoryTable::class]);
Check::same('в LlmService Runner ходит только за allow()', array_values(array_unique(array_column($service->calls, 'method'))), ['allow']);

Check::group('ModelException: провайдер лежит (isDown)');

foreach(['provider_timeout', 'provider_unavailable', 'provider_rate_limit', 'no_model', 'quota_exceeded'] as $code)
{
	Check::same('isDown: '.$code, (new ModelException('x', $code))->isDown(), true);
}
Check::same('не isDown: provider_bad_response, пустой код', [(new ModelException('x', 'provider_bad_response'))->isDown(), (new ModelException('x'))->isDown()], [false, false]);

$model = $scriptedModel([new ModelException('Сервер не отвечает', 'provider_timeout', 0, 0)]);
[$runner, $report] = $run([1], $model, $serviceWith([$ok()]));
Check::same('запись не помечается проверенной: ни проверки, ни истории', [$written(EntityCheckTable::class), $written(HistoryTable::class)], [[], []]);
Check::same('в отчёте — ошибка, запрос засчитан', [$report[1]['error'], $report[1]['action'], $runner->analyzed, $runner->stopped], ['Сервер не отвечает', 'ошибка', 1, false]);

$model = $scriptedModel([new ModelException('Сервер не отвечает', 'provider_unavailable')]);
[$runner, $report] = $run([1, 2, 3, 4, 5], $model, $serviceWith([$ok()]), false);
Check::same('три сбоя подряд — остановка с кодом provider, четвёртую не пробуем', [$model->calls, $runner->stopped, $runner->stopCode, $runner->stopReason], [Runner::PROVIDER_ERRORS_MAX, true, Runner::STOP_PROVIDER, 'провайдер не отвечает 3 раза подряд']);
Check::same('ручной прогон: записи 4 и 5 — со stop provider', [$report[4]['stop'], $report[5]['stop'], isset($report[1]['stop'])], [Runner::STOP_PROVIDER, Runner::STOP_PROVIDER, false]);

$model = $scriptedModel([
	new ModelException('x', 'provider_timeout'), new ModelException('x', 'provider_timeout'), $low,
	new ModelException('x', 'provider_timeout'), new ModelException('x', 'provider_timeout'), new ModelException('x', 'provider_timeout'),
]);
[$runner] = $run([1, 2, 3, 4, 5, 6, 7, 8], $model, $serviceWith([$ok()]));
Check::same('успех сбрасывает счётчик подряд: остановка после трёх новых сбоев', [$model->calls, $runner->stopCode], [6, Runner::STOP_PROVIDER]);

Check::group('ModelException: негодный ответ (не isDown)');

$model = $scriptedModel([new ModelException('Ответ без поля risk', 'provider_bad_response', 123, 40)]);
[$runner, $report] = $run([1], $model, $serviceWith([$ok()]));
$checks = $written(EntityCheckTable::class);
Check::same('запись помечается проверенной с ошибкой (иначе падала бы каждый прогон за деньги)', [count($checks), $checks[0]['fields']['SKIPPED'] ?? null, $checks[0]['fields']['ACTION'] ?? null, $checks[0]['fields']['WHY'] ?? null], [1, 'Y', 'ошибка модели', 'Ошибка модели: Ответ без поля risk']);
Check::same('CHECKED_AT — дата', ($checks[0]['fields']['CHECKED_AT'] ?? null) instanceof DateTime, true);
Check::same('цена оплаченного, но негодного ответа — в отчёте', [$report[1]['costMicro'], $report[1]['error'], $runner->analyzed], [123, 'Ответ без поля risk', 1]);
Check::same('счётчик провайдера не растёт: прогон не останавливается', [$runner->stopped], [false]);

$model = $scriptedModel([new ModelException('Ответ без поля risk', 'provider_bad_response', 123)]);
[$runner] = $run([1, 2, 3, 4, 5], $model, $serviceWith([$ok()]));
Check::same('пять негодных ответов подряд — все пять записей отмечены, остановки нет', [$model->calls, count($written(EntityCheckTable::class)), $runner->stopped], [5, 5, false]);

Check::group('Сбой не модели (CRM)');

$model = $scriptedModel([$low]);
Container::override(null, $serviceWith([$ok()]), $model, new class implements FactsSourceInterface {
	public function build(int $dealId): DealFacts { throw new \RuntimeException('Сделка не найдена'); }
});
\Bitrix\Main\ORM\Data\DataManager::$written = [];
$runner = new Runner(EntityType::deal());
$report = $runner->process([9], $profiles, 50, true);
$checks = $written(EntityCheckTable::class);
Check::same('записи нет — отмечается проверенной с ошибкой, модель не звали', [$model->calls, $report[9]['error'], $checks[0]['fields']['WHY'] ?? null, $checks[0]['fields']['SKIPPED'] ?? null], [0, 'Сделка не найдена', 'Ошибка: Сделка не найдена', 'Y']);
Container::override();

Check::finish();
