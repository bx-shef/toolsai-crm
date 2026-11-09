<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Analysis;

/**
 * Очистка полей «AI: …» у записи, созданной из лида (1.11.0). Чистая
 * логика — без ядра; обработчики событий — Deal\Field\Handlers.
 *
 * Зачем. При конвертации лида ядро переносит в новую запись
 * пользовательские поля лида, и наши UF_SHEF_AI_* приезжают в сделку и
 * смарт-процесс с оценкой ЛИДА: чужой риск, «почему», отметки, история.
 * Как именно ядро это делает (crm, файлы модуля):
 *
 *   - старый путь (сделка, контакт, компания):
 *     conversion/leadconversionmapper.php, createMap() — пары полей из
 *     Synchronization\UserFieldSynchronizer::getIntersection(): поля
 *     совпадают по ПОДПИСИ (getFieldComplianceCode(): EDIT_FORM_LABEL без
 *     пробелов, в нижнем регистре), типу и множественности; map() копирует
 *     значения (mapUserField()); сделку создаёт
 *     conversion/leadconverter.php -> CCrmDeal::Add() с LEAD_ID;
 *   - новый путь (смарт-процесс, а при новом API и сделка):
 *     conversion/mapper.php, fillMapByComparingFields() — поля с ОДИНАКОВЫМ
 *     ИМЕНЕМ из коллекций фабрик плюс то же getIntersection();
 *     conversion/entityconverter.php, fillDestinationItemWithDataFromSourceItem()
 *     ставит родителя (PARENT_ID_1 — ParentFieldManager) и значения,
 *     createDestinationItem() -> Factory::getAddOperation()->launch().
 *
 * У наших полей и имя, и подпись на всех типах одни — переезжают оба раза.
 *
 * Когда запись «из лида»: LEAD_ID > 0 (сделка, старый путь и совместимые
 * поля фабрики) или PARENT_ID_1 > 0 (родитель — лид, CCrmOwnerType::Lead = 1).
 */
final class ConversionCleaner
{
	/** Поле родителя-лида в новой записи: ParentFieldManager::getParentFieldName(1). */
	public const PARENT_LEAD = 'PARENT_ID_1';

	/** Создаётся ли запись из лида. */
	public static function isFromLead(array $fields): bool
	{
		foreach(['LEAD_ID', static::PARENT_LEAD] as $key)
		{
			$value = $fields[$key] ?? null;
			if((is_int($value) || (is_string($value) && 1 === preg_match('/^\d+$/', trim($value)))) && (int)$value > 0)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Какие наши поля очистить: те, что есть в массиве полей записи.
	 * Поля, которых в массиве нет, не добавляем — у типа их может не быть.
	 *
	 * @return string[]
	 */
	public static function fieldsToClear(array $fields): array
	{
		return array_values(array_filter(EntityFields::getCodes(), static fn(string $code): bool => array_key_exists($code, $fields)));
	}

	/**
	 * Поля новой записи до сохранения -> поля с очищенными UF_SHEF_AI_*.
	 * Не из лида — без изменений.
	 */
	public static function clear(array $fields): array
	{
		if(!static::isFromLead($fields))
		{
			return $fields;
		}
		foreach(static::fieldsToClear($fields) as $code)
		{
			$fields[$code] = null;
		}

		return $fields;
	}

	/**
	 * Что записать в уже созданную запись (смарт-процесс: у фабрики нет
	 * совместимого события «до добавления»): только непустые наши поля.
	 *
	 * @param array<string, mixed> $values наши поля записи
	 * @return array<string, null>
	 */
	public static function valuesToReset(array $values): array
	{
		$result = [];
		foreach(EntityFields::getCodes() as $code)
		{
			$value = $values[$code] ?? null;
			if($value !== null && $value !== '' && $value !== false && $value !== 0 && $value !== '0' && $value !== [])
			{
				$result[$code] = null;
			}
		}

		return $result;
	}
}
