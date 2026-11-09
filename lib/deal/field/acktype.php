<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Deal\Field;

use Bitrix\Main\UserField\Types\StringType;
use Shef\ToolsAiCrm\Analysis\CheckStore;
use Shef\ToolsAiCrm\Analysis\EntityType;
use Shef\ToolsAiCrm\Analysis\EntityTypes;
use Shef\ToolsAiCrm\Deal\Model\DealProfileTable;

/**
 * Свой тип пользовательского поля: отметка с кнопкой («Риск под контролем»
 * для старшего, «Отработано» для менеджера). С версии 1.8.0.
 *
 * Канон ядра — main/lib/UserField/Types (наследуемся от StringType:
 * хранение — текст: пусто, «pending|<ID>» или «<ID>|<время>», AckValue). Тип отдаётся событием
 * main:OnUserTypeBuildList (регистрирует установщик и Setup).
 *
 * Как карточка CRM выводит поле. Новая карточка (BX.UI.EntityEditorUserField,
 * ui/install/js/ui/entity-editor/js/user-field.js) берёт HTML поля через
 * BX.Main.UF.Manager.getView()/getEdit() -> /bitrix/tools/uf.php ->
 * UserField\Display -> CUserTypeManager::GetPublicView()/GetPublicEdit(),
 * а те зовут VIEW_CALLBACK/EDIT_CALLBACK типа, то есть renderView() и
 * renderEdit() ниже. Компонент main.field.* не нужен: HTML отдаём сами,
 * как это делает и сам BaseType через getHtml(). Редактора у типа нет —
 * renderEdit() показывает то же, что renderView(): поле не правится ни в
 * карточке, ни в списке.
 *
 * Пустое значение карточка рисует сама («не заполнено»), тип не зовёт —
 * поэтому анализ пишет «pending|<адресат>» (AckValue::next()), и кнопка
 * появляется. Остальным, включая администратора, — текст
 * (AckValue::decide()/text()).
 *
 * Кнопку видит адресат (AckValue::canMark) и, с 1.8.2, администратор портала: старший профиля сделки
 * (EntityCheckTable.PROFILE_ID -> SENIOR_ID) или ответственный. Нажатие —
 * BX.ajax.runAction('shef:toolsaicrm.dealflag.mark') (Controller\DealFlag):
 * sessid и CSRF проверяет ядро, права — контроллер заново.
 *
 * С 1.9.0 тип один на все сущности блока анализа: сделку, лид, элемент
 * смарт-процесса. Чью запись рисуем — по ENTITY_ID поля (EntityType::parseUfEntity()),
 * в кнопку уходит entityTypeId.
 */
class AckType extends StringType
{
	public const
		USER_TYPE_ID = 'shef_toolsai_ack',
		RENDER_COMPONENT = 'bitrix:main.field.string';

	public static function getDescription(): array
	{
		return [
			'DESCRIPTION' => 'AI: отметка (shef.toolsaicrm)',
			'BASE_TYPE' => \CUserTypeManager::BASE_TYPE_STRING,
		];
	}

	public static function prepareSettings(array $userField): array
	{
		return ['ROLE' => AckValue::role($userField['SETTINGS']['ROLE'] ?? null) ?? AckValue::ROLE_MANAGER];
	}

	public static function isMultiplicitySupported(): bool
	{
		return false;
	}

	public static function isMandatorySupported(): bool
	{
		return false;
	}

	public static function renderView(array $userField, ?array $additionalParameters = []): string
	{
		return static::render($userField);
	}

	public static function renderEdit(array $userField, ?array $additionalParameters = []): string
	{
		return static::render($userField);
	}

	public static function renderEditForm(array $userField, ?array $additionalParameters): string
	{
		return static::render($userField);
	}

	public static function renderAdminListView(array $userField, ?array $additionalParameters): string
	{
		return static::render($userField, false);
	}

	public static function renderAdminListEdit(array $userField, ?array $additionalParameters)
	{
		return static::render($userField, false);
	}

	public static function renderFilter(array $userField, ?array $additionalParameters): string
	{
		return '';
	}

	public static function renderText(array $userField): string
	{
		$role = AckValue::role($userField['SETTINGS']['ROLE'] ?? null) ?? AckValue::ROLE_MANAGER;
		$state = AckValue::state($userField['VALUE'] ?? null);
		if($state === null)
		{
			return '';
		}
		$view = $state['state'] === AckValue::STATE_DONE ? AckValue::VIEW_DONE : AckValue::VIEW_PENDING;

		return AckValue::text($role, $view, static::nameOf($state['userId']), $state['at'], $state['byAdmin']);
	}

	/** HTML поля из $userField (ENTITY_ID, ENTITY_VALUE_ID, VALUE, SETTINGS). */
	public static function render(array $userField, bool $withButton = true): string
	{
		global $USER;

		$role = AckValue::role($userField['SETTINGS']['ROLE'] ?? null) ?? AckValue::ROLE_MANAGER;
		$dealId = (int)($userField['ENTITY_VALUE_ID'] ?? 0);
		$entityTypeId = EntityTypes::fromUfEntityId($userField['ENTITY_ID'] ?? 'CRM_DEAL') ?: EntityType::DEAL;
		$state = AckValue::state($userField['VALUE'] ?? null);

		[$assigned, $senior] = [0, 0];
		$userId = is_object($USER) ? (int)$USER->GetID() : 0;
		$isAdmin = is_object($USER) && $USER->IsAdmin();
		if($withButton && $state !== null && $state['state'] === AckValue::STATE_PENDING && $dealId > 0 && $userId > 0)
		{
			[$assigned, $senior] = static::getAddressees($dealId, $entityTypeId);
		}
		$view = AckValue::decide($role, $state, $userId, $assigned, $senior, $withButton && $dealId > 0, $isAdmin);
		if($view === AckValue::VIEW_BUTTON)
		{
			static::loadButtons();
		}

		$byAdmin = $view === AckValue::VIEW_BUTTON
			? !AckValue::canMark($role, $userId, $assigned, $senior)
			: (bool)($state['byAdmin'] ?? false);

		return static::buildHtml($role, $dealId, $view, $state !== null ? static::nameOf($state['userId']) : '', $state['at'] ?? 0, $byAdmin, $entityTypeId);
	}

	/**
	 * Чистая часть рендера — для теста. $view — AckValue::VIEW_*.
	 * $byAdmin: у кнопки — нажимает администратор не за себя (своё
	 * пояснение); у отметки — отметил администратор («(за менеджера)»).
	 */
	public static function buildHtml(string $role, int $dealId, string $view, string $userName, int $at, bool $byAdmin = false, int $entityTypeId = EntityType::DEAL): string
	{
		$h = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
		if($view === AckValue::VIEW_EMPTY)
		{
			return '<span class="shef-toolsai-ack" style="color:#999">—</span>';
		}
		if($view !== AckValue::VIEW_BUTTON)
		{
			return '<span class="shef-toolsai-ack">'.$h(AckValue::text($role, $view, $userName, $at, $byAdmin)).'</span>';
		}

		// Обработчик — атрибутом onclick: карточка вставляет HTML через
		// BX.html() (innerHTML), атрибут работает; ответ контроллера
		// подменяет содержимое — без перезагрузки. Без подтверждения: на
		// время запроса кнопка заблокирована, ошибка — текстом рядом.
		// Сделка — прежний запрос без entityTypeId (1.8.x), остальные — с ним.
		$extra = $entityTypeId !== EntityType::DEAL ? ',entityTypeId:'.$entityTypeId : '';
		// Отправка отметки; r — пояснение старшего (1.46.0), у менеджера пусто
		$send = sprintf(
			"function s(r){b.disabled=true;e.textContent='';"
			."BX.ajax.runAction('shef:toolsaicrm.dealflag.mark',{data:{dealId:%d,role:'%s'%s,reason:r}})"
			.".then(function(x){w.outerHTML=x.data.html;},function(x){b.disabled=false;"
			."e.textContent=(x&&x.errors&&x.errors[0]&&x.errors[0].message)||'Не удалось поставить отметку';});}",
			$dealId,
			$role,
			$extra
		);
		$head = "var b=this,w=b.parentNode,e=w.querySelector('.shef-toolsai-ack-error');".$send;
		if($role !== AckValue::ROLE_SENIOR)
		{
			$js = $head."s('');return false;";
		}
		else
		{
			// Старший (1.46.0): модальное окно — без пояснения «почему риски сняты» отметку не ставим
			$js = $head.sprintf(
				"var t=document.createElement('textarea'),c=document.createElement('div'),m=%d;"
				."t.rows=5;t.maxLength=%d;t.placeholder='Например: клиент подтвердил оплату на звонке 12.10, счёт выставлен, следующий контакт 15.10';"
				."t.style.cssText='width:100%%;box-sizing:border-box;padding:8px;border:1px solid #c6cdd3;border-radius:6px;font:inherit;resize:vertical';"
				."c.style.cssText='width:100%%;box-sizing:border-box';"
				."c.innerHTML='<p style=\"margin:0 0 10px;color:#525c69;line-height:1.45\">Почему вы считаете, что все риски устранены? Пояснение увидят менеджер и руководитель в ленте сделки, его учтёт и следующий анализ AI.</p>';"
				."c.appendChild(t);var h=document.createElement('div');h.style.cssText='margin-top:6px;font-size:12px;color:#828b95';h.textContent='Не короче '+m+' символов';c.appendChild(h);"
				// 1.63.5: штатное современное окно ui.dialogs.messagebox (было устаревшее BX.PopupWindow)
				."var ok=function(){var r=t.value.trim();if(r.length<m){t.style.borderColor='#d0021b';h.style.color='#d0021b';t.focus();return false;}s(r);return true;};"
				."var MB=BX.UI&&BX.UI.Dialogs&&BX.UI.Dialogs.MessageBox;"
				."if(!MB){var v=window.prompt('Почему риски устранены? Не короче '+m+' символов','');if(v!==null){t.value=v;ok();}return false;}"
				."var p=MB.create({useAirDesign:true,title:'Риск под контролем?',message:c,modal:true,buttons:BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,"
				."okCaption:'Подтвердить',cancelCaption:'Отмена',popupOptions:{closeIcon:false,closeByEsc:true},onOk:function(){return ok();}});"
				."p.show();setTimeout(function(){t.focus();},50);return false;",
				AckValue::REASON_MIN,
				AckValue::REASON_MAX
			);
		}

		return '<span class="shef-toolsai-ack" style="display:inline-block">'
			.'<button type="button" class="ui-btn ui-btn-primary ui-btn-sm" onclick="'.$h($js).'">'
			.$h(AckValue::buttonTitle($role)).'</button>'
			.'<span class="shef-toolsai-ack-error" style="color:#d0021b;margin-left:8px"></span>'
			.'<span class="shef-toolsai-ack-hint" style="display:block;margin-top:4px;color:#828b95;font-size:12px;line-height:1.4">'
			.$h(AckValue::hint($role, $byAdmin)).'</span></span>';
	}

	/**
	 * Стили ui-btn (расширение ui.buttons). В карточке сделки они обычно
	 * уже есть (кнопки тулбара); на случай, если нет, — подключаем.
	 */
	private static function loadButtons(): void
	{
		try
		{
			\Bitrix\Main\UI\Extension::load(['ui.buttons', 'ui.dialogs.messagebox']);
		}
		catch(\Throwable)
		{
		}
	}

	private static function nameOf(int $userId): string
	{
		return $userId > 0 ? static::getUserName($userId) : '';
	}

	/**
	 * Ответственный за запись и старший её профиля.
	 *
	 * @return array{0: int, 1: int}
	 */
	public static function getAddressees(int $dealId, int $entityTypeId = EntityType::DEAL): array
	{
		$assigned = 0;
		$senior = 0;
		try
		{
			$type = EntityTypes::get($entityTypeId);
			if($type === null)
			{
				return [0, 0];
			}
			if(\Bitrix\Main\Loader::includeModule('crm'))
			{
				$item = \Bitrix\Crm\Service\Container::getInstance()->getFactory($entityTypeId)?->getItem($dealId);
				$assigned = (int)$item?->getAssignedById();
			}
			$profileId = (int)(CheckStore::get($type, $dealId)['PROFILE_ID'] ?? 0);
			if($profileId > 0)
			{
				$profile = DealProfileTable::getList(['select' => ['SENIOR_ID'], 'filter' => ['=ID' => $profileId], 'limit' => 1])->fetch();
				$senior = (int)($profile['SENIOR_ID'] ?? 0);
			}
		}
		catch(\Throwable)
		{
		}

		return [$assigned, $senior];
	}

	public static function getUserName(int $userId): string
	{
		try
		{
			$row = \Bitrix\Main\UserTable::getList(['select' => ['LAST_NAME', 'NAME', 'LOGIN'], 'filter' => ['=ID' => $userId], 'limit' => 1])->fetch();
		}
		catch(\Throwable)
		{
			$row = false;
		}
		if(!is_array($row))
		{
			return '#'.$userId;
		}
		$name = trim(($row['LAST_NAME'] ?? '').' '.($row['NAME'] ?? ''));

		return $name !== '' ? $name : (string)($row['LOGIN'] ?? '#'.$userId);
	}
}
