<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Settings;

/**
 * Права на разделы страницы модуля.
 *
 * Своих ролей у модуля нет: права выдаются на странице прав shef.toolsai, роли
 * руководителей (РОП) сохраняются. Администратор портала — всё. Остальным:
 *   - «Промпты анализа» — право «Промпты: читать» (prompts.read), править — prompts.edit;
 *   - «Анализ записей CRM», «Оценка работы менеджеров» — «Статистика по всему
 *     порталу» (reports.all): чужие оценки видят только руководители;
 *   - «Общие» и «Авто-анализ CRM» — только администратор.
 *
 * Проверка права — функция $can(код действия): боевая обращается к
 * Shef\ToolsAi\Public\Service\AccessService, тесту подставляют свою.
 */
final class SectionAccess
{
	public const PROMPTS_READ = 'prompts.read';
	public const PROMPTS_EDIT = 'prompts.edit';
	public const REPORTS_ALL = 'reports.all';
	public const REPORTS_VIEW = 'reports.view';

	/** Раздел => действие, которое его открывает. Нет в списке — только администратор. */
	public const SECTIONS = [
		Sections::PROMPTS => self::PROMPTS_READ,
		Sections::STATS_CHECKS => self::REPORTS_ALL,
		Sections::STATS_MANAGERS => self::REPORTS_ALL,
	];

	/**
	 * @param \Closure(string): bool $can есть ли у пользователя действие shef.toolsai
	 */
	public function __construct(
		public readonly bool $isAdmin,
		private readonly \Closure $can,
	)
	{
	}

	/** Права текущего пользователя портала через публичный API shef.toolsai. */
	public static function current(): self
	{
		global $USER;
		$isAdmin = $USER instanceof \CUser && $USER->IsAdmin();
		$service = new \Shef\ToolsAi\Public\Service\AccessService();

		return new self($isAdmin, static fn(string $action): bool => $service->can($action));
	}

	public function can(string $action): bool
	{
		return $this->isAdmin || ($this->can)($action);
	}

	public function canEditPrompts(): bool
	{
		return $this->can(self::PROMPTS_EDIT);
	}

	public function canSection(string $section): bool
	{
		if($this->isAdmin)
		{
			return isset(Sections::getList()[$section]);
		}

		return isset(self::SECTIONS[$section]) && ($this->can)(self::SECTIONS[$section]);
	}

	/**
	 * @return string[] коды доступных разделов в порядке меню
	 */
	public function getSections(): array
	{
		return array_values(array_filter(array_keys(Sections::getList()), fn(string $code): bool => $this->canSection($code)));
	}

	/** Хоть один раздел открыт: иначе страница — «Нет доступа». */
	public function hasAny(): bool
	{
		return $this->getSections() !== [];
	}

	/** Раздел по умолчанию: общий, а если он закрыт — первый доступный; нет ни одного — null. */
	public function getDefaultSection(): ?string
	{
		if($this->canSection(Sections::DEFAULT))
		{
			return Sections::DEFAULT;
		}

		return $this->getSections()[0] ?? null;
	}
}
