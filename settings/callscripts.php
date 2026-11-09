<?php declare(strict_types=1);

/**
 * Блок «Скрипты оценки звонков» раздела «Авто-анализ CRM» (#408, часть B).
 *
 * Подключается из settings/profiles.php дважды: $callScriptStage = 'post' —
 * до вывода (сохранение, LocalRedirect), 'view' — карточка. Права — компонент
 * (settings/component.php), изменения — POST с check_bitrix_sessid(), вывод —
 * через $h. Устройство — CallScript\Events.
 *
 * @var string $callScriptStage
 * @var string $self
 * @var \Bitrix\Main\HttpRequest $request
 * @var array<string, string> $places ключ «тип:воронка» => название (из profiles.php, только на view)
 */

use Bitrix\Main\Config\Option;
use Shef\ToolsAiCrm\Analysis\EntityType;
use Shef\ToolsAiCrm\Analysis\EntityTypes;
use Shef\ToolsAiCrm\CallScript\Events as CallScriptEvents;
use Shef\ToolsAiCrm\CallScript\Model\CallScriptRuleTable;
use Shef\ToolsAiCrm\CallScript\Rule;
use Shef\ToolsAiCrm\Deal\Profile;
use Shef\ToolsAiCrm\Main\Constants;

if($callScriptStage === 'post')
{
	$callScriptErrors = [];
	if(!$request->isPost() || !check_bitrix_sessid() || $request->getPost('callscript') === null)
	{
		return;
	}
	CallScriptRuleTable::init();
	$action = (string)$request->getPost('callscript');
	if($action === 'settings')
	{
		$mode = $request->getPost('MODE') === CallScriptEvents::MODE_RULES ? CallScriptEvents::MODE_RULES : CallScriptEvents::MODE_CORE;
		$fallback = trim((string)$request->getPost('FALLBACK'));
		if($fallback !== '' && 1 !== preg_match('/^[1-9]\d{0,9}$/', $fallback))
		{
			$callScriptErrors[] = 'Запасной скрипт не разобрался — выберите из списка';
		}
		else
		{
			Option::set(Constants::MODULE_ID, CallScriptEvents::OPTION_MODE, $mode);
			Option::set(Constants::MODULE_ID, CallScriptEvents::OPTION_FALLBACK, $fallback);
			CallScriptEvents::register();
			LocalRedirect($self.'&saved=Y#callscripts');
		}
	}
	elseif($action === 'delete' && (int)$request->getPost('RULE_ID') > 0)
	{
		CallScriptRuleTable::delete((int)$request->getPost('RULE_ID'));
		LocalRedirect($self.'&deleted=Y#callscripts');
	}
	elseif($action === 'save')
	{
		[$fields, $invalid] = Rule::fromInput($request->getPostList()->toArray());
		if($invalid === [])
		{
			$ruleId = (int)$request->getPost('RULE_ID');
			$result = $ruleId > 0 ? CallScriptRuleTable::update($ruleId, $fields) : CallScriptRuleTable::add($fields);
			if($result->isSuccess())
			{
				LocalRedirect($self.'&saved=Y#callscripts');
			}
			$callScriptErrors = $result->getErrorMessages();
		}
		else
		{
			$names = ['PLACE' => 'Где', 'STAGES' => 'Стадии', 'STAGE_SEMANTIC' => 'Итог', 'ASSESSMENT_ID' => 'Скрипт', 'SORT' => 'Сортировка'];
			$callScriptErrors[] = 'Не разобрались поля: '.implode(', ', array_map(static fn(string $f): string => $names[$f] ?? $f, $invalid));
		}
	}

	return;
}

// view
CallScriptRuleTable::init();
try
{
	$scripts = CallScriptEvents::scriptNames();
}
catch(\Throwable)
{
	$scripts = [];
}
// Места: типы с воронками и «любая воронка» типа
$rulePlaces = [];
foreach(array_merge([EntityType::DEAL => EntityType::deal(), EntityType::LEAD => EntityType::lead()], EntityTypes::getDynamic()) as $placeType)
{
	if($placeType->hasCategories)
	{
		$rulePlaces[$placeType->entityTypeId.':*'] = $placeType->title.': любая воронка';
	}
	foreach(EntityTypes::getCategories($placeType) as $categoryId => $name)
	{
		$rulePlaces[EntityType::toKey($placeType->entityTypeId, $categoryId)] = $placeType->hasCategories ? $placeType->title.': '.$name : $placeType->title;
	}
}
$outcomeNames = [Profile::OUTCOME_ANY => 'Итог любой — стадии из списка', Profile::OUTCOME_SUCCESS => 'Успех — запись закрыта успешно', Profile::OUTCOME_FAILURE => 'Провал — запись закрыта неуспешно'];
$ruleEdit = null;
$csEdit = (string)$request->getQuery('csedit');
if($csEdit === 'new')
{
	$ruleEdit = Rule::fromRow(['IS_ENABLED' => 'Y']);
}
elseif(1 === preg_match('/^\d+$/', $csEdit))
{
	$row = CallScriptRuleTable::getById((int)$csEdit)->fetch();
	$ruleEdit = $row ? Rule::fromRow($row) : null;
}
$mode = Option::get(Constants::MODULE_ID, CallScriptEvents::OPTION_MODE, CallScriptEvents::MODE_CORE);
$fallback = CallScriptEvents::getFallback();
?>
<div class="shef-tai-card" id="callscripts">
	<h3 style="margin-top:0">Скрипты оценки звонков <?=$help('section.callscripts')?></h3>
	<p style="color:var(--tai-text-2);max-width:900px">«Штатно» — скрипт к звонку выбирает Битрикс24 по типу клиента и направлению звонка. «По правилам» — модуль закрепляет за звонком скрипт по записи, к которой он привязан: тип записи, воронка, стадия; оценку делает Битрикс24 этим скриптом, тексты и критерии скрипта не меняются. Автопроверку и расписание задаёт сам скрипт: скрипт вне своего расписания (и, при речевой аналитике нового вида, без критериев) не закрепляется — тогда скрипт выбирает Битрикс24. Запись, стадия и расписание скрипта берутся на момент, когда звонок попадает в CRM. Запасной скрипт действует и для звонков без сделки, лида или смарт-процесса. Работает для телефонии Битрикс24; скрипт, выбранный оператором в карточке звонка, остаётся.</p>
	<?php if(!CallScriptEvents::isSupported()): ?><p style="color:var(--tai-danger)">Подбор скриптов недоступен в этой версии Битрикс24.</p><?php elseif($scripts === []): ?><p style="color:var(--tai-danger)">Включённых скриптов оценки звонков нет или в этом Битрикс24 они недоступны — подбирать нечего.</p><?php endif; ?>
	<?php foreach($callScriptErrors ?? [] as $error): $alert((string)$error); endforeach; ?>
	<form method="post" action="<?=$h($self)?>">
		<?=bitrix_sessid_post()?>
		<input type="hidden" name="callscript" value="settings">
		<p>
			<label><input type="radio" name="MODE" value="<?=$h(CallScriptEvents::MODE_CORE)?>"<?=$mode !== CallScriptEvents::MODE_RULES ? ' checked' : ''?>> Штатно</label>
			&nbsp; <label><input type="radio" name="MODE" value="<?=$h(CallScriptEvents::MODE_RULES)?>"<?=$mode === CallScriptEvents::MODE_RULES ? ' checked' : ''?>> По правилам</label>
		</p>
		<p>Если правило не подошло — скрипт:
			<select name="FALLBACK">
				<option value="">штатный выбор Битрикс24</option>
				<?php foreach($scripts as $sid => $title): ?>
					<option value="<?=(int)$sid?>"<?=$sid === $fallback ? ' selected' : ''?>><?=$h($title)?></option>
				<?php endforeach; ?>
			</select>
			<input type="submit" class="ui-btn ui-btn-light-border" value="Сохранить">
		</p>
	</form>

	<?php if($ruleEdit === null): ?>
	<table class="shef-tai-table" style="max-width:900px">
		<thead>
			<tr class="shef-tai-table-header">
				<td class="shef-tai-table-cell">Где</td>
				<td class="shef-tai-table-cell">Стадии</td>
				<td class="shef-tai-table-cell">Скрипт</td>
				<td class="shef-tai-table-cell">Включено</td>
				<td class="shef-tai-table-cell"></td>
			</tr>
		</thead>
		<tbody>
		<?php foreach(CallScriptRuleTable::getRules() as $rule): ?>
			<tr class="shef-tai-table-row">
				<td class="shef-tai-table-cell"><a href="<?=$h($self.'&csedit='.$rule->id.'#callscripts')?>"><?=$h($rulePlaces[$rule->placeKey()] ?? $rule->placeKey())?></a> <span style="color:var(--tai-text-3)">(<?=(int)$rule->sort?>)</span></td>
				<td class="shef-tai-table-cell"><?=$h($rule->stageSemantic !== '' ? $outcomeNames[$rule->stageSemantic] : ($rule->stages === [] ? 'любая' : implode(', ', $rule->stages)))?></td>
				<td class="shef-tai-table-cell"><?=$h($scripts[$rule->assessmentId] ?? ('#'.$rule->assessmentId.' — выключен или удалён, правило пропускается'))?></td>
				<td class="shef-tai-table-cell"><?=$rule->enabled ? 'да' : 'нет'?></td>
				<td class="shef-tai-table-cell">
					<form method="post" action="<?=$h($self)?>" data-confirm-title="Удалить правило?" data-confirm-text="Правило удаляется без возврата." data-confirm-ok="Удалить" data-confirm-danger="Y">
						<?=bitrix_sessid_post()?>
						<input type="hidden" name="callscript" value="delete">
						<input type="hidden" name="RULE_ID" value="<?=(int)$rule->id?>">
						<input type="submit" class="ui-btn ui-btn-light-border" value="Удалить">
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<p style="margin-bottom:0"><a class="ui-btn ui-btn-light-border" href="<?=$h($self.'&csedit=new#callscripts')?>">Добавить правило</a></p>
	<?php else:
		// Стадии мест без финальных — итог задаётся отдельно
		$ruleStages = [];
		foreach($rulePlaces as $key => $unused)
		{
			$parsed = EntityType::parseKey($key);
			$factory = $parsed !== null ? \Bitrix\Crm\Service\Container::getInstance()->getFactory($parsed[0]) : null;
			if($factory === null || !$factory->isStagesEnabled())
			{
				continue;
			}
			foreach($factory->getStages($factory->isCategoriesSupported() ? $parsed[1] : null) as $stage)
			{
				if(Profile::parseOutcome((string)$stage->getSemantics()) === Profile::OUTCOME_ANY)
				{
					$ruleStages[$key][(string)$stage->getStatusId()] = (string)$stage->getName();
				}
			}
		}
	?>
	<form method="post" action="<?=$h($self)?>">
		<?=bitrix_sessid_post()?>
		<input type="hidden" name="callscript" value="save">
		<input type="hidden" name="RULE_ID" value="<?=(int)$ruleEdit->id?>">
		<table class="shef-tai-form">
			<tr><td class="shef-tai-l">Включено:</td><td class="shef-tai-r"><input type="checkbox" name="IS_ENABLED" value="Y"<?=$ruleEdit->enabled ? ' checked' : ''?>></td></tr>
			<tr><td class="shef-tai-l">Сортировка:<br><span style="color:var(--tai-text-3)">Меньше — главнее среди равных по точности</span></td><td class="shef-tai-r"><input type="text" name="SORT" size="6" value="<?=(int)$ruleEdit->sort?>"></td></tr>
			<tr><td class="shef-tai-l">Где:</td><td class="shef-tai-r">
				<select name="PLACE" id="shef-tai-cs-place">
					<?php foreach($rulePlaces as $key => $name): ?>
						<option value="<?=$h($key)?>"<?=$key === $ruleEdit->placeKey() ? ' selected' : ''?>><?=$h($name)?></option>
					<?php endforeach; ?>
				</select>
			</td></tr>
			<tr><td class="shef-tai-l">Стадии:<br><span style="color:var(--tai-text-3)">Ничего не выбрано — любая стадия (несколько — с Ctrl или Cmd). Итог «успех» или «провал» — звонок по закрытой записи.</span></td><td class="shef-tai-r">
				<select name="STAGE_SEMANTIC" id="shef-tai-cs-outcome">
					<?php foreach($outcomeNames as $code => $name): ?>
						<option value="<?=$h($code)?>"<?=$code === $ruleEdit->stageSemantic ? ' selected' : ''?>><?=$h($name)?></option>
					<?php endforeach; ?>
				</select><br>
				<?php foreach($ruleStages as $key => $stages): ?>
					<select name="STAGES[]" multiple size="<?=min(8, max(3, count($stages)))?>" data-shef-cs-stages="<?=$h($key)?>" style="min-width:260px;margin-top:6px">
						<?php foreach($stages as $code => $name): ?>
							<option value="<?=$h($code)?>"<?=in_array($code, $ruleEdit->stages, true) ? ' selected' : ''?>><?=$h($name)?></option>
						<?php endforeach; ?>
					</select>
				<?php endforeach; ?>
			</td></tr>
			<tr><td class="shef-tai-l">Скрипт:</td><td class="shef-tai-r">
				<select name="ASSESSMENT_ID">
					<?php foreach($scripts as $sid => $title): ?>
						<option value="<?=(int)$sid?>"<?=$sid === $ruleEdit->assessmentId ? ' selected' : ''?>><?=$h($title)?></option>
					<?php endforeach; ?>
				</select>
			</td></tr>
		</table>
		<div class="shef-tai-form-buttons">
			<input type="submit" class="ui-btn ui-btn-primary" value="Сохранить правило">
			<a class="ui-btn ui-btn-light-border" href="<?=$h($self.'#callscripts')?>">Отмена</a>
		</div>
		<script>
		(function()
		{
			var place = document.getElementById('shef-tai-cs-place');
			var outcome = document.getElementById('shef-tai-cs-outcome');
			var sync = function()
			{
				document.querySelectorAll('select[data-shef-cs-stages]').forEach(function(list)
				{
					var show = list.getAttribute('data-shef-cs-stages') === place.value && outcome.value === '';
					list.hidden = !show;
					list.disabled = !show;
				});
			};
			place.addEventListener('change', sync);
			outcome.addEventListener('change', sync);
			sync();
		})();
		</script>
		<style>select[data-shef-cs-stages][hidden] { display: none; }</style>
	</form>
	<?php endif; ?>
</div>
