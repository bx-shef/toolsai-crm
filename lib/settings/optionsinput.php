<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Settings;

use Shef\ToolsAiCrm\Config;

/**
 * Проверка значений страницы «Общие» перед записью: число в допустимых границах
 * или пусто (тогда действует значение по умолчанию). Без проверки «500000» молча
 * превратилось бы в значение по умолчанию при чтении (OptionParser::int), а
 * администратор думал бы, что лимит принят.
 *
 * Класс без ядра — optionsinput_test.php.
 */
final class OptionsInput
{
	/** Код настройки => [минимум, максимум, подпись для сообщения]. */
	public const LIMITS = [
		'DEAL_maxperrun' => [1, 500, 'Анализов за прогон'],
		'DEAL_interval' => [Config::AGENT_INTERVAL_MIN, Config::AGENT_INTERVAL_MAX, 'Запускать агент раз в, минут'],
	];

	/**
	 * @param array<string, string> $values код настройки => значение из формы
	 * @return array<string, string> код настройки => что не так; пусто — всё принимается
	 */
	public static function validate(array $values): array
	{
		$errors = [];
		foreach(self::LIMITS as $code => [$min, $max, $title])
		{
			if(!array_key_exists($code, $values))
			{
				continue;
			}
			$value = trim((string)$values[$code]);
			if($value === '')
			{
				continue;
			}
			if(1 !== preg_match('/^\d{1,9}$/', $value) || (int)$value < $min || (int)$value > $max)
			{
				$errors[$code] = $title.': целое число от '.$min.' до '.$max;
			}
		}

		return $errors;
	}
}
