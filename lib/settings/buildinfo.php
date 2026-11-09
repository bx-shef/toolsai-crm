<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Settings;

/**
 * Строка версии под левым меню настроек: «shef.toolsaicrm 1.0.0 · сборка
 * a1b2c3d · 19.10.2026» — для обращений в поддержку, щелчок копирует.
 *
 * Версия и дата — install/version.php (VERSION, VERSION_DATE). Сборка — короткий
 * хеш коммита из kb/version.txt («<версия> <хеш>»): его пишет tools/kb-build.php,
 * и kb/ собирают в PR выпуска вместе с версией. Хеш берём, только если версия в
 * файле совпадает с модулем; иначе (и без файла) — только версия и дата.
 */
final class BuildInfo
{
	/** Текст строки. Чистая функция — держит tests/buildinfo_test.php. */
	public static function line(string $moduleId, string $version, string $versionDate, string $kbVersionTxt): string
	{
		$parts = [trim($moduleId.' '.$version)];
		[$kbVersion, $hash] = array_pad(preg_split('/\s+/', trim($kbVersionTxt)) ?: [], 2, '');
		if($kbVersion === $version && preg_match('/^[0-9a-f]{7,40}$/', $hash))
		{
			$parts[] = 'сборка '.$hash;
		}
		$time = strtotime($versionDate);
		if($time !== false && $versionDate !== '')
		{
			$parts[] = date('d.m.Y', $time);
		}

		return implode(' · ', $parts);
	}

	/** Строка для этого модуля: читает version.php и kb/version.txt. */
	public static function current(): string
	{
		$root = dirname(__DIR__, 2);
		$arModuleVersion = [];
		include $root.'/install/version.php';
		$kb = is_file($root.'/kb/version.txt') ? (string)file_get_contents($root.'/kb/version.txt') : '';

		return static::line(\Shef\ToolsAiCrm\Main\Constants::MODULE_ID, (string)($arModuleVersion['VERSION'] ?? ''), (string)($arModuleVersion['VERSION_DATE'] ?? ''), $kb);
	}

	/**
	 * HTML строки (экранирован), щелчок копирует строку целиком штатным BX.clipboard.
	 * Левый край — как у заголовка «AI: автоанализ CRM» (у .ui-sidepanel-head
	 * отступ 6px), модуль и версия — первой строкой, сборка и дата — второй: в узком
	 * меню текст не рвётся посреди хеша.
	 */
	public static function html(string $line): string
	{
		$e = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		[$head, $rest] = array_pad(explode(' · ', $line, 2), 2, '');

		return '<div class="shef-tai-build" data-line="'.$e($line).'" title="Скопировать для обращения в поддержку" role="button" tabindex="0"'
			.' onclick="var t=this.getAttribute(\'data-line\');if(window.BX&&BX.clipboard){BX.clipboard.copy(t);}else if(navigator.clipboard){navigator.clipboard.writeText(t);}if(window.BX&&BX.UI&&BX.UI.Notification){BX.UI.Notification.Center.notify({content:\'Скопировано\'});}"'
			.' style="margin:12px 0 0;padding:0 6px;font-size:11px;line-height:1.5;color:#a8adb4;cursor:pointer;overflow-wrap:anywhere">'
			.'<span style="display:block">'.$e($head).'</span>'
			.($rest !== '' ? '<span style="display:block">'.$e($rest).'</span>' : '')
			.'</div>';
	}
}
