<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Internals\Integration\ToolsAi\EventHandler;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Shef\ToolsAiCrm\Integration\Links;
use Shef\ToolsAiCrm\Main\Constants;

/**
 * Обработчик shef.toolsai:OnRocketCardsCollect — карточки панели «ракеты»
 * (shef.toolsai 1.79.0+). Плитки страницы «AI-инструменты» shef.toolsai
 * делает из тех же карточек: своего события для плиток у страницы нет.
 *
 * Контракт: параметр userId (int), ответ EventResult SUCCESS с ['cards' => [...]]
 * от имени модуля. Права проверяет обработчик сам, по userId, через публичный
 * AccessService shef.toolsai (роли доступа; администратор может всё):
 *
 * * «Авто-анализ CRM» — профили анализа, только администратор (отдельного
 *   действия в ролях нет);
 * * «Промпты анализа» — действие prompts.read;
 * * «Оценка работы менеджеров» — действие reports.all (статистика по всему порталу).
 *
 * Без права карточки нет; обычному сотруднику — ничего. Сбой — пустой список.
 * Чистая часть — cards(), tests/rocketcards_test.php.
 */
final class OnRocketCardsCollectEventHandler
{
	public const CODE_PROFILES = 'analysis_profiles';
	public const CODE_PROMPTS = 'analysis_prompts';
	public const CODE_MANAGERS = 'manager_scores';

	/** Условное «право»: администратор портала. */
	public const ADMIN = 'admin';
	public const ACTION_PROMPTS = 'prompts.read';
	public const ACTION_REPORTS = 'reports.all';

	public static function handle(Event $event): EventResult
	{
		try
		{
			$userId = (int)($event->getParameter('userId') ?? 0);
			$cards = $userId > 0 ? static::cards(static::can($userId)) : [];
		}
		catch(\Throwable)
		{
			$cards = [];
		}

		return new EventResult(EventResult::SUCCESS, ['cards' => $cards], Constants::MODULE_ID);
	}

	/**
	 * Проверка права сотрудника: ADMIN — администратор портала, иначе код действия
	 * ролей shef.toolsai (AccessService::can).
	 *
	 * @return \Closure(string): bool
	 */
	public static function can(int $userId): \Closure
	{
		$isAdmin = in_array(1, array_map('intval', (array)\CUser::GetUserGroup($userId)), true);
		$service = new \Shef\ToolsAi\Public\Service\AccessService();

		return static fn(string $action): bool => $action === self::ADMIN ? $isAdmin : $service->can($action, $userId);
	}

	/**
	 * Карточки по правам. Чистая часть.
	 *
	 * @param callable(string): bool $can ADMIN или код действия ролей
	 * @return list<array<string, mixed>>
	 */
	public static function cards(callable $can): array
	{
		$cards = [];
		if($can(self::ADMIN))
		{
			$cards[] = static::card(self::CODE_PROFILES, 'settings', 'Авто-анализ CRM', 'Профили проверки сделок, лидов и смарт-процессов: когда звать старшего.', Links::section(Links::SECTION_PROFILES), 'settings', 200);
		}
		if($can(self::ACTION_PROMPTS))
		{
			$cards[] = static::card(self::CODE_PROMPTS, 'settings', 'Промпты анализа', 'Задания AI для анализа записей CRM, писем и чатов.', Links::section(Links::SECTION_PROMPTS), 'ai', 210);
		}
		if($can(self::ACTION_REPORTS))
		{
			$cards[] = static::card(self::CODE_MANAGERS, 'use', 'Оценка работы менеджеров', 'Рейтинг по анализу записей CRM: звонки, письма и чаты.', Links::section(Links::SECTION_MANAGERS), 'quota', 310);
		}

		return $cards;
	}

	private static function card(string $code, string $kind, string $title, string $text, string $url, string $icon, int $sort): array
	{
		return [
			'code' => $code,
			'kind' => $kind,
			'title' => $title,
			'text' => $text,
			'button' => 'Открыть',
			'url' => $url,
			'alert' => false,
			'icon' => $icon,
			'sort' => $sort,
		];
	}
}
