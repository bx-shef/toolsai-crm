<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Internals\Integration\Shef\ToolsAiKb\EventHandler;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Shef\ToolsAiCrm\Main\Constants;

/**
 * Справка shef.toolsaicrm в «Базе знаний» портала: ответ на событие
 * shef.toolsaikb:OnKbSourcesCollect — пакет статей shef.toolsaicrm:guide.
 *
 * Статьи — Markdown пакета справки в каталоге kb/guide модуля. Показывают их
 * штатные страницы «Базы знаний 2.0».
 *
 * readOnly — статьи пакета всегда совпадают с текстом модуля: правка руками в
 * «Базе знаний» перезаписывается следующим импортом.
 *
 * Аргумент — Bitrix\Main\Event: жёсткой зависимости от shef.toolsaikb нет.
 * Каталога нет — пакетов нет, ошибки тоже.
 */
final class OnKbSourcesCollectEventHandler
{
	public const CODE = 'shef.toolsaicrm:guide';
	public const TITLE = 'AI: Автооценка CRM';
	/** Каталог статей от корня модуля. */
	public const DIR = 'kb/guide';

	/** Ответ на событие: пакет справки модуля. */
	public static function handle(Event $event): EventResult
	{
		return new EventResult(EventResult::SUCCESS, ['sources' => static::sources(dirname(__DIR__, 6))], Constants::MODULE_ID);
	}

	/**
	 * Пакеты статей модуля; каталога нет — пусто.
	 *
	 * @return list<array{code: string, title: string, dir: string, readOnly: bool}>
	 */
	public static function sources(string $moduleDir): array
	{
		$dir = realpath(rtrim($moduleDir, '/').'/'.self::DIR);
		if($dir === false || !is_dir($dir))
		{
			return [];
		}

		return [['code' => self::CODE, 'title' => self::TITLE, 'dir' => $dir, 'readOnly' => true]];
	}
}
