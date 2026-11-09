<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Analysis\Prompt;

use Bitrix\Main\Type\DateTime;
use Shef\ToolsAiCrm\Analysis\EntityType;
use Shef\ToolsAiCrm\Analysis\Model\PromptTable;

/**
 * Где лежат шаблоны промпта анализа: свои — строки PromptTable с кодом
 * `analysis.<код уровня>` (PromptLevel::code()), стандартные — в коде
 * (PromptTemplate::defaults()). Какой действует — PromptResolver
 * (воронка -> тип -> стандартный).
 */
final class PromptStore
{
	public const PREFIX = 'analysis.';

	/** Шаблон «смарт-процессы, общий» 1.9.0 — до переноса в шаблоны типов. */
	public const LEGACY_DYNAMIC = 'dynamic';

	/**
	 * Действующий шаблон уровня и чей он. Сбой таблицы — стандартный.
	 *
	 * @return array{template: PromptTemplate, source: ?PromptLevel}
	 */
	public static function resolve(PromptLevel $level): array
	{
		try
		{
			$rows = static::getRows(array_map(static fn(PromptLevel $item): string => $item->code(), $level->chain()));
		}
		catch(\Throwable)
		{
			$rows = [];
		}

		return PromptResolver::resolve($level, $rows);
	}

	/**
	 * Свои шаблоны по кодам уровней.
	 *
	 * @param string[] $codes коды уровней; пусто — все шаблоны анализа
	 * @return array<string, array> код уровня => строка
	 */
	public static function getRows(array $codes = []): array
	{
		$filter = $codes !== []
			? ['@CODE' => array_map(static fn(string $code): string => static::PREFIX.$code, $codes)]
			: ['%=CODE' => static::PREFIX.'%'];
		$result = [];
		foreach(PromptTable::getList(['filter' => $filter])->fetchAll() as $row)
		{
			$result[substr((string)$row['CODE'], strlen(static::PREFIX))] = $row;
		}

		return $result;
	}

	/** Строка таблицы -> шаблон вида $kind. Пустая часть — из стандартного. Чистая функция. */
	public static function fromRow(string $kind, array $row): PromptTemplate
	{
		$default = PromptTemplate::defaults($kind);
		$system = trim((string)($row['SYSTEM_TEXT'] ?? ''));
		$user = trim((string)($row['USER_TEXT'] ?? ''));
		[$extra] = PromptTemplate::parseExtraFields((string)($row['EXTRA_FIELDS'] ?? ''));
		$at = $row['CHANGED_AT'] ?? null;

		return new PromptTemplate(
			$default->key,
			$system !== '' ? $system : $default->system,
			$user !== '' ? $user : $default->user,
			$extra,
			false,
			(int)($row['VERSION'] ?? 1),
			$at instanceof \Bitrix\Main\Type\Date ? $at->getTimestamp() : 0,
			(int)($row['CHANGED_BY'] ?? 0),
		);
	}

	/**
	 * Сохранить свой шаблон уровня: версия +1, кто и когда.
	 *
	 * @param string[] $extraFields
	 */
	public static function save(PromptLevel $level, string $system, string $user, array $extraFields, int $userId): void
	{
		static::saveCode(static::PREFIX.$level->code(), $system, $user, $extraFields, $userId);
	}

	/** «Вернуть стандартный»: удалить свой шаблон уровня — действует шаблон уровня выше. */
	public static function reset(PromptLevel $level): void
	{
		static::resetCode(static::PREFIX.$level->code());
	}

	/**
	 * Записать строку таблицы по полному коду (с пространством имён:
	 * «analysis.…», «copilot.…»): версия +1, кто и когда. Общее для всех
	 * промптов модуля — механизм один (bx-shef/toolsai#27).
	 *
	 * @param string[] $extraFields
	 */
	public static function saveCode(string $code, string $system, string $user, array $extraFields, int $userId): void
	{
		$fields = [
			'SYSTEM_TEXT' => mb_substr(trim($system), 0, PromptTemplate::TEXT_MAX),
			'USER_TEXT' => mb_substr(trim($user), 0, PromptTemplate::TEXT_MAX),
			'EXTRA_FIELDS' => implode(',', $extraFields),
			'CHANGED_AT' => new DateTime(),
			'CHANGED_BY' => $userId,
		];
		$row = PromptTable::getByCode($code);
		if($row === null)
		{
			PromptTable::add(['CODE' => $code, 'VERSION' => 1] + $fields);

			return;
		}
		PromptTable::update((int)$row['ID'], ['VERSION' => (int)$row['VERSION'] + 1] + $fields);
	}

	/** Удалить строку по полному коду («вернуть стандартный»). */
	public static function resetCode(string $code): void
	{
		$row = PromptTable::getByCode($code);
		if($row !== null)
		{
			PromptTable::delete((int)$row['ID']);
		}
	}

	/**
	 * Шаблон «смарт-процессы, общий» из 1.9.0 (код analysis.dynamic) — в
	 * шаблон каждого смарт-процесса, у которого своего ещё нет; потом общий
	 * удаляется. Идемпотентно: общего нет — ничего не делаем; сбой записи —
	 * общий остаётся, повтор доделает.
	 *
	 * @param int[] $dynamicTypeIds entityTypeId смарт-процессов портала
	 * @return array{ok: bool, message: string}
	 */
	public static function migrateCommonDynamic(array $dynamicTypeIds): array
	{
		PromptTable::init();
		$common = PromptTable::getByCode(static::PREFIX.static::LEGACY_DYNAMIC);
		if($common === null)
		{
			return ['ok' => true, 'message' => 'переносить нечего'];
		}

		// Смарт-процессов на портале сейчас нет — копировать некуда, а удаление
		// стёрло бы текст администратора насовсем: общий шаблон остаётся до появления типов.
		if(!static::canDropCommonDynamic($dynamicTypeIds))
		{
			return ['ok' => true, 'message' => 'смарт-процессов нет — общий шаблон смарт-процессов оставлен'];
		}

		$copied = 0;
		foreach(static::planCommonDynamic($dynamicTypeIds, array_keys(static::getRows())) as $code)
		{
			$result = PromptTable::add([
				'CODE' => static::PREFIX.$code,
				'SYSTEM_TEXT' => $common['SYSTEM_TEXT'] ?? null,
				'USER_TEXT' => $common['USER_TEXT'] ?? null,
				'EXTRA_FIELDS' => (string)($common['EXTRA_FIELDS'] ?? ''),
				'VERSION' => (int)($common['VERSION'] ?? 1),
				'CHANGED_AT' => $common['CHANGED_AT'] ?? new DateTime(),
				'CHANGED_BY' => (int)($common['CHANGED_BY'] ?? 0),
			]);
			if(!$result->isSuccess())
			{
				return ['ok' => false, 'message' => 'шаблон '.$code.' не записан: '.implode('; ', $result->getErrorMessages())];
			}
			$copied++;
		}
		PromptTable::delete((int)$common['ID']);

		return ['ok' => true, 'message' => 'общий шаблон смарт-процессов перенесён в шаблоны типов: '.$copied];
	}

	/**
	 * Можно ли убрать общий шаблон смарт-процессов 1.9.0 после переноса: только если на
	 * портале есть смарт-процессы — иначе его текст некуда скопировать. Чистая функция.
	 *
	 * @param int[] $dynamicTypeIds
	 */
	public static function canDropCommonDynamic(array $dynamicTypeIds): bool
	{
		return array_filter(array_map('intval', $dynamicTypeIds), static fn(int $id): bool => $id > 0) !== [];
	}

	/**
	 * Коды уровней, куда копировать общий шаблон: смарт-процессы без своего. Чистая функция.
	 *
	 * @param int[] $dynamicTypeIds
	 * @param string[] $existingCodes коды своих шаблонов
	 * @return string[]
	 */
	public static function planCommonDynamic(array $dynamicTypeIds, array $existingCodes): array
	{
		$result = [];
		foreach(array_unique(array_map('intval', $dynamicTypeIds)) as $entityTypeId)
		{
			$code = EntityType::KEY_DYNAMIC.'.'.$entityTypeId;
			if($entityTypeId > EntityType::DEAL && !in_array($code, $existingCodes, true))
			{
				$result[] = $code;
			}
		}

		return $result;
	}
}
