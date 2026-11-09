<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Integration;

use Shef\ToolsAiCrm\Main\Constants;

/**
 * Адреса разделов публичной страницы модуля (/shef/toolsaicrm/?section=…) для
 * соседей: карточки «ракеты», ответы событий вида «AI», ссылки REST.
 *
 * Свой класс, а не Settings\Sections: соседям нужен только адрес, и он не
 * зависит от того, как страница рисует разделы. Коды разделов — те же,
 * что в Settings\Sections.
 */
final class Links
{
	public const SECTION_PROFILES = 'profiles';
	public const SECTION_PROMPTS = 'prompts';
	public const SECTION_MANAGERS = 'stats_managers';

	/** Адрес раздела; параметры — после section. */
	public static function section(string $section, array $params = []): string
	{
		return Constants::PUBLIC_URL.'?'.http_build_query(['section' => $section] + $params, '', '&', PHP_QUERY_RFC3986);
	}
}
