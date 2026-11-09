<?php declare(strict_types=1);

/**
 * Раздел «Промпты анализа»: шаблон промпта анализа записей по уровням, в таблице
 * shef_toolsaicrm_prompt.
 *
 * Уровни (Analysis\Prompt\PromptLevel): сделки общий, сделки направление N,
 * лиды, смарт-процесс X общий, смарт-процесс X воронка N. Действует ближайший
 * свой шаблон по цепочке воронка -> тип -> стандартный из кода
 * (Analysis\Prompt\PromptResolver). Для уровня видно, свой он, унаследован
 * (от какого уровня) или стандартный. «Удалить свой шаблон» удаляет свой
 * шаблон уровня вместе с его своими полями ответа — дальше действует
 * уровень выше. Предпросмотр на записи показывает, какой уровень сработал.
 *
 * Права проверяет компонент (settings/component.php); изменения — POST с
 * check_bitrix_sessid(); всё, что выводится, — через htmlspecialcharsbx.
 *
 * Права: без «Промпты: править» раздел только для чтения — формы не
 * показываются, а POST (сохранение, удаление, предпросмотр) отклоняется здесь
 * же, на сервере.
 */

use Bitrix\Main\Application;
use Shef\ToolsAiCrm\Analysis\EntityType;
use Shef\ToolsAiCrm\Analysis\EntityTypes;
use Shef\ToolsAiCrm\Analysis\History;
use Shef\ToolsAiCrm\Analysis\Model\HistoryTable;
use Shef\ToolsAiCrm\Analysis\Model\PromptTable;
use Shef\ToolsAiCrm\Analysis\Prompt\PromptLevel;
use Shef\ToolsAiCrm\Analysis\Prompt\PromptResolver;
use Shef\ToolsAiCrm\Analysis\Prompt\PromptStore;
use Shef\ToolsAiCrm\Analysis\Prompt\PromptTemplate;
use Shef\ToolsAiCrm\Container;
use Shef\ToolsAiCrm\Deal\Model\DealProfileTable;
use Shef\ToolsAiCrm\Deal\ProfilePicker;

/** @var \CUser $USER */
global $USER;

$canEdit = (bool)($canEditPrompts ?? false);
if($request->isPost() && !$canEdit)
{
	$alert('Изменения не приняты: раздел «Промпты анализа» открыт вам только для просмотра.', 'warning');
}

$base = $self;

// Обновление без установщика — пишет в базу: только тот, кто может править.
if($canEdit) try
{
	PromptTable::init();
	PromptStore::migrateCommonDynamic(array_keys(EntityTypes::getDynamic()));
}
catch(\Throwable $throwable)
{
}

// Уровни: код => [уровень, название, отступ].
$levels = [];
foreach(array_merge([EntityType::DEAL => EntityType::deal(), EntityType::LEAD => EntityType::lead()], EntityTypes::getDynamic()) as $levelType)
{
	$typeLevel = PromptLevel::of($levelType);
	$levels[$typeLevel->code()] = [$typeLevel, $levelType->title.($levelType->hasCategories ? ': общий' : ''), 0, $levelType];
	if($levelType->hasCategories)
	{
		foreach(EntityTypes::getCategories($levelType) as $categoryId => $name)
		{
			$categoryLevel = PromptLevel::of($levelType, $categoryId);
			$levels[$categoryLevel->code()] = [$categoryLevel, $levelType->title.': '.$name, 1, $levelType];
		}
	}
}
$titleOf = static fn(?PromptLevel $level): string => $level === null ? 'стандартный шаблон модуля' : ($levels[$level->code()][1] ?? $level->code());

$request = Application::getInstance()->getContext()->getRequest();
$code = (string)($request->get('level') ?? 'deal');

// Выбор уровня.
$selector = static function(string $current) use ($h, $levels, $formPath, $hidden): string
{
	try
	{
		$rows = PromptStore::getRows();
	}
	catch(\Throwable)
	{
		$rows = [];
	}
	$html = '<form method="get" action="'.$h($formPath).'">'.$hidden.'<b>Промпт:</b> <select name="level" onchange="this.form.submit()">';
	foreach($levels as $levelCode => [, $title, $indent])
	{
		$html .= '<option value="'.$h($levelCode).'"'.($levelCode === $current ? ' selected' : '').'>'.str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $indent).$h($title).(isset($rows[$levelCode]) ? ' — свой' : '').'</option>';
	}

	return $html.'</select> <noscript><input type="submit" class="ui-btn ui-btn-light-border" value="Открыть"></noscript></form>';
};

if(!isset($levels[$code]))
{
	$code = 'deal';
}
[$level, $levelTitle, , $type] = $levels[$code];
$self = $base.'&level='.rawurlencode($code);
$errors = [];
$preview = null;

if($canEdit && $request->isPost() && check_bitrix_sessid())
{
	if($request->getPost('reset') === 'Y')
	{
		PromptStore::reset($level);
		LocalRedirect($self.'&reset=Y');
	}
	if($request->getPost('save') === 'Y')
	{
		[$extra, $errors] = PromptTemplate::parseExtraFields((string)$request->getPost('EXTRA_FIELDS'));
		if($errors === [])
		{
			PromptStore::save($level, (string)$request->getPost('SYSTEM_TEXT'), (string)$request->getPost('USER_TEXT'), $extra, (int)$USER->GetID());
			LocalRedirect($self.'&saved=Y');
		}
	}
	if($request->getPost('preview') === 'Y' && $USER->IsAdmin())
	{
		// Предпросмотр: собрать промпт на записи типа уровня, модель не звать.
		// Шаблон — по воронке самой записи, как у агента.
		$entityId = (int)$request->getPost('ENTITY_ID');
		if($entityId <= 0)
		{
			$errors[] = 'Не указан ID записи';
		}
		else
		{
			try
			{
				$profiles = DealProfileTable::getProfiles(true, $type->entityTypeId);
				$pick = static fn(\Shef\ToolsAiCrm\Deal\DealFacts $facts): ?\Shef\ToolsAiCrm\Deal\Profile => ProfilePicker::pick($profiles, $facts->categoryId, $facts->clientType);
				$history = History::toPromptText(HistoryTable::getLast($type->entityTypeId, $entityId, History::PROMPT_LIMIT));
				$used = null;
				$preview = Container::getHealthAnalyzer($type)->preview($entityId, $pick, $history, static function(\Shef\ToolsAiCrm\Deal\DealFacts $facts) use ($type, &$used): PromptTemplate
				{
					$used = PromptStore::resolve(PromptLevel::of($type, $facts->categoryId));

					return $used['template'];
				});
				$preview['level'] = PromptLevel::of($type, $preview['facts']->categoryId);
				$preview['source'] = $used['source'] ?? null;
			}
			catch(\Throwable $throwable)
			{
				$errors[] = $throwable->getMessage();
			}
		}
	}
}

try
{
	$rows = PromptStore::getRows();
}
catch(\Throwable $throwable)
{
	$rows = [];
}
$resolved = PromptResolver::resolve($level, $rows);
$state = PromptResolver::state($level, $rows);
$template = $resolved['template'];
$parentResolved = $level->parent() !== null ? PromptResolver::resolve($level->parent(), $rows) : ['template' => PromptTemplate::defaults($level->kind), 'source' => null];


if($errors !== [])
{
	$alert('Ошибка', 'danger', implode('<br>', array_map($h, $errors)));
}
if($request->getQuery('saved') === 'Y')
{
	$alert('Шаблон сохранён', 'success');
}
if($request->getQuery('reset') === 'Y')
{
	$alert('Свой шаблон удалён — действует шаблон уровня выше', 'success');
}
?>
<div class="shef-tai-section">
<p class="shef-tai-page-desc">Тексты-задания для AI: анализ сделок, лидов и смарт-процессов. Выберите уровень в списке; своего шаблона нет — работает стандартный. <?=$help('section.prompts')?></p>

<div class="shef-tai-card">
<?=$selector($code)?>
<p>Шаблон промпта, по которому AI анализирует запись. Свой шаблон можно задать на уровне типа (сделки, лиды, конкретный смарт-процесс) и на уровне воронки. Действует ближайший: воронка → тип → стандартный шаблон модуля. Данные подставляет модуль — переменные ниже. Форма ответа общая (risk, needSenior, why, nextStep): требование JSON модуль добавит сам. Свой промпт в профиле анализа главнее системной части шаблона.</p>
<p style="color:#555">
	<b><?=$h($levelTitle)?></b>:
	<?php if($state['state'] === 'own'): ?>
		свой шаблон, версия <?=(int)$template->version?>, изменён <?=$h($template->changedAt > 0 ? date('d.m.Y H:i', $template->changedAt) : '—')?>, пользователь #<?=(int)$template->changedBy?>.
	<?php elseif($state['state'] === 'inherited'): ?>
		своего шаблона нет — унаследован от уровня «<?=$h($titleOf($state['source']))?>». Сохранение создаст свой шаблон этого уровня.
	<?php else: ?>
		своего шаблона нет — действует стандартный шаблон модуля. Сохранение создаст свой шаблон этого уровня.
	<?php endif; ?>
</p>
<table class="shef-tai-table" style="max-width:900px">
	<?php foreach(PromptTemplate::VARIABLES as $var => $description): ?>
		<tr class="shef-tai-table-row"><td class="shef-tai-table-cell"><code><?=$h($var)?></code></td><td class="shef-tai-table-cell"><?=$h($description)?></td></tr>
	<?php endforeach; ?>
</table>
</div>

<div class="shef-tai-card">
<?php
// Сохранение с ошибкой (свои поля) — в форме остаётся введённое, а не сохранённый шаблон:
// иначе правка пропала бы, а повторное «Сохранить» записало бы прежний текст.
$posted = $canEdit && $request->isPost() && $request->getPost('save') === 'Y' && ($errors !== [] || !check_bitrix_sessid());
$formSystem = $posted ? (string)$request->getPost('SYSTEM_TEXT') : $template->system;
$formUser = $posted ? (string)$request->getPost('USER_TEXT') : $template->user;
$formExtra = $posted ? (string)$request->getPost('EXTRA_FIELDS') : implode(', ', $template->extraFields);
?>
<?php if(!$canEdit): ?>
<p><b>Инструкция модели (системная часть)</b></p><pre style="white-space:pre-wrap;background:#f5f5f5;padding:8px;max-width:900px"><?=$h($formSystem)?></pre>
<p><b>Данные (пользовательская часть)</b></p><pre style="white-space:pre-wrap;background:#f5f5f5;padding:8px;max-width:900px"><?=$h($formUser)?></pre>
<p><b>Свои поля ответа:</b> <?=$h($formExtra !== '' ? $formExtra : 'нет')?></p>
<?php else: ?>
<form method="post" action="<?=$h($self)?>">
	<?=bitrix_sessid_post()?>
	<input type="hidden" name="save" value="Y">
	<p><b>Инструкция модели (системная часть)</b> <?=$help('prompts.system')?><br>
	<textarea name="SYSTEM_TEXT" rows="18" cols="100"><?=$h($formSystem)?></textarea></p>
	<p><b>Данные (пользовательская часть)</b> <?=$help('prompts.user')?><br>
	<textarea name="USER_TEXT" rows="4" cols="100"><?=$h($formUser)?></textarea></p>
	<p><b>Свои поля ответа</b> <?=$help('prompts.extra_fields')?> (через запятую, латиница, до <?=PromptTemplate::EXTRA_MAX?>; сохраняются в истории и показываются, на шкалу не влияют; убрать поле — стереть его здесь и сохранить)<br>
	<input type="text" name="EXTRA_FIELDS" size="60" value="<?=$h($formExtra)?>"></p>
	<input type="submit" class="ui-btn ui-btn-primary" value="Сохранить для уровня «<?=$h($levelTitle)?>»">
</form>
<?php endif; ?>
<?php if($state['state'] === 'own' && $canEdit): ?>
<form method="post" action="<?=$h($self)?>" style="margin-top:8px" data-confirm-title="<?=$h('Удалить свой шаблон уровня «'.$levelTitle.'»?')?>" data-confirm-text="<?=$h('Шаблон удаляется вместе со своими полями ответа, без возврата. Действует: '.$titleOf($parentResolved['source']).'.')?>" data-confirm-ok="Удалить" data-confirm-danger="Y">
	<?=bitrix_sessid_post()?>
	<input type="hidden" name="reset" value="Y">
	<input type="submit" class="ui-btn ui-btn-danger-light" value="Удалить свой шаблон"> <?=$help('prompts.delete')?>
	<span style="color:#777">После удаления действует: <?=$h($titleOf($parentResolved['source']))?>. Это и есть «вернуть стандартный»: отдельной кнопки нет, потому что действие одно и то же.</span>
</form>
<details style="margin-top:8px"><summary>Что будет действовать без своего: <?=$h($titleOf($parentResolved['source']))?></summary><pre style="white-space:pre-wrap"><?=$h($parentResolved['template']->system."\n\n---\n".$parentResolved['template']->user)?></pre></details>
<?php endif; ?>
</div>

<?php if($canEdit && $USER->IsAdmin()): // данные любой записи CRM — мимо её прав: только админ ?>
<div class="shef-tai-card">
<h3>Предпросмотр на записи <?=$help('prompts.preview')?></h3>
<p>Собрать промпт для записи типа «<?=$h($type->title)?>» — без отправки в модель и без расхода. Шаблон берётся по воронке самой записи, как у агента.</p>
<form method="post" action="<?=$h($self)?>">
	<?=bitrix_sessid_post()?>
	<input type="hidden" name="preview" value="Y">
	ID: <input type="text" name="ENTITY_ID" size="8" value="<?=$h($request->getPost('ENTITY_ID') ?? '')?>">
	<input type="submit" class="ui-btn ui-btn-light-border" value="Собрать промпт">
</form>
<?php if($preview !== null): ?>
	<p>Уровень записи: <b><?=$h($titleOf($preview['level']))?></b>; сработал шаблон: <b><?=$h($titleOf($preview['source']))?></b>.</p>
	<p>Профиль: <?=$h($preview['profile']?->title ?? 'не подобрался — агент запись бы не анализировал')?></p>
	<p><b>Системная часть</b></p><pre style="white-space:pre-wrap;background:#f5f5f5;padding:8px"><?=$h($preview['system'])?></pre>
	<p><b>Данные</b></p><pre style="white-space:pre-wrap;background:#f5f5f5;padding:8px"><?=$h($preview['user'])?></pre>
	<p><b>Схема ответа</b></p><pre style="white-space:pre-wrap;background:#f5f5f5;padding:8px"><?=$h(json_encode($preview['schema'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))?></pre>
<?php endif; ?>
</div>
<?php endif; ?>

</div>
