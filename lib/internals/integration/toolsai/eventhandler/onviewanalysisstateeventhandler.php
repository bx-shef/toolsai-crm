<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Internals\Integration\ToolsAi\EventHandler;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Shef\ToolsAiCrm\Deal\Model\DealProfileTable;
use Shef\ToolsAiCrm\Integration\Links;
use Shef\ToolsAiCrm\Main\Constants;

/**
 * Обработчик shef.toolsai:OnViewAnalysisState — состояние авто-анализа типа
 * записи для вида «AI»: включён ли и где настройки.
 *
 * Контракт (shef.toolsai Public\Event\ViewAnalysisStateEvent): параметр
 * entityTypeId; ответ EventResult SUCCESS с ['enabled' => bool, 'settingsUrl' => путь]
 * от имени модуля. enabled — у типа есть включённый профиль (как считал вид
 * «AI» до выноса анализа); тип без профилей, в том числе не анализируемый, —
 * false. settingsUrl — раздел «Авто-анализ CRM».
 *
 * Сбой чтения — enabled false: вид тогда не считает анализ в оценке запуска.
 * Чистая часть — answer(), tests/viewevents_test.php.
 */
final class OnViewAnalysisStateEventHandler
{
	public static function handle(Event $event): EventResult
	{
		$entityTypeId = (int)($event->getParameter('entityTypeId') ?? 0);
		try
		{
			$enabled = $entityTypeId > 0 && DealProfileTable::getProfiles(true, $entityTypeId) !== [];
		}
		catch(\Throwable)
		{
			$enabled = false;
		}

		return new EventResult(EventResult::SUCCESS, static::answer($enabled), Constants::MODULE_ID);
	}

	/**
	 * @return array{enabled: bool, settingsUrl: string}
	 */
	public static function answer(bool $enabled): array
	{
		return ['enabled' => $enabled, 'settingsUrl' => Links::section(Links::SECTION_PROFILES)];
	}
}
