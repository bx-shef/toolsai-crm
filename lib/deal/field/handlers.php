<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Deal\Field;

use Bitrix\Main\EventResult;
use Shef\ToolsAiCrm\Analysis\EntityFields;
use Shef\ToolsAiCrm\Analysis\EntityType;

/**
 * Обработчики, которые держат поля «AI: …» только для чтения
 * (Analysis\EntityFields, «ТОЛЬКО ЧТЕНИЕ»): сделка, лид, смарт-процесс.
 * Регистрируются EntityFields::registerEvents(). Событие «до изменения» не
 * несёт тип записи — поэтому по методу на событие, работа общая (keep()).
 */
final class Handlers
{
	/**
	 * main:onGetPublicEdit — наши штатные поля (риск, дата, почему, что
	 * сделать) в режиме правки показываются как просмотр, без поля ввода.
	 */
	public static function onGetPublicEdit(\Bitrix\Main\Event $event): ?EventResult
	{
		$params = $event->getParameters();
		$userField = $params[0] ?? null;
		if(
			!is_array($userField)
			|| !EntityType::isAnalysisUfEntity($userField['ENTITY_ID'] ?? null)
			|| !in_array($userField['FIELD_NAME'] ?? '', EntityFields::getPlainCodes(), true)
		)
		{
			return null;
		}

		global $USER_FIELD_MANAGER;
		$html = (string)$USER_FIELD_MANAGER->GetPublicView($userField, is_array($params[1] ?? null) ? $params[1] : []);

		return new EventResult(EventResult::SUCCESS, $html, 'shef.toolsaicrm');
	}

	/**
	 * crm:OnBeforeCrmDealUpdate — правка наших полей не модулем
	 * откатывается к значению из базы. Работает и для фабрики (новая
	 * карточка, Operation\Action\Compatible\SendEvent\WithCancel\Update
	 * берёт поля обратно из массива), и для CCrmDeal::Update.
	 */
	public static function onBeforeDealUpdate(array &$fields): bool
	{
		return static::keep($fields, EntityType::deal()->ufEntityId);
	}

	/**
	 * crm:OnBeforeCrmLeadUpdate (1.9.0) — то же для лида: событие зовёт и
	 * фабрика (crm/lib/Service/Factory/Lead.php:542), и CCrmLead::Update
	 * (crm/classes/general/crm_lead.php:2224).
	 */
	public static function onBeforeLeadUpdate(array &$fields): bool
	{
		return static::keep($fields, EntityType::lead()->ufEntityId);
	}

	/**
	 * crm:OnBeforeCrmDealAdd (1.11.0) — сделка из лида приходит с нашими
	 * полями лида (Analysis\ConversionCleaner): очистить до записи. Событие
	 * зовут и CCrmDeal::Add (crm/classes/general/crm_deal.php, «Rise
	 * BeforeAdd event»), и фабрика (crm/lib/Service/Factory/Deal.php,
	 * configureAddOperation -> SendEvent\WithCancel\Update берёт поля
	 * обратно в запись).
	 */
	public static function onBeforeDealAdd(array &$fields): bool
	{
		if(!EntityFields::isInternalWrite())
		{
			$fields = \Shef\ToolsAiCrm\Analysis\ConversionCleaner::clear($fields);
		}

		return true;
	}

	/**
	 * crm:onCrmDynamicItemAdd (1.11.0) — элемент смарт-процесса из лида.
	 * Совместимого события «до добавления» у фабрики смарт-процессов нет
	 * (crm/lib/Service/Factory/Dynamic.php, configureAddOperation: только
	 * SendEvent после записи); ORM-событие «до добавления» у элементов —
	 * своё на каждый тип и без модуля (main/lib/orm/event.php, имя
	 * сущности crm_items_<ID типа>). Поэтому — сразу после записи: наши
	 * непустые поля обнуляются своей записью (EntityFields::write(), без
	 * роботов и БП). Сбой не мешает созданию элемента.
	 */
	public static function onDynamicItemAdd(\Bitrix\Main\Event $event): void
	{
		try
		{
			$item = $event->getParameter('item');
			if(!$item instanceof \Bitrix\Crm\Item || !$item->hasField(\Shef\ToolsAiCrm\Analysis\ConversionCleaner::PARENT_LEAD))
			{
				return;
			}
			$values = [\Shef\ToolsAiCrm\Analysis\ConversionCleaner::PARENT_LEAD => $item->get(\Shef\ToolsAiCrm\Analysis\ConversionCleaner::PARENT_LEAD)];
			if(!\Shef\ToolsAiCrm\Analysis\ConversionCleaner::isFromLead($values))
			{
				return;
			}
			foreach(EntityFields::getCodes() as $code)
			{
				if($item->hasField($code))
				{
					$values[$code] = $item->get($code);
				}
			}
			$reset = \Shef\ToolsAiCrm\Analysis\ConversionCleaner::valuesToReset($values);
			$type = $reset !== [] ? \Shef\ToolsAiCrm\Analysis\EntityTypes::get($item->getEntityTypeId()) : null;
			if($type !== null && (int)$item->getId() > 0)
			{
				EntityFields::write($type, (int)$item->getId(), $reset, null, \Shef\ToolsAiCrm\Analysis\CrmWrite::SOURCE_CONVERSION);
			}
		}
		catch(\Throwable $throwable)
		{
			\Shef\ToolsAiCrm\Container::getLogger(static::class)?->error($throwable);
		}
	}

	private static function keep(array &$fields, string $ufEntityId): bool
	{
		if(EntityFields::isInternalWrite())
		{
			return true;
		}

		$dealId = (int)($fields['ID'] ?? 0);
		global $USER_FIELD_MANAGER;
		foreach(static::getTouched($fields) as $code)
		{
			if($dealId > 0 && is_object($USER_FIELD_MANAGER))
			{
				$fields[$code] = $USER_FIELD_MANAGER->GetUserFieldValue($ufEntityId, $code, $dealId);
			}
			else
			{
				unset($fields[$code]);
			}
		}

		return true;
	}

	/** Какие наши поля есть в массиве обновления. Чистая функция. */
	public static function getTouched(array $fields): array
	{
		return array_values(array_filter(EntityFields::getCodes(), static fn(string $code): bool => array_key_exists($code, $fields)));
	}
}
