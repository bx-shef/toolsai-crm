<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Settings;

/**
 * Адрес статьи справки в «Базе знаний 2.0».
 *
 * Справку показывают штатные страницы Битрикс24 (модуль ядра note), статьи
 * кладёт модуль shef.toolsaikb («[SH] AI база знаний») из пакета
 * shef.toolsaicrm:guide (каталог kb/guide этого модуля). Адрес документа с якорем
 * заголовка — его публичный метод
 * Shef\ToolsAi\Public\Service\KbLinkService::url() (shef.toolsaikb 1.8.0):
 * '/note/document/<id>/#якорь'.
 *
 * Читатель (1.76.1, toolsai-kb#38): в сервис уходит текущий сотрудник —
 * shef.toolsaikb 1.11.0 не даёт адрес статьи, которую он прочитать не может,
 * и «Подробнее» не рисуется. Сотрудника нет (агент, консоль) — права не проверяются.
 * Старые shef.toolsaikb лишний аргумент не читают.
 *
 * Вызов мягкий: нет модуля, класса, документа или сбой — null: «Подробнее» нет,
 * а «?» остаётся и объясняет на месте. Ответы кешируются на запрос: на странице
 * настроек «?» — десятки.
 */
final class KbLink
{
	public const PACKAGE = 'shef.toolsaicrm:guide';
	/** Какой адрес сервиса берём: документ note и якорь без знаков; D — `$` не пропускает перевод строки в конце. */
	public const URL_RE = '~^/note/document/\d+/(#[A-Za-z0-9_-]*)?$~D';
	public const MODULE = 'shef.toolsaikb';
	public const SERVICE = 'Shef\\ToolsAiKb\\Public\\Service\\KbLinkService';
	/** Статья «Вопросы и ответы» — один документ на все «?». */
	public const FAQ = 'faq.md';
	/** Оглавление справки. */
	public const INDEX = 'index.md';
	/** Строка в настройках, когда справки нет. */
	public const INSTALL_HINT = 'Подробные статьи — в модуле «[SH] AI база знаний», установите его';

	/** @var (\Closure(string, ?string): ?string)|null подмена теста */
	private static ?\Closure $resolver = null;
	/** @var array<string, ?string> */
	private static array $cache = [];

	/** Тестам: свой ответ вместо shef.toolsaikb (null — снова настоящий). */
	public static function setResolver(?\Closure $resolver): void
	{
		self::$resolver = $resolver;
		self::$cache = [];
	}

	public static function url(string $path, ?string $heading = null): ?string
	{
		$reader = static::readerId();
		$key = $path."\0".($heading ?? '')."\0".(string)$reader;
		if(!array_key_exists($key, self::$cache))
		{
			self::$cache[$key] = static::resolve($path, $heading, $reader);
		}

		return self::$cache[$key];
	}

	/** Справка открывается: документ оглавления есть. */
	public static function isAvailable(): bool
	{
		return static::url(self::INDEX) !== null;
	}

	/** Текущий сотрудник; нет или не прочитать — null (права не проверять). */
	public static function readerId(): ?int
	{
		try
		{
			$id = class_exists(\Bitrix\Main\Engine\CurrentUser::class) ? (int)\Bitrix\Main\Engine\CurrentUser::get()->getId() : 0;
		}
		catch(\Throwable)
		{
			return null;
		}

		return $id > 0 ? $id : null;
	}

	private static function resolve(string $path, ?string $heading, ?int $reader): ?string
	{
		try
		{
			if(self::$resolver !== null)
			{
				$url = (self::$resolver)($path, $heading);
			}
			else
			{
				if(!class_exists(\Bitrix\Main\Loader::class) || !\Bitrix\Main\Loader::includeModule(self::MODULE) || !class_exists(self::SERVICE))
				{
					return null;
				}
				$service = self::SERVICE;
				$url = $service::url(self::PACKAGE, $path, $heading, $reader);
			}
		}
		catch(\Throwable)
		{
			return null;
		}

		// Только документ «Базы знаний» своего портала; якорь note (Note\Anchor::of()) — [a-z0-9-]
		return is_string($url) && preg_match(self::URL_RE, $url) ? $url : null;
	}
}
