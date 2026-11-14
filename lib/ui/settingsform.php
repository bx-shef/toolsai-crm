<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Ui;

/**
 * Форма настроек «как у Битрикс24» (1.20.0, #96): описание полей для
 * JS-страницы. С 1.64.0 вид — как «Настройки BitrixGPT» CRM
 * (crm/install/js/crm/ai/settings-slider): карточка на вкладку, строки
 * «подпись — поле» на штатных ui-ctl и ui.switcher.
 *
 * Слой не знает про toolsai: на вход — вкладки shef.options (Options\Tab с
 * Checkbox, Text, NumberInt, TextArea, Enum, RowInfo), значения и подсказки
 * — функциями. Его же потом переносим в shef.options (#96).
 *
 * Имена полей — как у shef.options (param_<ВКЛАДКА>_<код>): сохранение
 * остаётся прежним циклом страницы, меняется только вид.
 *
 * Без ядра — тестируется uiform_test.php.
 */
final class SettingsForm
{
	public const TYPE_CHECKER = 'checker';
	public const TYPE_TEXT = 'text';
	public const TYPE_NUMBER = 'number';
	public const TYPE_TEXTAREA = 'textarea';
	public const TYPE_SELECTOR = 'selector';
	public const TYPE_INFO = 'info';
	public const TYPE_SECRET = 'secret';

	/** Ввести в поле ключа, чтобы удалить сохранённый (пустое поле ключ не меняет). */
	public const SECRET_CLEAR = '-';

	/**
	 * @param iterable<object> $tabs вкладки shef.options (getCode, getName, getTitle, getOptionList)
	 * @param callable(string): string $value значение настройки по полному коду (ВКЛАДКА_код)
	 * @param callable(string): string $hint подсказка «что это» по полному коду; пусто — нет
	 * @param callable(string): string|null $hintIcon значок «?» перед подсказкой — готовый HTML
	 *        кнопки подсказки на месте (Settings\Faq::icon()); пусто — значка нет
	 * @return list<array{id: string, title: string, description: string, fields: list<array<string, mixed>>}>
	 */
	public static function fromTabs(iterable $tabs, callable $value, ?callable $hint = null, ?callable $hintIcon = null): array
	{
		$hint ??= static fn(string $code): string => '';
		$hintIcon ??= static fn(string $code): string => '';
		$sections = [];
		foreach($tabs as $tab)
		{
			if(!is_object($tab) || !method_exists($tab, 'getOptionList'))
			{
				continue;
			}
			$fields = [];
			foreach($tab->getOptionList() as $option)
			{
				$field = static::field($tab->getCode(), $option, $value, $hint, $hintIcon);
				if($field !== null)
				{
					$fields[] = $field;
				}
			}
			$sections[] = [
				'id' => (string)$tab->getCode(),
				'title' => (string)$tab->getName(),
				'description' => (string)$tab->getTitle(),
				'fields' => $fields,
			];
		}

		return $sections;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function field(string $tabCode, object $option, callable $value, callable $hint, ?callable $hintIcon = null): ?array
	{
		$code = $tabCode.'_'.$option->getCode();
		$class = (new \ReflectionClass($option))->getShortName();
		$type = match($class)
		{
			'Checkbox' => self::TYPE_CHECKER,
			'NumberInt', 'NumberFloat' => self::TYPE_NUMBER,
			'TextArea' => self::TYPE_TEXTAREA,
			'RowInfo' => self::TYPE_INFO,
			'Text' => self::TYPE_TEXT,
			default => method_exists($option, 'getList') ? self::TYPE_SELECTOR : null,
		};
		if($type === null)
		{
			return null;
		}

		$description = (string)$option->getDescription();
		if($type === self::TYPE_INFO)
		{
			return ['type' => $type, 'name' => '', 'label' => '', 'html' => static::safeHtml($description)];
		}

		$current = $value($code);
		$field = [
			'type' => $type,
			'name' => 'param_'.$code,
			'label' => (string)$option->getTitle(),
			'description' => static::safeHtml($description),
			'hint' => $hint($code),
			'hintIcon' => $hintIcon !== null ? $hintIcon($code) : '',
			'value' => $current,
		];

		if($type === self::TYPE_CHECKER)
		{
			$yes = method_exists($option, 'getValueY') ? (string)$option->getValueY() : 'Y';
			$field['checked'] = $current === $yes;
		}
		if($type === self::TYPE_SELECTOR)
		{
			$items = [];
			foreach((array)$option->getList() as $key => $title)
			{
				$items[] = ['value' => (string)$key, 'name' => (string)$title, 'selected' => (string)$key === $current];
			}
			$field['items'] = $items;
		}

		return $field;
	}

	/**
	 * Серый текст в пустых полях (#294): действующее значение направления,
	 * когда своё не задано. Только текстовым и числовым полям.
	 *
	 * @param list<array{fields: list<array<string, mixed>>}> $sections результат fromTabs()
	 * @param array<string, string> $placeholders полный код настройки => текст
	 * @return list<array{fields: list<array<string, mixed>>}>
	 */
	public static function withPlaceholders(array $sections, array $placeholders): array
	{
		foreach($sections as &$section)
		{
			foreach($section['fields'] as &$field)
			{
				$code = substr((string)($field['name'] ?? ''), strlen('param_'));
				if(in_array($field['type'] ?? '', [self::TYPE_TEXT, self::TYPE_NUMBER], true) && ($placeholders[$code] ?? '') !== '')
				{
					$field['placeholder'] = $placeholders[$code];
				}
			}
			unset($field);
		}
		unset($section);

		return $sections;
	}

	/**
	 * Ключи API — полем-паролем (1.64.5, #283): значение в браузер не уходит
	 * совсем (ни в value, ни в JSON страницы). Под полем — явная строка
	 * состояния (#527): «Сохранён: ••••XXXX», «Берётся из: Текст (••••XXXX)»
	 * или «Не задан» — одинаково у всех ключей. Сохранение — keepSecrets().
	 *
	 * @param list<array{fields: list<array<string, mixed>>}> $sections результат fromTabs()
	 * @param list<string> $codes полные коды настроек-ключей
	 * @param array<string, string> $sources полный код => откуда берётся ключ, когда свой пуст
	 *        («Текст (••••e166)», Config::getSecretSources()); нет — «Не задан»
	 * @return list<array{fields: list<array<string, mixed>>}>
	 */
	public static function withSecrets(array $sections, array $codes, array $sources = []): array
	{
		foreach($sections as &$section)
		{
			foreach($section['fields'] as &$field)
			{
				$code = substr((string)($field['name'] ?? ''), strlen('param_'));
				if(!in_array($code, $codes, true) || ($field['type'] ?? '') !== self::TYPE_TEXT)
				{
					continue;
				}
				$stored = trim((string)($field['value'] ?? ''));
				$field['type'] = self::TYPE_SECRET;
				$field['value'] = '';
				$field['secretState'] = static::secretState($stored, (string)($sources[$code] ?? ''));
				// Ни своего, ни унаследованного — строка серым (JS не сравнивает текст)
				$field['secretEmpty'] = $stored === '' && (string)($sources[$code] ?? '') === '';
				$field['placeholder'] = $stored !== ''
					? 'Новый ключ; пусто — не менять, «'.self::SECRET_CLEAR.'» — удалить'
					: 'Вставьте ключ';
			}
			unset($field);
		}
		unset($section);

		return $sections;
	}

	/** Строка состояния ключа под полем (#527): сохранён, берётся из соседа или не задан. */
	public static function secretState(string $stored, string $source = ''): string
	{
		if(trim($stored) !== '')
		{
			return 'Сохранён: '.static::mask($stored);
		}

		return $source !== '' ? 'Берётся из: '.$source : 'Не задан';
	}

	/** Маска ключа: последние 4 знака (короткий ключ — без знаков). */
	public static function mask(string $secret): string
	{
		$secret = trim($secret);

		return mb_strlen($secret) > 8 ? '••••'.mb_substr($secret, -4) : '••••';
	}

	/**
	 * Ключи при сохранении (1.64.5): пустое поле — ключ не трогаем (его в форме
	 * не было), «-» — удалить. Остальные настройки — как пришли.
	 *
	 * @param array<string, string> $values результат collect()
	 * @param list<string> $codes полные коды настроек-ключей
	 * @return array<string, string>
	 */
	public static function keepSecrets(array $values, array $codes): array
	{
		foreach($codes as $code)
		{
			if(!array_key_exists($code, $values))
			{
				continue;
			}
			if($values[$code] === '')
			{
				unset($values[$code]);
			}
			elseif($values[$code] === self::SECRET_CLEAR)
			{
				$values[$code] = '';
			}
		}

		return $values;
	}

	/**
	 * Описания настроек — из языковых файлов модуля, в них бывают ссылки и
	 * <br>. Пропускаем только их; остальное экранируется.
	 */
	public static function safeHtml(string $html): string
	{
		$text = htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		// <br>, <b>, <i>, <code> — как были
		$text = preg_replace('~&lt;(/?)(br|b|i|code)\s*/?&gt;~i', '<$1$2>', $text) ?? $text;
		// <a href="https://…" или "/…"> — адрес разбираем заново и экранируем один раз.
		// Прочие атрибуты отбрасываем: RowInfo::getDescription() shef.options
		// прогоняет текст через парсер, и тот сам делает из адреса
		// <a href="…" target="_blank" …> — раньше такая ссылка выходила сырым текстом.
		$text = preg_replace_callback('~&lt;a\s((?:(?!&gt;).)*?)&gt;~is', static function(array $m): string
		{
			if(!preg_match('~(?:^|\s)href=&quot;(.*?)&quot;~i', $m[1], $found))
			{
				return $m[0];
			}
			$href = html_entity_decode(html_entity_decode($found[1], ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8');
			// «//host» и «/\host» браузер читает как чужой сайт
			if(!preg_match('~^(?:https?://|/(?![/\\\\]))[^\s"<>\\\\]*$~', $href))
			{
				return $m[0];
			}

			return '<a href="'.htmlspecialchars($href, ENT_QUOTES, 'UTF-8').'" target="_top">';
		}, $text) ?? $text;

		return preg_replace('~&lt;/a&gt;~i', '</a>', $text) ?? $text;
	}

	/** Скрытое поле, которое ставит JS после отрисовки формы. */
	public const RENDERED = 'shef_ui_rendered';

	/**
	 * Что сохранить из POST: только настройки, чьи поля пришли. Поля рисует
	 * JS — если он не отработал, в POST их нет, и пустая форма не должна
	 * затирать настройки (адреса и ключи API). Переключатель (ui.switcher)
	 * всегда шлёт Y или N — переводим в «да/нет» самой настройки.
	 *
	 * @param iterable<object> $tabs вкладки shef.options
	 * @param array<string, mixed> $post
	 * @return array<string, string> полный код => значение
	 */
	public static function collect(iterable $tabs, array $post): array
	{
		if(($post[self::RENDERED] ?? '') !== 'Y')
		{
			return [];
		}
		$result = [];
		foreach($tabs as $tab)
		{
			if(!is_object($tab) || !method_exists($tab, 'getOptionList'))
			{
				continue;
			}
			foreach($tab->getOptionList() as $option)
			{
				$code = $tab->getCode().'_'.$option->getCode();
				$type = static::field($tab->getCode(), $option, static fn(): string => '', static fn(): string => '')['type'] ?? null;
				if($type === null || $type === self::TYPE_INFO || !array_key_exists('param_'.$code, $post))
				{
					continue;
				}
				$value = $post['param_'.$code];
				if($type === self::TYPE_CHECKER)
				{
					$yes = method_exists($option, 'getValueY') ? (string)$option->getValueY() : 'Y';
					$no = method_exists($option, 'getValueN') ? (string)$option->getValueN() : 'N';
					$value = (string)$value === 'Y' || (string)$value === $yes ? $yes : $no;
				}
				$result[$code] = is_array($value) ? serialize($value) : trim((string)$value);
			}
		}

		return $result;
	}

	/** JS, который рисует форму: lib/ui/settingsform.js целиком. */
	public static function script(): string
	{
		return (string)file_get_contents(__DIR__.'/settingsform.js');
	}

	/**
	 * Иконка и «Как это работает?» у карточек (1.64.0): код вкладки => [класс
	 * иконки ui.icon-set.outline, адрес справки или null]. Вкладки без записи —
	 * шестерёнка и без кнопки.
	 *
	 * @param list<array<string, mixed>> $sections результат fromTabs()
	 * @param array<string, array{0: string, 1: ?string}> $meta
	 * @return list<array<string, mixed>>
	 */
	public static function withMeta(array $sections, array $meta): array
	{
		foreach($sections as &$section)
		{
			[$icon, $help] = $meta[$section['id']] ?? ['--o-settings', null];
			$section['icon'] = preg_match('~^--o-[a-z0-9-]+$~', $icon) ? $icon : '--o-settings';
			$section['help'] = $help;
		}
		unset($section);

		return $sections;
	}

	/** Штатные расширения, нужные форме (Extension::load). */
	public static function extensions(): array
	{
		return ['ui.switcher', 'ui.forms', 'ui.alerts', 'ui.buttons', 'ui.icon-set.outline'];
	}

	/** Стили карточек — как у crm.ai.settings-slider (1.64.0). */
	public static function style(): string
	{
		return (string)file_get_contents(__DIR__.'/settingsform.css');
	}
}
