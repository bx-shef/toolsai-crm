<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Analysis;

use Shef\ToolsAiCrm\Analysis\Model\EntityCheckTable;

/**
 * «Последняя проверка» записи любого типа — одна таблица
 * shef_toolsaicrm_entity_check с типом сущности в ключе.
 */
final class CheckStore
{
	public static function get(EntityType $type, int $id): ?array
	{
		return EntityCheckTable::getByEntity($type->entityTypeId, $id);
	}

	public static function save(EntityType $type, int $id, array $fields): void
	{
		EntityCheckTable::save($type->entityTypeId, $id, $fields);
	}
}
