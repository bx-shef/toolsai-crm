<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Internals\Integration\Shef\ToolsAiKb;

use Bitrix\Main\Loader;
use Shef\ToolsAiCrm\Internals\Integration\Shef\ToolsAiKb\EventHandler\OnKbSourcesCollectEventHandler;

/**
 * Импорт справки модуля в «Базу знаний» портала (shef.toolsaikb 1.7.0+):
 * KbImportService::importPackage('shef.toolsaicrm:guide') из установщика
 * (DoInstall() и installModuleData(), который зовёт shef:modules:sync).
 *
 * Мягко: нет shef.toolsaikb или его сервиса — ничего; занято (busy), ошибка,
 * даже исключение — установку не роняет. Статьи придут следующим импортом
 * или кнопкой «Обновить статьи».
 */
final class KbImport
{
	public const SERVICE = '\\Shef\\ToolsAiKb\\Public\\Service\\KbImportService';

	/** Статус KbImportResult (ok|busy|error) или null — импорта не было. */
	public static function run(): ?string
	{
		try
		{
			if(!Loader::includeModule('shef.toolsaikb') || !class_exists(self::SERVICE) || !method_exists(self::SERVICE, 'importPackage'))
			{
				return null;
			}
			$result = (self::SERVICE)::importPackage(OnKbSourcesCollectEventHandler::CODE);

			return is_object($result) && isset($result->status) ? (string)$result->status : null;
		}
		catch(\Throwable)
		{
			return 'error';
		}
	}
}
