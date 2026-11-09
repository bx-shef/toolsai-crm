<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Settings;

use Shef\ToolsAiCrm\Main\Constants;

/**
 * Разделы страницы модуля в публичной части: /shef/toolsaicrm/.
 *
 * Одно место настроек и отчётов — страница в слайдере, слева меню разделов,
 * как у штатных настроек CRM (bitrix:ui.sidepanel.wrappermenu). Раздел — файл
 * модуля settings/<код>.php (settings/component.php даёт ему адрес,
 * экранирование, сообщения и «?»).
 *
 * Разделы:
 *   - «Общие» — агент анализа (включение, лимит, интервал, запись в CRM),
 *     ссылки на подключение модели и расход в shef.toolsai;
 *   - «Авто-анализ CRM» — профили и скрипты оценки звонков;
 *   - «Промпты анализа» — шаблоны промпта по уровням;
 *   - «Анализ записей CRM» и «Оценка работы менеджеров» — статистика за период.
 *
 * Класс без ядра: адреса и список разделов покрыты тестом.
 */
final class Sections
{
	public const OPTIONS = 'options';
	public const PROFILES = 'profiles';
	public const PROMPTS = 'prompts';
	/** Статистика: анализ записей CRM */
	public const STATS_CHECKS = 'stats_checks';
	/** Статистика: оценка работы менеджеров */
	public const STATS_MANAGERS = 'stats_managers';

	public const DEFAULT = self::OPTIONS;

	/** Страница настроек shef.toolsai: подключение модели, расход и включение (не наша — ссылки). */
	public const CORE_URL = '/shef/toolsai/';
	public const CORE_SECTION_OPTIONS = 'options';
	public const CORE_SECTION_QUOTA = 'quota';

	/** Отчёты: страница с верхней панелью (в ней штатный фильтр периода). */
	public const REPORTS = [self::STATS_CHECKS, self::STATS_MANAGERS];

	/** Отчёты статистики: код раздела => блок отчёта в settings/stats.php. */
	public const STATS_PAGES = [
		self::STATS_CHECKS => 'checks',
		self::STATS_MANAGERS => 'managers',
	];

	/**
	 * Разделы в виде страницы настроек портала: заголовок и описание — на
	 * сером фоне, блоки — белыми карточками (.shef-tai-card). Оформление даёт
	 * settings/component.php.
	 */
	public const CARDS = [self::OPTIONS, self::PROFILES, self::PROMPTS, self::STATS_CHECKS, self::STATS_MANAGERS];

	/** Параметры, с которыми страница остаётся в слайдере при переходах. */
	public const IFRAME_PARAMS = ['IFRAME' => 'Y', 'IFRAME_TYPE' => 'SIDE_SLIDER'];

	/**
	 * @return array<string, array{title: string, file: string}> в порядке меню
	 */
	public static function getList(): array
	{
		return [
			self::OPTIONS => ['title' => 'Общие', 'file' => '/settings/options.php'],
			self::PROFILES => ['title' => 'Авто-анализ CRM', 'file' => '/settings/profiles.php'],
			self::PROMPTS => ['title' => 'Промпты анализа', 'file' => '/settings/prompts.php'],
			self::STATS_CHECKS => ['title' => 'Анализ записей CRM', 'file' => '/settings/stats.php'],
			self::STATS_MANAGERS => ['title' => 'Оценка работы менеджеров', 'file' => '/settings/stats.php'],
		];
	}

	/** Блок отчёта статистики по коду раздела; не статистика — null. */
	public static function statsBlock(string $section): ?string
	{
		return self::STATS_PAGES[$section] ?? null;
	}

	public static function isReport(string $section): bool
	{
		return in_array($section, self::REPORTS, true);
	}

	public static function hasCards(string $section): bool
	{
		return in_array($section, self::CARDS, true);
	}

	/** Код раздела из запроса; чужое и пустое — раздел по умолчанию. */
	public static function resolve(mixed $value): string
	{
		return is_string($value) && isset(static::getList()[$value]) ? $value : self::DEFAULT;
	}

	/**
	 * Адрес раздела. Всегда с «?section=», поэтому к нему можно дописывать
	 * «&ключ=значение».
	 *
	 * @param array<string, scalar> $params
	 */
	public static function url(string $section, array $params = [], bool $iframe = false): string
	{
		$query = ['section' => static::resolve($section)] + $params + ($iframe ? self::IFRAME_PARAMS : []);

		return Constants::PUBLIC_URL.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
	}

	/**
	 * Адрес раздела настроек shef.toolsai (подключение модели, расход): страница
	 * другого модуля, поэтому коды разделов не проверяем.
	 */
	public static function coreUrl(string $coreSection, bool $iframe = false): string
	{
		return self::CORE_URL.'?'.http_build_query(['section' => $coreSection] + ($iframe ? self::IFRAME_PARAMS : []), '', '&', PHP_QUERY_RFC3986);
	}

	/**
	 * Скрытые поля GET-формы раздела (выбор периода, промпта): без них
	 * отправка формы теряла бы раздел и слайдер.
	 *
	 * @return array<string, string>
	 */
	public static function hiddenFields(string $section, bool $iframe = false): array
	{
		return ['section' => static::resolve($section)] + ($iframe ? self::IFRAME_PARAMS : []);
	}
}
