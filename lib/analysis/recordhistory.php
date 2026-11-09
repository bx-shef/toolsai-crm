<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Analysis;

use Bitrix\Crm\Service\Container as CrmContainer;
use Bitrix\Main\Loader;

/**
 * «История записи» наружу (1.45.0, #34): та же лента, что получает анализ, —
 * для чата справа и MCP. Сама по себе не тратит квоту и не зовёт модель.
 *
 * Права: запись должна быть видна пользователю в CRM (штатная проверка
 * чтения). Права модуля на сценарий (чат, MCP) проверяет вызывающий —
 * действие shef:toolsai.view.history пускает с «Вид «AI»: открывать» или
 * «Чат AI-помощника: спрашивать».
 */
final class RecordHistory
{
	/**
	 * Лента записи или null — тип не поддерживается, записи нет или она не видна пользователю.
	 *
	 * @return array{title: string, noun: string, text: string}|null
	 */
	public static function get(int $entityTypeId, int $id, int $userId, int $limit = Timeline::LIMIT): ?array
	{
		if($id <= 0 || $userId <= 0 || !Loader::includeModule('crm'))
		{
			return null;
		}
		$type = EntityTypes::get($entityTypeId);
		$factory = CrmContainer::getInstance()->getFactory($entityTypeId);
		if($type === null || $factory === null || !CrmContainer::getInstance()->getUserPermissions($userId)->checkReadPermissions($entityTypeId, $id))
		{
			return null;
		}
		$item = $factory->getItem($id);
		if($item === null)
		{
			return null;
		}

		return [
			'title' => (string)$item->getTitle(),
			'noun' => $type->noun,
			'text' => static::text($type->noun, $id, (string)$item->getTitle(), Timeline::render(TimelineSource::collect($type, $id), $limit)),
		];
	}

	/** Текст для модели: заголовок записи и лента; касаний нет — так и пишем. */
	public static function text(string $noun, int $id, string $title, string $timeline): string
	{
		return sprintf('%s #%d: %s', $noun, $id, $title)."\n"
			.($timeline !== '' ? "История — все касания по времени (от старого к новому):\n".$timeline : 'Касаний пока нет.');
	}
}
