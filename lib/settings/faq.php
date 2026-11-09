<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Settings;

/**
 * Вопросы и ответы по настройкам и отчётам.
 *
 * Тексты — отдельным файлом settings/faqdata.php (обычный массив PHP):
 * правятся без кода. Ключ ответа — то, к чему он относится:
 *
 *   section.<раздел>     раздел целиком (Sections)
 *   option.<ВКЛАДКА_код> настройка страницы «Общие» (как её хранит Option)
 *   profile.<поле>       поле профиля анализа
 *   <раздел>.<что>       блок раздела: кнопка, таблица, поле промпта
 *
 * Рядом с настройкой — значок «?»: всплывающая подсказка «что это» и
 * ссылка на полный ответ в статье «Вопросы и ответы» «Базы знаний 2.0»
 * (shef.toolsaikb). Ответ «что это и зачем» живёт в одном месте, рядом с
 * полем — только ссылка.
 *
 * Класс без ядра: что у каждой настройки есть ответ, держит faq_test.php.
 */
final class Faq
{
	/** @var array<string, array{section: string, title: string, what: string, why: string}>|null */
	private static ?array $data = null;

	/**
	 * @return array<string, array{section: string, title: string, what: string, why: string}>
	 */
	public static function getAll(): array
	{
		if(self::$data === null)
		{
			$data = require dirname(__DIR__, 2).'/settings/faqdata.php';
			self::$data = is_array($data) ? $data : [];
		}

		return self::$data;
	}

	public static function has(string $key): bool
	{
		return isset(static::getAll()[static::resolveKey($key)]);
	}

	/** Ключ ответа как есть: общего для нескольких полей ответа в модуле нет. */
	public static function resolveKey(string $key): string
	{
		return $key;
	}

	/**
	 * @return array{section: string, title: string, what: string, why: string}|null
	 */
	public static function get(string $key): ?array
	{
		return static::getAll()[static::resolveKey($key)] ?? null;
	}

	/**
	 * Ответы раздела FAQ, сгруппированные по разделам настроек в порядке меню.
	 *
	 * @return array<string, array<string, array{section: string, title: string, what: string, why: string}>>
	 */
	public static function bySection(): array
	{
		$result = array_fill_keys(array_keys(Sections::getList()), []);
		foreach(static::getAll() as $key => $item)
		{
			$result[$item['section']][$key] = $item;
		}

		return array_filter($result);
	}

	/** Якорь ответа (ключ без опасных символов): для своих разметок и тестов. */
	public static function anchor(string $key): string
	{
		return 'faq-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $key);
	}

	/**
	 * Заголовки вопросов в статье «Вопросы и ответы» (faq.md пакета справки,
	 * tools/kb-build.php): поле heading или название ответа; повторяющееся
	 * название — «О разделе «…»» у ответа о разделе, у остальных — с разделом в
	 * скобках. «База знаний 2.0» строит якорь из текста заголовка,
	 * повтор получил бы суффикс «-2», поэтому заголовки уникальны. Группы
	 * разделов — «Раздел «…»» (sectionHeading()), с вопросами не совпадают.
	 *
	 * @return array<string, string> ключ ответа => заголовок
	 */
	public static function headings(): array
	{
		$all = static::getAll();
		$base = array_map(static fn(array $item): string => (string)($item['heading'] ?? $item['title']), $all);
		$count = array_count_values($base);
		$sections = Sections::getList();
		$result = [];
		foreach($all as $key => $item)
		{
			$title = $base[$key];
			$result[$key] = match(true) {
				$count[$title] === 1 => $title,
				str_starts_with($key, 'section.') => 'О разделе «'.$title.'»',
				default => $title.' ('.($sections[$item['section']]['title'] ?? $item['section']).')',
			};
		}

		return $result;
	}

	public static function heading(string $key): ?string
	{
		return static::headings()[static::resolveKey($key)] ?? null;
	}

	/** Заголовок группы раздела в faq.md. */
	public static function sectionHeading(string $section): string
	{
		return 'Раздел «'.(Sections::getList()[$section]['title'] ?? $section).'»';
	}

	/**
	 * Поле doc ответа ('/guide/slug#якорь') — статья пакета справки и якорь:
	 * ['slug.md', 'якорь' | null]. Нет поля или кривое — null.
	 *
	 * @return array{0: string, 1: ?string}|null
	 */
	public static function docTarget(string $doc): ?array
	{
		if(!preg_match('~^/guide/([a-z0-9-]+)(?:#([\p{Ll}0-9_-]+))?$~u', $doc, $m))
		{
			return null;
		}

		return [$m[1].'.md', ($m[2] ?? '') !== '' ? $m[2] : null];
	}

	/**
	 * Адрес статьи справки: документ «Базы знаний 2.0» из пакета
	 * shef.toolsaicrm:guide, $doc — '/guide/slug#якорь'. Адрес даёт
	 * shef.toolsaikb (KbLink). Кривой адрес, нет модуля или документа — null:
	 * лучше без ссылки, чем ссылка в никуда.
	 */
	public static function kbUrl(string $doc): ?string
	{
		$target = static::docTarget($doc);

		return $target === null ? null : KbLink::url($target[0], $target[1]);
	}

	/**
	 * Куда ведёт «?»: вопрос в статье «Вопросы и ответы» (один документ, якорь —
	 * заголовок вопроса). Нет ответа, модуля или документа — null.
	 */
	public static function kbAnswer(string $key): ?string
	{
		$heading = static::heading($key);

		return $heading === null ? null : KbLink::url(KbLink::FAQ, $heading);
	}

	/**
	 * Ссылка на статью справки: открывает узкий слайдер, что и «?» (data-kb).
	 * $doc — '/guide/slug#якорь'; адреса нет — просто текст.
	 */
	public static function kbLink(string $doc, string $text): string
	{
		$e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$url = static::kbUrl($doc);
		if($url === null)
		{
			return $e($text);
		}

		return '<a href="'.$e($url).'" target="_blank" data-kb="'.$e($url).'">'.$e($text).'</a>';
	}

	/**
	 * Значок «?» рядом с настройкой: подсказка «что это» при наведении,
	 * щелчок — узкий слайдер с ответом в «Базе знаний». Нет ответа, модуля
	 * «[SH] AI база знаний» или статьи — пусто: лучше без значка, чем значок
	 * в никуда.
	 */
	public static function icon(string $key): string
	{
		$item = static::get($key);
		$url = $item !== null ? static::kbAnswer($key) : null;
		if($url === null)
		{
			return '';
		}

		$e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

		return '<a class="shef-tai-q" href="'.$e($url).'" target="_blank" data-kb="'.$e($url).'"'
			.' title="'.$e($item['title'].': '.$item['what']).'"'
			.' aria-label="'.$e('Что это: '.$item['title']).'">?</a>';
	}
}
