<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Analysis\Prompt;

use Shef\ToolsAiCrm\Deal\HealthAnalyzer;
use Shef\ToolsAiCrm\Deal\Profile;

/**
 * Собрать сообщения модели из шаблона (1.9.0). Чистая функция — её же зовёт
 * предпросмотр на странице промптов (без отправки в модель).
 *
 * Системная часть:
 *   - у профиля свой промпт — он (как до 1.9.0) + требование JSON;
 *   - иначе шаблон типа; свой (не стандартный) шаблон получает требование
 *     JSON модуля в конце — правка текста не ломает разбор;
 *   - есть свои поля ответа — строка о них.
 * Пользовательская часть — шаблон с подстановками.
 *
 * Сделка со стандартным шаблоном без истории даёт ровно то, что уходило
 * до 1.9.0: getSystemPrompt() и сводку.
 */
final class PromptBuilder
{
	/**
	 * @return array{system: string, user: string, schema: array}
	 */
	public static function build(PromptTemplate $template, ?Profile $profile, string $summary, string $history, string $entityNoun, string $timeline = ''): array
	{
		$vars = [
			PromptTemplate::VAR_SUMMARY => $summary,
			PromptTemplate::VAR_HISTORY => $history,
			PromptTemplate::VAR_SCALE => $profile !== null
				? sprintf('риск от %d%% — низкий (дело ответственному), от %d%% — высокий (старший)', $profile->lowBorder, $profile->highBorder)
				: '',
			PromptTemplate::VAR_PROFILE => $profile?->title ?? '',
			PromptTemplate::VAR_ENTITY => $entityNoun,
			PromptTemplate::VAR_TIMELINE => $timeline,
		];

		$own = $profile !== null ? trim($profile->prompt) : '';
		if($own !== '')
		{
			$system = PromptTemplate::render($own, $vars)."\n\n".HealthAnalyzer::JSON_RULE;
		}
		else
		{
			$system = PromptTemplate::render($template->system, $vars);
			if(!$template->isDefault)
			{
				$system .= "\n\n".HealthAnalyzer::JSON_RULE;
			}
		}
		if($template->extraFields !== [])
		{
			$system .= "\n".'Дополнительно в том же JSON — строковые поля: '.implode(', ', $template->extraFields).'.';
		}

		$user = PromptTemplate::render($template->user, $vars);
		// Шаблон без {сводка} — модель осталась бы без данных: сводку в конец.
		if(!str_contains($template->user, PromptTemplate::VAR_SUMMARY))
		{
			$user = trim($user."\n\n".$summary);
		}

		return ['system' => $system, 'user' => $user, 'schema' => HealthAnalyzer::schema($template->extraFields)];
	}
}
