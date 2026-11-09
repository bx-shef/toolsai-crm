<?php declare(strict_types=1);

/**
 * Раздел «Статистика»: что сделал автоанализ CRM за период. Два отчёта, каждый
 * на своей странице меню:
 *   - «Анализ записей CRM» — проверено записей, дела менеджерам и старшим, по
 *     типам, ответственным и профилям;
 *   - «Оценка работы менеджеров» — оценка ответственного из итогов анализа, 0–100.
 *
 * Права проверяет компонент (settings/component.php). Только чтение: здесь нет
 * ни одной записи в базу. Период — штатный фильтр в верхней панели.
 *
 * Чистая логика — в Stats\*: период (строгий разбор GET), выбор владельцев
 * записей, подсчёт оценок менеджеров; там же тесты.
 */

use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UserTable;
use Shef\ToolsAiCrm\Analysis\EntityTypes;
use Shef\ToolsAiCrm\Analysis\Model\EntityCheckTable;
use Shef\ToolsAiCrm\Analysis\Model\HistoryTable;
use Shef\ToolsAiCrm\Deal\Model\DealProfileTable;
use Shef\ToolsAiCrm\Settings\Faq;
use Shef\ToolsAiCrm\Settings\Sections;
use Shef\ToolsAiCrm\Stats\AnalysisOwners;
use Shef\ToolsAiCrm\Stats\ManagerScores;
use Shef\ToolsAiCrm\Stats\Period;
use Shef\ToolsAiCrm\Stats\PeriodFilter;

Loc::loadMessages(__FILE__);

// Один отчёт — одна страница: раздел выбирает блок, запросы другого блока не идут
$block = Sections::statsBlock((string)($section ?? '')) ?? 'checks';
$show = static fn(string ...$blocks): bool => in_array($block, $blocks, true);

$request = Application::getInstance()->getContext()->getRequest();
// Период — штатный main.ui.filter в верхней панели; ?from=&to= в ссылке выставляют фильтр
$filterOptions = new \Bitrix\Main\UI\Filter\Options(PeriodFilter::ID);
$periodQuery = array_intersect_key($request->getQueryList()->toArray(), ['from' => 1, 'to' => 1]);
if(PeriodFilter::hasParams($periodQuery))
{
	$filterOptions->setFilterSettings(\Bitrix\Main\UI\Filter\Options::TMP_FILTER, PeriodFilter::settings($periodQuery, \Bitrix\Main\Type\Date::convertFormatToPhp(FORMAT_DATE)), true, false);
	$filterOptions->save();
	$periodValues = $periodQuery;
}
else
{
	$periodValues = PeriodFilter::values($filterOptions->getFilter(PeriodFilter::fields()));
}
$period = Period::fromRequest($periodValues['from'] ?? null, $periodValues['to'] ?? null, new \DateTimeImmutable());

$connection = Application::getConnection();
$helper = $connection->getSqlHelper();
$toDb = static fn(\DateTimeImmutable $date): string => $helper->convertToDbDateTime(
	new \Bitrix\Main\Type\DateTime($date->format('Y-m-d H:i:s'), 'Y-m-d H:i:s')
);
$from = $toDb($period->from);
$till = $toDb($period->till);
/** Условие «поле в периоде» — даты уже через SqlHelper. */
$in = static fn(string $field): string => '('.$field.' >= '.$from.' AND '.$field.' < '.$till.')';

$h = static fn(mixed $value): string => htmlspecialcharsbx((string)$value);
$msg = static fn(string $code): string => htmlspecialcharsbx((string)Loc::getMessage('SH_TOOLSAICRM_STATS_'.$code));
$fetchAll = static fn(string $sql): array => $connection->query($sql)->fetchAll();

// Проверки всех типов — одна таблица
$checkTable = EntityCheckTable::getTableName();
// Типы анализа: сделки и лиды всегда, смарт-процессы — с профилем или с проверками
// (профиль удалили — прежние проверки и история из итогов не пропадают).
$ownerTables = AnalysisOwners::tables(array_map(
	static fn(array $row): int => (int)$row['ENTITY_TYPE_ID'],
	$fetchAll(AnalysisOwners::typesSql($helper->quote(DealProfileTable::getTableName()), $helper->quote(EntityCheckTable::getTableName())))
));
$dealOnly = AnalysisOwners::scope($ownerTables);
$ownerJoin = static fn(string $alias): string => AnalysisOwners::joins($ownerTables, $alias);
$owner = AnalysisOwners::owner($ownerTables);
$profileTable = DealProfileTable::getTableName();

$deals = $dealTodo = [];
$dealOverdue = null;
$byManager = $byType = $byProfile = $managerScores = [];
// region Анализ записей ////
if($show('checks', 'managers'))
{
	$deals = $connection->query('
		SELECT COUNT(*) AS CNT,
			SUM(CASE WHEN SKIPPED = \'N\' THEN 1 ELSE 0 END) AS ANALYZED,
			SUM(CASE WHEN SKIPPED = \'Y\' THEN 1 ELSE 0 END) AS SKIPPED_CNT
		FROM '.$helper->quote($checkTable).'
		WHERE '.$dealOnly.' AND '.$in('CHECKED_AT')
	)->fetch() ?: [];
	$dealTodo = $connection->query('
		SELECT SUM(CASE WHEN '.$in('MANAGER_TODO_AT').' THEN 1 ELSE 0 END) AS TODO,
			SUM(CASE WHEN '.$in('ESCALATED_AT').' THEN 1 ELSE 0 END) AS ESC
		FROM '.$helper->quote($checkTable).'
		WHERE '.$dealOnly.' AND ('.$in('MANAGER_TODO_AT').' OR '.$in('ESCALATED_AT').')'
	)->fetch() ?: [];

	// Дела старшему о просрочке — столбца нет, пока таблицу не обновили: тогда цифры нет.
	$hasOverdue = array_key_exists('OVERDUE_NOTIFIED_AT', array_change_key_case((array)$connection->getTableFields($checkTable), CASE_UPPER));
	$dealOverdue = $hasOverdue ? ($connection->query('
		SELECT COUNT(*) AS CNT FROM '.$helper->quote($checkTable).' WHERE '.$dealOnly.' AND '.$in('OVERDUE_NOTIFIED_AT')
	)->fetch() ?: []) : null;

	$dealGroup = static fn(string $groupBy, string $join): string => '
		SELECT '.$groupBy.' AS GRP,
			SUM(CASE WHEN '.$in('c.CHECKED_AT').' THEN 1 ELSE 0 END) AS CNT,
			AVG(CASE WHEN '.$in('c.CHECKED_AT').' AND c.SKIPPED = \'N\' THEN c.RISK END) AS AVG_RISK,
			SUM(CASE WHEN '.$in('c.MANAGER_TODO_AT').' THEN 1 ELSE 0 END) AS TODO,
			SUM(CASE WHEN '.$in('c.ESCALATED_AT').' THEN 1 ELSE 0 END) AS ESC
		FROM '.$helper->quote($checkTable).' c
		'.$join.'
		WHERE c.'.$dealOnly.' AND ('.$in('c.CHECKED_AT').' OR '.$in('c.MANAGER_TODO_AT').' OR '.$in('c.ESCALATED_AT').')
		GROUP BY '.$groupBy.'
		ORDER BY CNT DESC';
	$byManager = $fetchAll($dealGroup($owner, $ownerJoin('c')));
	$byType = $fetchAll($dealGroup('c.ENTITY_TYPE_ID', ''));
	$byProfile = $fetchAll($dealGroup('c.PROFILE_ID', ''));

	// Оценка работы менеджеров: managerScore из истории анализа по ответственным.
	// Не больше 20 000 строк за период.
	$managerScores = ManagerScores::aggregate($fetchAll(ManagerScores::historySql(
		$ownerTables, $helper->quote(HistoryTable::getTableName()), $from, $till, 0, 20000
	)));
}
$profileNames = [];
foreach(!$show('checks') ? [] : $fetchAll('SELECT ID, TITLE FROM '.$helper->quote($profileTable)) as $row)
{
	$profileNames[(int)$row['ID']] = (string)$row['TITLE'];
}
// endregion ////

// Имена — одним запросом на всех.
$userIds = array_unique(array_filter(array_merge(
	array_map(static fn(array $row): int => (int)$row['GRP'], $byManager),
	array_column($managerScores, 'user')
)));
$userNames = [];
if($userIds !== [])
{
	foreach(UserTable::getList([
		'select' => ['ID', 'LAST_NAME', 'NAME', 'LOGIN'],
		'filter' => ['@ID' => array_values($userIds)],
	])->fetchAll() as $row)
	{
		$name = trim($row['LAST_NAME'].' '.$row['NAME']);
		$userNames[(int)$row['ID']] = $name !== '' ? $name : (string)$row['LOGIN'];
	}
}
$user = static fn(int $id): string => $userNames[$id] ?? ($id > 0 ? '#'.$id : '—');

$int = static fn(mixed $value): string => (string)(int)$value;
$avg = static fn(mixed $value): string => $value === null ? '—' : (string)(int)round((float)$value);
?>
<div class="shef-tai-section">
<p class="shef-tai-page-desc"><?=$h((string)((Faq::get('section.'.$section) ?? [])['what'] ?? ''))?> <?=$help('section.'.$section)?></p>

<?php
$statsFilter = [
	'FILTER_ID' => PeriodFilter::ID,
	'FILTER' => PeriodFilter::fields(),
	'ENABLE_LIVE_SEARCH' => false,
	'DISABLE_SEARCH' => true,
	'ENABLE_LABEL' => true,
	'RESET_TO_DEFAULT_MODE' => true,
];
if(class_exists(\Bitrix\UI\Toolbar\Facade\Toolbar::class))
{
	\Bitrix\UI\Toolbar\Facade\Toolbar::addFilter($statsFilter);
}
else
{
	?><div class="shef-tai-card"><?php $APPLICATION->IncludeComponent('bitrix:main.ui.filter', '', $statsFilter); ?></div><?php
}
?>
<p class="shef-tai-page-desc" style="margin-top:-12px">Период: <b><?=$h($period->getFromValue())?> — <?=$h($period->getToValue())?></b></p>
<script>
// После «Найти» и «Сбросить» — перечитать; ?from=&to= из адреса убираем
BX.ready(function() {
	BX.addCustomEvent('BX.Main.Filter:apply', function(filterId) {
		if(filterId !== <?=json_encode(PeriodFilter::ID)?>) { return; }
		var url = new URL(window.location.href);
		['from', 'to', 'apply_filter', 'clear_filter'].forEach(function(key) { url.searchParams.delete(key); });
		window.location.href = url.toString();
	});
});
</script>

<?php if($show('checks')): ?>
<div class="shef-tai-card">
<h3><?=$msg('DEALS')?> <?=$help('stats.deals')?></h3>
<ul>
	<li><?=$msg('DEALS_CHECKED')?>: <b><?=$int($deals['CNT'] ?? 0)?></b>,
		<?=$msg('DEALS_ANALYZED')?>: <b><?=$int($deals['ANALYZED'] ?? 0)?></b>,
		<?=$msg('DEALS_SKIPPED')?>: <b><?=$int($deals['SKIPPED_CNT'] ?? 0)?></b></li>
	<li><?=$msg('DEALS_TODO')?>: <b><?=$int($dealTodo['TODO'] ?? 0)?></b>,
		<?=$msg('DEALS_ESC')?>: <b><?=$int($dealTodo['ESC'] ?? 0)?></b><?php if($dealOverdue !== null): ?>,
		<?=$msg('DEALS_OVERDUE')?>: <b><?=$int($dealOverdue['CNT'] ?? 0)?></b><?php endif; ?></li>
</ul>
<p style="color:#777"><?=$msg('DEALS_NOTE')?></p>
<?php foreach(['TYPE' => $byType, 'MANAGER' => $byManager, 'PROFILE' => $byProfile] as $kind => $rows): ?>
	<table class="shef-tai-table" style="margin-bottom:10px">
		<thead>
			<tr class="shef-tai-table-header">
				<td class="shef-tai-table-cell"><?=$msg('COL_'.$kind)?></td>
				<td class="shef-tai-table-cell"><?=$msg('COL_DEALS')?></td>
				<td class="shef-tai-table-cell"><?=$msg('COL_AVG_RISK')?></td>
				<td class="shef-tai-table-cell"><?=$msg('COL_TODO')?></td>
				<td class="shef-tai-table-cell"><?=$msg('COL_ESC')?></td>
			</tr>
		</thead>
		<tbody>
		<?php foreach($rows as $row): ?>
			<tr class="shef-tai-table-row">
				<td class="shef-tai-table-cell"><?=$h(match($kind) {
					'TYPE' => EntityTypes::get((int)$row['GRP'])?->title ?? '#'.(int)$row['GRP'],
					'MANAGER' => $user((int)$row['GRP']),
					default => $profileNames[(int)$row['GRP']] ?? ((int)$row['GRP'] > 0 ? '#'.(int)$row['GRP'] : Loc::getMessage('SH_TOOLSAICRM_STATS_NO_PROFILE')),
				})?></td>
				<td class="shef-tai-table-cell"><?=$int($row['CNT'])?></td>
				<td class="shef-tai-table-cell"><?=$h($avg($row['AVG_RISK']))?></td>
				<td class="shef-tai-table-cell"><?=$int($row['TODO'])?></td>
				<td class="shef-tai-table-cell"><?=$int($row['ESC'])?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endforeach; ?>

</div>

<?php endif; ?>

<?php if($show('managers')): ?>
<div class="shef-tai-card">
<h3><?=$msg('MANAGER_SCORES')?> <?=$help('stats.manager_scores')?></h3>
<?php if($managerScores === []): ?>
	<p style="color:#777"><?=$msg('MANAGER_SCORES_EMPTY')?></p>
<?php else: ?>
	<table class="shef-tai-table">
		<thead>
			<tr class="shef-tai-table-header">
				<td class="shef-tai-table-cell"><?=$msg('COL_MANAGER')?></td>
				<td class="shef-tai-table-cell"><?=$msg('COL_ANALYSES')?></td>
				<td class="shef-tai-table-cell"><?=$msg('COL_AVG_SCORE')?></td>
				<td class="shef-tai-table-cell"><?=$msg('COL_MIN_SCORE')?></td>
			</tr>
		</thead>
		<tbody>
		<?php foreach($managerScores as $row): ?>
			<tr class="shef-tai-table-row">
				<td class="shef-tai-table-cell"><?=$h($user($row['user']))?></td>
				<td class="shef-tai-table-cell"><?=$int($row['count'])?></td>
				<td class="shef-tai-table-cell"><?=$h($avg($row['avg']))?>%</td>
				<td class="shef-tai-table-cell"><?=$int($row['min'])?>%</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
<p style="color:#777"><?=$msg('MANAGER_SCORES_NOTE')?></p>
</div>

<?php endif; ?>

</div>
