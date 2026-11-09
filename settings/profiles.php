<?php declare(strict_types=1);

/**
 * Раздел «Авто-анализ CRM»: профили анализа и скрипты оценки звонков.
 *
 * Профиль — направление сделки, типы клиента, свой промпт и шкала риска
 * (Deal\Profile, Model\DealProfileTable). Образец — скрипты речевой
 * аналитики CRM.
 *
 * Права проверяет компонент (settings/component.php); изменения — POST с
 * check_bitrix_sessid(); всё, что выводится, — через htmlspecialcharsbx.
 */

use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;
use Shef\ToolsAiCrm\Analysis\EntityType;
use Shef\ToolsAiCrm\Analysis\EntityTypes;
use Shef\ToolsAiCrm\Analysis\LastChecks;
use Shef\ToolsAiCrm\Analysis\Model\EntityCheckTable;
use Shef\ToolsAiCrm\Container;
use Shef\ToolsAiCrm\Deal\ClientType;
use Shef\ToolsAiCrm\Deal\LeadClientType;
use Shef\ToolsAiCrm\Deal\Model\DealProfileTable;
use Shef\ToolsAiCrm\Deal\Profile;
use Shef\ToolsAiCrm\Settings\AnalysisAgents;
use Shef\ToolsAiCrm\Main\Constants;

Loc::loadMessages(__FILE__);


// Обновление без установщика: таблицы создаются, если их ещё нет.
try
{
	DealProfileTable::init();
	EntityCheckTable::init();
}
catch(\Throwable)
{
}

$request = Application::getInstance()->getContext()->getRequest();
$errors = [];
$input = null;

// Скрипты оценки звонков (#408): сохранение — до вывода.
$callScriptStage = 'post';
require __DIR__.'/callscripts.php';

if($request->isPost() && check_bitrix_sessid())
{
	$id = (int)$request->getPost('ID');

	if($request->getPost('delete') === 'Y' && $id > 0)
	{
		DealProfileTable::delete($id);
		// Удалён последний включённый профиль типа — агент снимается сразу, как при сохранении.
		AnalysisAgents::ensure(Container::getConfig(), array_keys(\Shef\ToolsAiCrm\Analysis\Candidates::groupByType(DealProfileTable::getProfiles(true))));
		LocalRedirect($self.'&deleted=Y');
	}

	// Агенты анализа по типам (1.9.1): свой интервал и лимит за прогон.
	// Пусто — общие DEAL_interval / DEAL_maxperrun. Сохранили — агенты
	// сразу сверяются (AnalysisAgents::ensure()).
	if($request->getPost('agents') === 'Y')
	{
		foreach((array)$request->getPost('AGENT') as $typeId => $values)
		{
			$typeId = (int)$typeId;
			if($typeId <= 0 || !is_array($values))
			{
				continue;
			}
			$interval = trim((string)($values['interval'] ?? ''));
			$max = trim((string)($values['maxPerRun'] ?? ''));
			if($interval !== '' && \Shef\ToolsAiCrm\Main\OptionParser::int($interval, -1, \Shef\ToolsAiCrm\Config::AGENT_INTERVAL_MIN, \Shef\ToolsAiCrm\Config::AGENT_INTERVAL_MAX) < 0)
			{
				$errors[] = 'Тип '.$typeId.': интервал — минуты от '.\Shef\ToolsAiCrm\Config::AGENT_INTERVAL_MIN.' до '.\Shef\ToolsAiCrm\Config::AGENT_INTERVAL_MAX;
				continue;
			}
			if($max !== '' && \Shef\ToolsAiCrm\Main\OptionParser::int($max, -1, 1, 500) < 0)
			{
				$errors[] = 'Тип '.$typeId.': анализов за прогон — от 1 до 500';
				continue;
			}
			\Bitrix\Main\Config\Option::set(Constants::MODULE_ID, \Shef\ToolsAiCrm\Config::OPTION_ANALYSIS_INTERVAL.$typeId, $interval);
			\Bitrix\Main\Config\Option::set(Constants::MODULE_ID, \Shef\ToolsAiCrm\Config::OPTION_ANALYSIS_MAX_PER_RUN.$typeId, $max);
		}
		if($errors === [])
		{
			AnalysisAgents::ensure(Container::getConfig(), array_keys(\Shef\ToolsAiCrm\Analysis\Candidates::groupByType(DealProfileTable::getProfiles(true))));
			LocalRedirect($self.'&saved=Y');
		}
	}

	if($request->getPost('save') === 'Y')
	{
		$input = $request->getPostList()->toArray();
		[$fields, $invalid] = Profile::fromInput($input);
		$errors = array_map(static fn(string $field): string => (string)Loc::getMessage('SH_TOOLSAICRM_PROFILES_ERROR_'.$field), $invalid);

		if($errors === [])
		{
			$result = $id > 0 ? DealProfileTable::update($id, $fields) : DealProfileTable::add($fields);
			if($result->isSuccess())
			{
				// Первый включённый профиль типа — сразу свой агент; выключен последний —
				// агент снимается. Иначе лиды и новый смарт-процесс ждали бы «Проверить и включить».
				AnalysisAgents::ensure(Container::getConfig(), array_keys(\Shef\ToolsAiCrm\Analysis\Candidates::groupByType(DealProfileTable::getProfiles(true))));
				LocalRedirect($self.'&saved=Y');
			}
			$errors = $result->getErrorMessages();
		}
	}
}

// «Где анализировать» (1.9.0): сделки по направлениям, лиды, смарт-процессы
// по воронкам. Ключ — «тип:воронка» (EntityType::toKey()).
$places = [];
foreach(array_merge([EntityType::DEAL => EntityType::deal(), EntityType::LEAD => EntityType::lead()], EntityTypes::getDynamic()) as $placeType)
{
	foreach(EntityTypes::getCategories($placeType) as $categoryId => $name)
	{
		$places[EntityType::toKey($placeType->entityTypeId, $categoryId)] = $placeType->hasCategories ? $placeType->title.': '.$name : $placeType->title;
	}
}

// Стадии места анализа (#408): ключ «тип:воронка» => код стадии => название.
$stagesByPlace = [];
foreach(array_merge([EntityType::DEAL => EntityType::deal(), EntityType::LEAD => EntityType::lead()], EntityTypes::getDynamic()) as $placeType)
{
	$factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory($placeType->entityTypeId);
	if($factory === null || !$factory->isStagesEnabled())
	{
		continue;
	}
	foreach(EntityTypes::getCategories($placeType) as $categoryId => $name)
	{
		$key = EntityType::toKey($placeType->entityTypeId, $categoryId);
		foreach($factory->getStages($placeType->hasCategories ? (int)$categoryId : null) as $stage)
		{
			// Финальные стадии — через итог «успех»/«провал», в списке их нет
			if(Profile::parseOutcome((string)$stage->getSemantics()) !== Profile::OUTCOME_ANY)
			{
				continue;
			}
			$stagesByPlace[$key][(string)$stage->getStatusId()] = (string)$stage->getName();
		}
	}
}
$outcomeNames = [
	Profile::OUTCOME_ANY => (string)Loc::getMessage('SH_TOOLSAICRM_PROFILES_OUTCOME_ANY'),
	Profile::OUTCOME_SUCCESS => (string)Loc::getMessage('SH_TOOLSAICRM_PROFILES_OUTCOME_S'),
	Profile::OUTCOME_FAILURE => (string)Loc::getMessage('SH_TOOLSAICRM_PROFILES_OUTCOME_F'),
];

$clientTypeNames = [];
foreach(ClientType::getAll() as $type)
{
	$clientTypeNames[$type] = (string)Loc::getMessage('SH_TOOLSAICRM_PROFILES_CLIENT_'.$type);
}

// Тип клиента лида (1.11.0, Deal\LeadClientType): '' — любой.
$leadRepeatNames = [
	LeadClientType::ANY => (string)Loc::getMessage('SH_TOOLSAICRM_PROFILES_CLIENT_ANY'),
	LeadClientType::NEW => (string)Loc::getMessage('SH_TOOLSAICRM_PROFILES_LEAD_NEW'),
	LeadClientType::REPEAT => (string)Loc::getMessage('SH_TOOLSAICRM_PROFILES_LEAD_REPEAT'),
];
$leadLegalNames = [
	LeadClientType::ANY => (string)Loc::getMessage('SH_TOOLSAICRM_PROFILES_CLIENT_ANY'),
	LeadClientType::LEGAL => (string)Loc::getMessage('SH_TOOLSAICRM_PROFILES_LEAD_LEGAL'),
	LeadClientType::PERSON => (string)Loc::getMessage('SH_TOOLSAICRM_PROFILES_LEAD_PERSON'),
];

$edit = (string)$request->getQuery('edit');
$editing = null;
if($input !== null)
{
	$parsedKey = EntityType::parseKey($input['ENTITY_KEY'] ?? null);
	$editing = Profile::fromRow(['ID' => $request->getPost('ID')] + ($parsedKey !== null ? ['ENTITY_TYPE_ID' => $parsedKey[0], 'CATEGORY_ID' => $parsedKey[1]] : []) + $input);
}
elseif($edit === 'new')
{
	$editing = Profile::fromRow(['IS_ENABLED' => 'Y']);
}
elseif((int)$edit > 0)
{
	$row = DealProfileTable::getById((int)$edit)->fetch();
	$editing = is_array($row) ? Profile::fromRow($row) : null;
}


if($errors !== [])
{
	$alert((string)Loc::getMessage('SH_TOOLSAICRM_PROFILES_ERRORS'), 'danger', implode('<br>', array_map($h, $errors)));
}
if($request->getQuery('saved') === 'Y' || $request->getQuery('deleted') === 'Y')
{
	$alert((string)Loc::getMessage($request->getQuery('saved') === 'Y' ? 'SH_TOOLSAICRM_PROFILES_SAVED' : 'SH_TOOLSAICRM_PROFILES_DELETED'), 'success');
}
?>
<div class="shef-tai-section">

<p class="shef-tai-page-desc"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_INTRO'))?> <?=$help('section.profiles')?></p>

<?php if($editing === null): ?>
	<div class="shef-tai-card">
	<table class="shef-tai-table">
		<thead>
			<tr class="shef-tai-table-header">
				<td class="shef-tai-table-cell">ID</td>
				<td class="shef-tai-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_TITLE'))?></td>
				<td class="shef-tai-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_IS_ENABLED'))?></td>
				<td class="shef-tai-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_CATEGORY_ID'))?></td>
				<td class="shef-tai-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_CLIENT_TYPES'))?></td>
				<td class="shef-tai-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_COL_SCALE'))?></td>
				<td class="shef-tai-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_SENIOR_ID'))?></td>
				<td class="shef-tai-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_PROMPT'))?></td>
				<td class="shef-tai-table-cell"></td>
			</tr>
		</thead>
		<tbody>
		<?php foreach(DealProfileTable::getProfiles() as $profile): ?>
			<tr class="shef-tai-table-row">
				<td class="shef-tai-table-cell"><?=(int)$profile->id?></td>
				<td class="shef-tai-table-cell"><a href="<?=$h($self.'&edit='.$profile->id)?>"><?=$h($profile->title)?></a> <span style="color:#777">(<?=(int)$profile->sort?>)</span></td>
				<td class="shef-tai-table-cell"><?=$h(Loc::getMessage($profile->enabled ? 'SH_TOOLSAICRM_PROFILES_YES' : 'SH_TOOLSAICRM_PROFILES_NO'))?></td>
				<td class="shef-tai-table-cell"><?=$h($places[EntityType::toKey($profile->entityTypeId, $profile->categoryId)] ?? '#'.$profile->entityTypeId.':'.$profile->categoryId)?></td>
				<td class="shef-tai-table-cell"><?=$h($profile->isAnyClient()
					? Loc::getMessage('SH_TOOLSAICRM_PROFILES_CLIENT_ANY')
					: ($profile->entityTypeId === EntityType::LEAD
						? ($leadRepeatNames[$profile->leadRepeat] ?? '').', '.($leadLegalNames[$profile->leadLegal] ?? '')
						: implode(', ', array_map(static fn(int $type): string => $clientTypeNames[$type] ?? (string)$type, $profile->clientTypes))))?></td>
				<td class="shef-tai-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_SCALE', [
					'#LOW#' => $profile->lowBorder,
					'#HIGH#' => $profile->highBorder,
					'#IDLE#' => $profile->idleDays,
					'#REANALYZE#' => $profile->reanalyzeDays,
					'#ACTIVE#' => $profile->activeDays > 0 ? $profile->activeDays : '∞',
				]))?></td>
				<td class="shef-tai-table-cell"><?=$profile->seniorId > 0 ? (int)$profile->seniorId : '—'?></td>
				<td class="shef-tai-table-cell"><?=$h(Loc::getMessage($profile->prompt === '' ? 'SH_TOOLSAICRM_PROFILES_PROMPT_COMMON' : 'SH_TOOLSAICRM_PROFILES_PROMPT_OWN'))?></td>
				<td class="shef-tai-table-cell">
					<form method="post" action="<?=$h($self)?>" data-confirm-title="<?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_DELETE_CONFIRM_TITLE'))?>" data-confirm-text="<?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_DELETE_CONFIRM'))?>" data-confirm-ok="<?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_DELETE'))?>" data-confirm-danger="Y">
						<?=bitrix_sessid_post()?>
						<input type="hidden" name="ID" value="<?=(int)$profile->id?>">
						<input type="hidden" name="delete" value="Y">
						<input type="submit" class="ui-btn ui-btn-light-border" value="<?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_DELETE'))?>">
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<p style="margin-bottom:0"><a class="ui-btn ui-btn-primary" href="<?=$h($self.'&edit=new')?>"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_ADD'))?></a></p>
	</div>

	<?php
	// Агенты анализа (1.9.1): у каждого типа с профилем свой агент.
	$config = Container::getConfig();
	$agentTypes = [];
	foreach(DealProfileTable::getProfiles() as $profile)
	{
		$agentTypes[$profile->entityTypeId] ??= EntityTypes::get($profile->entityTypeId)?->title ?? ('тип '.$profile->entityTypeId);
	}
	$registered = AnalysisAgents::registeredTypes();
	?>
	<?php if($agentTypes !== []): ?>
	<div class="shef-tai-card">
	<h3 style="margin-top:0">Агенты анализа <?=$help('profiles.agents')?></h3>
	<p style="color:#555;max-width:900px">У каждого типа — свой агент: свой интервал, свой лимит анализов за прогон, своя блокировка. Пусто — общие настройки модуля («Запускать агент раз в», «Анализов за прогон»). Агент работает, пока у типа есть включённый профиль; после сохранения агенты сверяются сразу.</p>
	<form method="post" action="<?=$h($self)?>">
		<?=bitrix_sessid_post()?>
		<input type="hidden" name="agents" value="Y">
		<table class="shef-tai-table" style="max-width:900px">
			<thead>
				<tr class="shef-tai-table-header">
					<td class="shef-tai-table-cell">Тип</td>
					<td class="shef-tai-table-cell">Раз в, минут</td>
					<td class="shef-tai-table-cell">Анализов за прогон</td>
					<td class="shef-tai-table-cell">Агент</td>
				</tr>
			</thead>
			<tbody>
			<?php foreach($agentTypes as $typeId => $typeTitle): $raw = $config->getAnalysisAgentRaw($typeId); ?>
				<tr class="shef-tai-table-row">
					<td class="shef-tai-table-cell" title="<?=$h('Номер типа в CRM: '.(int)$typeId.' — агент AnalysisAgent::run('.(int)$typeId.')')?>"><?=$h($typeTitle)?></td>
					<td class="shef-tai-table-cell"><input type="text" size="6" name="AGENT[<?=(int)$typeId?>][interval]" value="<?=$h($raw['interval'])?>" placeholder="<?=(int)($config->getAnalysisInterval($typeId) / 60)?>"></td>
					<td class="shef-tai-table-cell"><input type="text" size="6" name="AGENT[<?=(int)$typeId?>][maxPerRun]" value="<?=$h($raw['maxPerRun'])?>" placeholder="<?=(int)$config->getAnalysisMaxPerRun($typeId)?>"></td>
					<td class="shef-tai-table-cell"><?=in_array($typeId, $registered, true) ? 'зарегистрирован' : 'нет — нужен включённый профиль'?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<input type="submit" class="ui-btn ui-btn-light-border" value="Сохранить агентов">
	</form>
	</div>
	<?php
	// Последние проверки агента (1.24.7; до него — «Анализ сделок: последние
	// проверки» в «Расходе и включении», только сделки). Проверки всех типов —
	// одна таблица с ENTITY_TYPE_ID (1.9.1): вкладка на каждый тип с профилем.
	$checkTypes = LastChecks::order(array_keys($agentTypes));
	$checkType = LastChecks::active($request->getQuery(LastChecks::PARAM), $checkTypes);
	$lastChecks = [];
	// По умолчанию — только проверки моделью (риск, дела); пропуски без модели — по ссылке
	$checksAll = $request->getQuery(LastChecks::ALL_PARAM) === 'Y';
	try
	{
		$lastChecks = $checkType === null ? [] : EntityCheckTable::getList([
			'filter' => ['=ENTITY_TYPE_ID' => $checkType] + ($checksAll ? [] : ['=SKIPPED' => 'N']),
			'order' => ['CHECKED_AT' => 'DESC'],
			'limit' => LastChecks::LIMIT,
		])->fetchAll();
	}
	catch(\Throwable)
	{
	}
	$crmRouter = null;
	try
	{
		$crmRouter = \Bitrix\Crm\Service\Container::getInstance()->getRouter();
	}
	catch(\Throwable)
	{
	}
	$detailUrl = $crmRouter === null ? null : static fn(int $typeId, int $id): mixed => $crmRouter->getItemDetailUrl($typeId, $id);
	?>
	<div class="shef-tai-card">
	<h3 style="margin-top:0"><?=$h(Loc::getMessage($checksAll ? 'SH_TOOLSAICRM_PROFILES_CHECKS_ALL' : 'SH_TOOLSAICRM_PROFILES_CHECKS', ['#N#' => LastChecks::LIMIT]))?> <?=$help('profiles.last_checks')?></h3>
	<p style="margin-top:0"><a href="<?=$h($self.($checkType !== null ? '&'.LastChecks::PARAM.'='.$checkType : '').($checksAll ? '' : '&'.LastChecks::ALL_PARAM.'=Y'))?>"><?=$h(Loc::getMessage($checksAll ? 'SH_TOOLSAICRM_PROFILES_CHECKS_SHOW_MODEL' : 'SH_TOOLSAICRM_PROFILES_CHECKS_SHOW_ALL'))?></a></p>
	<?php if(count($checkTypes) > 1): ?>
		<div style="margin:0 0 10px;display:flex;flex-wrap:wrap;gap:6px">
		<?php foreach($checkTypes as $typeId): ?>
			<a class="ui-btn ui-btn-sm <?=$typeId === $checkType ? 'ui-btn-primary' : 'ui-btn-light-border'?>" href="<?=$h($self.'&'.LastChecks::PARAM.'='.$typeId.($checksAll ? '&'.LastChecks::ALL_PARAM.'=Y' : ''))?>"><?=$h($agentTypes[$typeId])?></a>
		<?php endforeach; ?>
		</div>
	<?php elseif($checkType !== null): ?>
		<p style="margin-top:0"><b><?=$h($agentTypes[$checkType])?></b></p>
	<?php endif; ?>
	<table class="shef-tai-table" style="margin-bottom:0">
		<thead>
			<tr class="shef-tai-table-header">
				<td class="shef-tai-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_CHECKS_COL_ITEM'))?></td>
				<td class="shef-tai-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_CHECKS_COL_TIME'))?></td>
				<td class="shef-tai-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_CHECKS_COL_RISK'))?></td>
				<td class="shef-tai-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_CHECKS_COL_WHY'))?></td>
				<td class="shef-tai-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_CHECKS_COL_ACTION'))?></td>
			</tr>
		</thead>
		<tbody>
		<?php foreach($lastChecks as $row): ?>
			<tr class="shef-tai-table-row">
				<td class="shef-tai-table-cell"><a class="shef-tai-crm-link" href="<?=$h(LastChecks::url((int)$row['ENTITY_TYPE_ID'], (int)$row['ENTITY_ID'], $detailUrl))?>">#<?=(int)$row['ENTITY_ID']?></a></td>
				<td class="shef-tai-table-cell"><?=$h($row['CHECKED_AT'])?></td>
				<td class="shef-tai-table-cell"><?=$row['SKIPPED'] === 'Y' ? ((int)$row['RISK'] === \Shef\ToolsAiCrm\Deal\Verdict::NOTHING_RISK ? $h(\Shef\ToolsAiCrm\Deal\Verdict::riskText((int)$row['RISK'])) : '—') : (int)$row['RISK'].($row['NEED_SENIOR'] === 'Y' ? ' <span class="ui-icon-set --o-alert shef-tai-ic --warn" title="нужен старший" aria-label="нужен старший"></span>' : '')?></td>
				<td class="shef-tai-table-cell"><?=$h($row['WHY'])?></td>
				<?php // Что сделано, а если ничего — почему (1.8.0); до 1.8.0 — дата эскалации. ?>
				<td class="shef-tai-table-cell"><?=$h((string)($row['ACTION'] ?? '') !== '' ? LastChecks::action((string)$row['ACTION'], (string)$row['WHY']) : ($row['ESCALATED_AT'] ? Loc::getMessage('SH_TOOLSAICRM_PROFILES_CHECKS_ESCALATED_OLD', ['#AT#' => $row['ESCALATED_AT']]) : ''))?></td>
			</tr>
		<?php endforeach; ?>
		<?php if($lastChecks === []): ?>
			<tr><td class="shef-tai-table-cell" colspan="5"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_CHECKS_EMPTY'))?></td></tr>
		<?php endif; ?>
		</tbody>
	</table>
	<?php // Ссылки a.shef-tai-crm-link открывает слайдером общий скрипт component.php ?>
	</div>
	<?php endif; ?>
	<?php $callScriptStage = 'view'; require __DIR__.'/callscripts.php'; ?>
<?php else: ?>
	<div class="shef-tai-card">
	<form method="post" action="<?=$h($self)?>">
		<?=bitrix_sessid_post()?>
		<input type="hidden" name="save" value="Y">
		<input type="hidden" name="ID" value="<?=(int)$editing->id?>">
		<table class="shef-tai-form">
			<tr>
				<td class="shef-tai-l" width="40%"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_TITLE'))?>: <?=$help('profile.title')?></td>
				<td class="shef-tai-r"><input type="text" name="TITLE" size="50" maxlength="255" value="<?=$h($editing->title)?>"></td>
			</tr>
			<tr>
				<td class="shef-tai-l"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_IS_ENABLED'))?>: <?=$help('profile.is_enabled')?></td>
				<td class="shef-tai-r"><input type="checkbox" name="IS_ENABLED" value="Y"<?=$editing->enabled ? ' checked' : ''?>></td>
			</tr>
			<tr>
				<td class="shef-tai-l"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_SORT'))?>: <?=$help('profile.sort')?></td>
				<td class="shef-tai-r"><input type="text" name="SORT" size="6" value="<?=(int)$editing->sort?>"></td>
			</tr>
			<tr>
				<td class="shef-tai-l"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_CATEGORY_ID'))?>: <?=$help('profile.category_id')?></td>
				<td class="shef-tai-r">
					<select name="ENTITY_KEY" id="shef-tai-profile-place">
						<?php foreach($places as $key => $name): ?>
							<option value="<?=$h($key)?>" data-lead="<?=str_starts_with($key, EntityType::LEAD.':') ? 'Y' : 'N'?>"<?=$key === EntityType::toKey($editing->entityTypeId, $editing->categoryId) ? ' selected' : ''?>><?=$h($name)?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<?php // Стадии и итог (#408): список стадий — той воронки, что выбрана в «Где анализировать». ?>
			<tr>
				<td class="shef-tai-l"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_STAGES'))?>: <?=$help('profile.stages')?><br><span style="color:#777"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_STAGES_DESCR'))?></span></td>
				<td class="shef-tai-r">
					<select name="STAGE_SEMANTIC" id="shef-tai-profile-outcome">
						<?php foreach($outcomeNames as $code => $name): ?>
							<option value="<?=$h($code)?>"<?=$code === $editing->stageSemantic ? ' selected' : ''?>><?=$h($name)?></option>
						<?php endforeach; ?>
					</select><br>
					<?php foreach($stagesByPlace as $key => $stages): ?>
						<select name="STAGES[]" multiple size="<?=min(8, max(3, count($stages)))?>" data-shef-stages="<?=$h($key)?>" style="min-width:260px;margin-top:6px">
							<?php foreach($stages as $code => $name): ?>
								<option value="<?=$h($code)?>"<?=in_array($code, $editing->stages, true) ? ' selected' : ''?>><?=$h($name)?></option>
							<?php endforeach; ?>
						</select>
					<?php endforeach; ?>
				</td>
			</tr>
			<?php // Поля типа клиента — по месту анализа (1.67.0): у лида свои признаки, у сделки и смарт-процесса — тип клиента ядра. Сервер лишнее и так не сохраняет (Profile). ?>
			<tr data-shef-for="client"<?=$editing->entityTypeId === EntityType::LEAD ? ' hidden' : ''?>>
				<td class="shef-tai-l"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_CLIENT_TYPES'))?>: <?=$help('profile.client_types')?><br><span style="color:#777"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_CLIENT_TYPES_DESCR'))?></span></td>
				<td class="shef-tai-r">
					<?php foreach($clientTypeNames as $type => $name): ?>
						<label><input type="checkbox" name="CLIENT_TYPES[]" value="<?=(int)$type?>"<?=in_array($type, $editing->clientTypes, true) ? ' checked' : ''?>> <?=$h($name)?></label><br>
					<?php endforeach; ?>
					<p style="color:#777;max-width:600px"><?=nl2br($h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_CLIENT_HELP')))?></p>
				</td>
			</tr>
			<tr data-shef-for="lead"<?=$editing->entityTypeId === EntityType::LEAD ? '' : ' hidden'?>>
				<td class="shef-tai-l"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_LEAD_CLIENT'))?>: <?=$help('profile.lead_client')?><br><span style="color:#777"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_LEAD_CLIENT_DESCR'))?></span></td>
				<td class="shef-tai-r">
					<select name="LEAD_REPEAT">
						<?php foreach($leadRepeatNames as $code => $name): ?>
							<option value="<?=$h($code)?>"<?=$code === $editing->leadRepeat ? ' selected' : ''?>><?=$h($name)?></option>
						<?php endforeach; ?>
					</select>
					<select name="LEAD_LEGAL">
						<?php foreach($leadLegalNames as $code => $name): ?>
							<option value="<?=$h($code)?>"<?=$code === $editing->leadLegal ? ' selected' : ''?>><?=$h($name)?></option>
						<?php endforeach; ?>
					</select>
					<p style="color:#777;max-width:600px"><?=nl2br($h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_LEAD_CLIENT_HELP')))?></p>
				</td>
			</tr>
			<tr>
				<td class="shef-tai-l"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_IDLE_DAYS'))?>: <?=$help('profile.idle_days')?><br><span style="color:#777"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_IDLE_DAYS_DESCR'))?></span></td>
				<td class="shef-tai-r"><input type="text" name="IDLE_DAYS" size="6" value="<?=(int)$editing->idleDays?>"></td>
			</tr>
			<tr>
				<td class="shef-tai-l"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_ACTIVE_DAYS'))?>: <?=$help('profile.active_days')?><br><span style="color:#777"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_ACTIVE_DAYS_DESCR'))?></span></td>
				<td class="shef-tai-r"><input type="text" name="ACTIVE_DAYS" size="6" value="<?=(int)$editing->activeDays?>"></td>
			</tr>
			<tr>
				<td class="shef-tai-l"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_REANALYZE_DAYS'))?>: <?=$help('profile.reanalyze_days')?></td>
				<td class="shef-tai-r"><input type="text" name="REANALYZE_DAYS" size="6" value="<?=(int)$editing->reanalyzeDays?>"></td>
			</tr>
			<tr>
				<td class="shef-tai-l"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_LOW_BORDER'))?>: <?=$help('profile.low_border')?><br><span style="color:#777"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_LOW_BORDER_DESCR'))?></span></td>
				<td class="shef-tai-r"><input type="text" name="LOW_BORDER" size="6" value="<?=(int)$editing->lowBorder?>"></td>
			</tr>
			<tr>
				<td class="shef-tai-l"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_HIGH_BORDER'))?>: <?=$help('profile.high_border')?><br><span style="color:#777"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_HIGH_BORDER_DESCR'))?></span></td>
				<td class="shef-tai-r"><input type="text" name="HIGH_BORDER" size="6" value="<?=(int)$editing->highBorder?>"></td>
			</tr>
			<tr>
				<td class="shef-tai-l"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_SENIOR_ID'))?>: <?=$help('profile.senior_id')?><br><span style="color:#777"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_SENIOR_ID_DESCR'))?></span></td>
				<td class="shef-tai-r"><input type="text" name="SENIOR_ID" size="6" value="<?=$editing->seniorId > 0 ? (int)$editing->seniorId : ''?>"></td>
			</tr>
			<tr>
				<td class="shef-tai-l"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_TOTAL_CONTROL'))?>: <?=$help('profile.total_control')?><br><span style="color:#777"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_TOTAL_CONTROL_DESCR'))?></span></td>
				<td class="shef-tai-r"><input type="checkbox" name="TOTAL_CONTROL" value="Y"<?=$editing->totalControl ? ' checked' : ''?>></td>
			</tr>
			<tr>
				<td class="shef-tai-l"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_NO_CONVERSATION'))?>: <?=$help('profile.no_conversation')?><br><span style="color:#777"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_NO_CONVERSATION_DESCR'))?></span></td>
				<td class="shef-tai-r"><input type="checkbox" name="NO_CONVERSATION" value="Y"<?=$editing->noConversation ? ' checked' : ''?>></td>
			</tr>
			<tr>
				<td class="shef-tai-l"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_PROMPT'))?>: <?=$help('profile.prompt')?><br><span style="color:#777"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_F_PROMPT_DESCR'))?></span></td>
				<td class="shef-tai-r">
					<?php
					$ownPrompt = trim($editing->prompt) !== '';
					// Общий — стандартный промпт вида записи (сделка, лид, смарт-процесс), не всегда сделки
					$commonPrompt = \Shef\ToolsAiCrm\Analysis\Prompt\PromptTemplate::defaults(\Shef\ToolsAiCrm\Analysis\EntityTypes::get($editing->entityTypeId)?->promptKind() ?? EntityType::KEY_DEAL)->system;
					?>
					<?php if(!$ownPrompt): ?>
						<?php // Свой промпт пуст — общий текстом, правка — по «Изменить» (1.67.0) ?>
						<div class="shef-tai-prompt-view" id="shef-tai-profile-prompt-view"><?=$h($commonPrompt)?></div>
						<div style="margin-top:8px"><button type="button" class="ui-btn ui-btn-xs ui-btn-light-border" id="shef-tai-profile-prompt-edit"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_PROMPT_EDIT'))?></button></div>
					<?php endif; ?>
					<textarea name="PROMPT" id="shef-tai-profile-prompt" class="shef-tai-prompt-area"<?=$ownPrompt ? '' : ' hidden'?>><?=$h($editing->prompt)?></textarea>
					<script>
					(function()
					{
						var area = document.getElementById('shef-tai-profile-prompt');
						// Поле растёт по тексту
						var grow = function() { area.style.height = 'auto'; area.style.height = (area.scrollHeight + 2) + 'px'; };
						area.addEventListener('input', grow);
						var edit = document.getElementById('shef-tai-profile-prompt-edit');
						if(edit)
						{
							edit.addEventListener('click', function()
							{
								var view = document.getElementById('shef-tai-profile-prompt-view');
								area.value = <?=json_encode($commonPrompt, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)?>;
								view.hidden = true;
								edit.parentNode.hidden = true;
								area.hidden = false;
								grow();
								area.focus();
							});
						}
						else
						{
							grow();
						}
						// Поля типа клиента — по месту анализа
						var place = document.getElementById('shef-tai-profile-place');
						var sync = function()
						{
							var lead = place.options[place.selectedIndex] && place.options[place.selectedIndex].getAttribute('data-lead') === 'Y';
							document.querySelectorAll('tr[data-shef-for]').forEach(function(row)
							{
								row.hidden = (row.getAttribute('data-shef-for') === 'lead') !== lead;
							});
						};
						// Стадии — только выбранной воронки; итог задан — список не нужен
						var outcome = document.getElementById('shef-tai-profile-outcome');
						var syncStages = function()
						{
							document.querySelectorAll('select[data-shef-stages]').forEach(function(list)
							{
								var show = list.getAttribute('data-shef-stages') === place.value && outcome.value === '';
								list.hidden = !show;
								list.disabled = !show;
							});
						};
						place.addEventListener('change', syncStages);
						outcome.addEventListener('change', syncStages);
						syncStages();
						place.addEventListener('change', sync);
						sync();
					})();
					</script></td>
			</tr>
		</table>
		<?php // Кнопки не липнут к краю карточки (1.67.0) ?>
		<div class="shef-tai-form-buttons">
			<input type="submit" class="ui-btn ui-btn-primary" value="<?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_SAVE'))?>">
			<a class="ui-btn ui-btn-light-border" href="<?=$h($self)?>"><?=$h(Loc::getMessage('SH_TOOLSAICRM_PROFILES_CANCEL'))?></a>
		</div>
		<style>
		.shef-tai-form-buttons { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 16px; padding-bottom: 6px; }
		.shef-tai-prompt-view { white-space: pre-wrap; max-width: 900px; padding: 12px 14px; background: #f5f7f8; border: 1px solid #e0e5e8; border-radius: 6px; color: #333; line-height: 1.45; font: inherit; }
		.shef-tai-prompt-area { display: block; width: 100%; max-width: 900px; min-height: 320px; box-sizing: border-box; padding: 10px 12px; border: 1px solid #c6cdd3; border-radius: 6px; font: inherit; line-height: 1.45; resize: vertical; overflow: hidden; }
		.shef-tai-prompt-area[hidden], tr[data-shef-for][hidden], select[data-shef-stages][hidden] { display: none; }
		</style>
	</form>
	</div>
<?php endif; ?>

</div>

