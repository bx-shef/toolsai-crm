<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Deal;

use Bitrix\Crm\ItemIdentifier;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Shef\ToolsAiCrm\Analysis\EntityType;

/**
 * Шаги по решению шкалы профиля (RiskScale::decide()).
 *
 * По возрастанию навязчивости:
 *   1) дело «Сделать» (To-Do) ответственному — у сделки высокий риск и нет
 *      ни одного запланированного дела;
 *   2) комментарий в таймлайне сделки — видно при открытии карточки;
 *   3) дело старшему профиля с дедлайном — остаётся в списке, пока его не
 *      закроют. Старший не задан — только комментарий, и отчёт говорит почему.
 *
 * Уведомления в чат сознательно нет: их быстро перестают читать, а дело
 * в списке остаётся. Смена ответственного — слишком грубо для автомата.
 *
 * С 1.9.0 — общий шаг блока анализа: тип сущности ($type) задаёт, к чему
 * привязать дело и комментарий (ItemIdentifier, BINDINGS) и как назвать
 * сущность в тексте. Без $type — сделка, тексты прежние.
 */
final class Escalation
{
	/** Дедлайн дела, часов. */
	private const DEADLINE_HOURS = 24;

	/** К какой сущности привязывать дела и комментарии в текущем apply(). */
	private int $entityTypeId = EntityType::DEAL;

	/**
	 * Сбои последнего apply(): что не поставилось и почему (#41). Раньше
	 * сбой addTodo()/addComment() молча превращался в «ничего не сделано».
	 *
	 * @var list<string>
	 */
	public array $errors = [];

	/**
	 * @param DealFacts|null $facts нужны для дела старшему о просрочке
	 * @return string[] что сделано: manager_todo, overdue_todo, comment, todo, note
	 */
	public function apply(int $dealId, Verdict $verdict, Profile $profile, ScaleDecision $decision, int $managerId, ?DealFacts $facts = null, ?EntityType $type = null): array
	{
		if(($decision->isEmpty() && !$decision->seniorMissing) || !Loader::includeModule('crm'))
		{
			return [];
		}
		$this->errors = [];
		$this->entityTypeId = ($type ?? EntityType::deal())->entityTypeId;
		$noun = ($type ?? EntityType::deal())->nounGenitive;

		$done = [];

		if($decision->managerTodo && $this->addTodo($dealId, $managerId, static::buildManagerText($verdict, $noun), 'дело менеджеру'))
		{
			$done[] = 'manager_todo';

			// Старшему — проконтролировать просрочку. Только если дело
			// менеджеру поставилось: без него указание повисает в воздухе.
			if($decision->seniorOverdueTodo && $facts !== null && $facts->overdueActivities > 0)
			{
				$overdueText = static::buildOverdueText(static::getUserName($managerId), $facts->overdueActivities, $facts->oldestOverdueAt, $verdict, $noun);
				if($this->addTodo($dealId, $profile->seniorId, $overdueText, 'дело старшему о просрочке'))
				{
					$done[] = 'overdue_todo';
				}
			}
		}

		$text = static::buildText($verdict, $decision->seniorAgainstModel, $noun);
		if($decision->comment && $this->addComment($dealId, $text, $profile->seniorId, 'комментарий'))
		{
			$done[] = 'comment';
		}

		// Старший не задан — дело не ставится, и отчёт прогона говорит почему,
		// а не молчит (приёмка, bx-shef/toolsai#3).
		if($decision->seniorMissing)
		{
			$done[] = 'старший не задан — дела нет';
		}
		elseif($decision->seniorTodo && $this->addTodo($dealId, $profile->seniorId, $text, 'дело старшему'))
		{
			$done[] = 'todo';
		}

		// Риск высокий, а старшего не зовут — объяснить в таймлайне, иначе
		// выглядит так, будто AI ничего не сделал (bx-shef/toolsai#41).
		if($decision->noSeniorComment && $this->addComment($dealId, static::buildNoSeniorText($verdict), $profile->seniorId, 'комментарий «старшего не привлекаем»'))
		{
			$done[] = 'note';
		}

		return $done;
	}

	/**
	 * @param bool $againstModel дело по «Тотальному контролю», модель сказала, что старший не нужен
	 */
	public static function buildText(Verdict $verdict, bool $againstModel = false, string $noun = 'сделки'): string
	{
		return static::withManager($verdict, sprintf(
			"AI-анализ %s: риск потери %d%%, %s.\nПочему: %s\nЧто сделать: %s",
			$noun,
			$verdict->risk,
			$againstModel
				? 'тотальный контроль (старший по мнению модели не нужен)'
				: 'нужен старший',
			$verdict->why !== '' ? $verdict->why : '—',
			$verdict->nextStep !== '' ? $verdict->nextStep : '—'
		));
	}

	/** Служебный комментарий: риск высокий, старшего не привлекаем. */
	public static function buildNoSeniorText(Verdict $verdict): string
	{
		return static::withManager($verdict, sprintf(
			"AI-анализ: риск %d%%. Старшего не привлекаем — по оценке модели вмешательство старшего исход не изменит.\nПочему: %s\nЧто сделать: %s",
			$verdict->risk,
			$verdict->why !== '' ? $verdict->why : '—',
			$verdict->nextStep !== '' ? $verdict->nextStep : '—'
		));
	}

	public static function buildManagerText(Verdict $verdict, string $noun = 'сделки'): string
	{
		return static::withManager($verdict, sprintf(
			"AI-анализ %s: нет запланированных дел, риск %d%%.\nЧто сделать: %s\nПочему: %s",
			$noun,
			$verdict->risk,
			$verdict->nextStep !== '' ? $verdict->nextStep : '—',
			$verdict->why !== '' ? $verdict->why : '—'
		));
	}

	/**
	 * Дело старшему о просрочке менеджера (1.4.0).
	 *
	 * @param int|null $oldestAt срок самого старого просроченного дела (unix)
	 */
	public static function buildOverdueText(string $managerName, int $count, ?int $oldestAt, Verdict $verdict, string $noun = 'сделки'): string
	{
		return sprintf(
			"У менеджера %s просрочены дела по %s: %d шт.%s Проконтролировать.\nAI-анализ: риск потери %d%%.\nПочему: %s",
			$managerName !== '' ? $managerName : '—',
			$noun === 'сделки' ? 'сделке' : ($noun === 'лида' ? 'лиду' : 'элементу'),
			$count,
			$oldestAt !== null ? ', самое старое с '.date('d.m.Y', $oldestAt).'.' : '',
			$verdict->risk,
			$verdict->why !== '' ? $verdict->why : '—'
		);
	}

	/** Строка оценки менеджера (1.67.0) в конце текста; модель не оценила — текст как был. */
	private static function withManager(Verdict $verdict, string $text): string
	{
		$line = $verdict->managerLine();

		return $line !== '' ? $text."\n".$line : $text;
	}

	/** Имя сотрудника для текста дела: «Фамилия Имя», нет — логин, нет — #ID. */
	private static function getUserName(int $userId): string
	{
		if($userId <= 0 || !class_exists(\Bitrix\Main\UserTable::class))
		{
			return '';
		}

		try
		{
			$row = \Bitrix\Main\UserTable::getList([
				'select' => ['LAST_NAME', 'NAME', 'LOGIN'],
				'filter' => ['=ID' => $userId],
				'limit' => 1,
			])->fetch();
		}
		catch(\Throwable)
		{
			$row = false;
		}
		if(!is_array($row))
		{
			return '#'.$userId;
		}
		$name = trim(($row['LAST_NAME'] ?? '').' '.($row['NAME'] ?? ''));

		return $name !== '' ? $name : (string)($row['LOGIN'] ?? '#'.$userId);
	}

	private function addComment(int $dealId, string $text, int $authorId, string $what = 'комментарий'): bool
	{
		if(!class_exists(\Bitrix\Crm\Timeline\CommentEntry::class))
		{
			$this->errors[] = ActionSummary::error($what, 'нет класса CommentEntry');

			return false;
		}

		$id = \Bitrix\Crm\Timeline\CommentEntry::create([
			'TEXT' => $text,
			'AUTHOR_ID' => $authorId ?: 1,
			'BINDINGS' => [
				['ENTITY_TYPE_ID' => $this->entityTypeId, 'ENTITY_ID' => $dealId],
			],
		]);

		if((int)$id > 0)
		{
			return true;
		}
		$this->errors[] = ActionSummary::error($what, 'CRM не создала комментарий');

		return false;
	}

	private function addTodo(int $dealId, int $responsibleId, string $text, string $what = 'дело'): bool
	{
		if(!class_exists(\Bitrix\Crm\Activity\Entity\ToDo::class))
		{
			$this->errors[] = ActionSummary::error($what, 'нет класса ToDo');

			return false;
		}

		// В crm 26.800 конструктор — (ItemIdentifier, ActivityProvider):
		// с одним аргументом ArgumentCountError, и дело старшему молча не
		// ставилось (приёмка 2026-10-02, bx-shef/toolsai#3). В старых
		// версиях провайдера в конструкторе нет — его не передаём.
		$owner = new ItemIdentifier($this->entityTypeId, $dealId);
		$constructor = (new \ReflectionClass(\Bitrix\Crm\Activity\Entity\ToDo::class))->getConstructor();
		$todo = $constructor !== null && $constructor->getNumberOfRequiredParameters() >= 2
			? new \Bitrix\Crm\Activity\Entity\ToDo($owner, new \Bitrix\Crm\Activity\Provider\ToDo\ToDo())
			: new \Bitrix\Crm\Activity\Entity\ToDo($owner);
		// Агент работает без подмены пользователя (#291): права CRM — не от «текущего»,
		// автор — служебный пользователь модуля
		if(method_exists($todo, 'setCheckPermissions'))
		{
			$todo->setCheckPermissions(false);
		}
		if(method_exists($todo, 'setAuthorId'))
		{
			$todo->setAuthorId(\Shef\ToolsAiCrm\Container::getAgentUserId() ?: 1);
		}
		$todo
			->setDescription($text)
			->setResponsibleId($responsibleId)
			->setDeadline((new DateTime())->add('+'.self::DEADLINE_HOURS.' hours'));

		$result = $todo->save();
		if($result->isSuccess())
		{
			return true;
		}
		$this->errors[] = ActionSummary::error($what, implode('; ', $result->getErrorMessages()));

		return false;
	}
}
