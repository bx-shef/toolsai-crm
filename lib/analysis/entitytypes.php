<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Analysis;

use Bitrix\Main\Loader;

/**
 * Типы сущностей из рантайма CRM (1.9.0): сделка, лид и смарт-процессы
 * портала. Описание каждого — EntityType (чистое).
 *
 * Смарт-процессы — через фабрику (Service\Container::getFactory()):
 * ENTITY_ID полей у них — `CRM_{ID типа}`, его отдаёт сама фабрика
 * (Factory::getUserFieldEntityId(), crm/lib/Service/Factory.php:1092), воронки —
 * Factory::isCategoriesEnabled() (:1164).
 */
final class EntityTypes
{
	/** Тип по entityTypeId; неизвестный или фабрики нет — null. */
	public static function get(int $entityTypeId): ?EntityType
	{
		if($entityTypeId === EntityType::DEAL)
		{
			return EntityType::deal();
		}
		if($entityTypeId === EntityType::LEAD)
		{
			return EntityType::lead();
		}
		if(!EntityType::isSupportedId($entityTypeId) || !Loader::includeModule('crm'))
		{
			return null;
		}

		$factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory($entityTypeId);
		if($factory === null)
		{
			return null;
		}

		return EntityType::dynamic($entityTypeId, $factory->getUserFieldEntityId(), (string)$factory->getEntityDescription(), $factory->isCategoriesEnabled());
	}

	/** entityTypeId по ENTITY_ID полей (CRM_DEAL, CRM_LEAD, CRM_{ID типа}); не наш — 0. */
	public static function fromUfEntityId(mixed $ufEntityId): int
	{
		$parsed = EntityType::parseUfEntity($ufEntityId);
		if($parsed === null)
		{
			return 0;
		}
		if($parsed['entityTypeId'] > 0 || !Loader::includeModule('crm'))
		{
			return $parsed['entityTypeId'];
		}

		return (int)\Bitrix\Crm\Service\Container::getInstance()->getType($parsed['dynamicTypeId'])?->getEntityTypeId();
	}

	/**
	 * Смарт-процессы портала.
	 *
	 * @return EntityType[] entityTypeId => тип
	 */
	public static function getDynamic(): array
	{
		if(!Loader::includeModule('crm') || !class_exists(\Bitrix\Crm\Model\Dynamic\TypeTable::class))
		{
			return [];
		}

		$result = [];
		$rows = \Bitrix\Crm\Model\Dynamic\TypeTable::getList(['select' => ['ENTITY_TYPE_ID'], 'order' => ['ENTITY_TYPE_ID' => 'ASC']])->fetchAll();
		foreach($rows as $row)
		{
			$type = static::get((int)$row['ENTITY_TYPE_ID']);
			if($type !== null && $type->isDynamic())
			{
				$result[$type->entityTypeId] = $type;
			}
		}

		return $result;
	}

	/**
	 * Воронки типа: ID => название. Лид — одна «воронка» 0.
	 *
	 * @return array<int, string>
	 */
	public static function getCategories(EntityType $type): array
	{
		if(!$type->hasCategories || !Loader::includeModule('crm'))
		{
			return [0 => $type->title];
		}

		$factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory($type->entityTypeId);
		$result = [];
		foreach($factory?->getCategories() ?? [] as $category)
		{
			$result[(int)$category->getId()] = (string)$category->getName();
		}

		return $result;
	}
}
