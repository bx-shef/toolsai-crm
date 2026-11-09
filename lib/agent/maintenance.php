<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Agent;

/**
 * Агент доведения раз в 10 минут: путь к Main\Maintenance::ensureOnce() после обновления
 * копированием файлов, когда установщик не запускался и ни одна подписка модуля ещё не
 * подключила его на хите. Ядро зовёт агент и без подписок модуля. Ставят установщик
 * (InstallEvents()), Main\Setup и доведение (Main\Maintenance::installAgent()), снимает
 * удаление (RemoveModuleAgents).
 */
final class Maintenance
{
	public static function run(): string
	{
		\Shef\ToolsAiCrm\Main\Maintenance::ensureOnce();

		return \Shef\ToolsAiCrm\Main\Maintenance::AGENT_NAME;
	}
}
