<?php declare(strict_types=1);

/**
 * Фильтр периода отчётов статистики: Stats\PeriodFilter и Stats\Period.
 *
 * Что держит:
 *
 * * значения фильтра -> from/to (Y-m-d), даты в форматах портала, мусор — пусто;
 * * ссылка ?from=&to= -> состояние фильтра в формате даты сайта;
 * * период: «по» включительно, «с» позже «по» меняются местами, мусор — с начала месяца.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiCrm\Stats\Period;
use Shef\ToolsAiCrm\Stats\PeriodFilter as F;

Check::boot();

Check::group('значения фильтра -> параметры отчёта');

Check::same('русский формат', F::values(['PERIOD_from' => '01.10.2026', 'PERIOD_to' => '15.10.2026']), ['from' => '2026-10-01', 'to' => '2026-10-15']);
Check::same('ISO-формат', F::values(['PERIOD_from' => '2026-10-01', 'PERIOD_to' => '2026-10-15 23:59:59']), ['from' => '2026-10-01', 'to' => '2026-10-15']);
Check::same('одна граница', F::values(['PERIOD_from' => '01.10.2026']), ['from' => '2026-10-01']);
Check::same('мусор и пусто — ничего', [F::values(['PERIOD_from' => 'вчера', 'PERIOD_to' => '']), F::values([]), F::values(['PERIOD_from' => ['x']])], [[], [], []]);
Check::same('чужие поля фильтра не проходят', F::values(['USER' => '5', 'PERIOD_from' => '01.10.2026']), ['from' => '2026-10-01']);

Check::group('ссылка -> состояние фильтра');

Check::same('есть параметры периода', [F::hasParams(['from' => '2026-10-01']), F::hasParams(['to' => '2026-10-02']), F::hasParams(['from' => '']), F::hasParams(['user' => '5'])], [true, true, false, false]);
Check::same('диапазон в формате сайта', F::settings(['from' => '2026-10-01', 'to' => '2026-10-15'], 'd.m.Y'), ['fields' => ['PERIOD_datesel' => 'RANGE', 'PERIOD_from' => '01.10.2026', 'PERIOD_to' => '15.10.2026'], 'rows' => ['PERIOD']]);
Check::same('только «с»', F::settings(['from' => '2026-10-01'], 'd.m.Y')['fields']['PERIOD_to'], '');
Check::same('мусор — пустое состояние', F::settings(['from' => '2026-02-30', 'to' => 'x']), ['fields' => [], 'rows' => []]);
Check::same('поля фильтра — только период', array_column(F::fields(), 'id'), ['PERIOD']);

Check::group('период');

$now = new DateTimeImmutable('2026-10-03 15:20:00');
$p = Period::fromRequest('2026-10-01', '2026-10-15', $now);
Check::same('«по» включительно: до начала следующего дня', [$p->getFromValue(), $p->getToValue(), $p->till->format('Y-m-d')], ['2026-10-01', '2026-10-15', '2026-10-16']);
$swap = Period::fromRequest('2026-10-15', '2026-10-01', $now);
Check::same('«с» позже «по» — меняются местами', [$swap->getFromValue(), $swap->getToValue()], ['2026-10-01', '2026-10-15']);
$def = Period::fromRequest(null, 'мусор', $now);
Check::same('по умолчанию — с начала месяца по сегодня', [$def->getFromValue(), $def->getToValue()], ['2026-10-01', '2026-10-03']);
Check::same('несуществующий день — мусор', Period::parseDate('2026-02-30'), null);

Check::finish();
