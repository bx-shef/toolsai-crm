<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Analysis;

use Bitrix\Main\Type\DateTime;
use Shef\ToolsAiCrm\Analysis\Model\HistoryTable;
use Shef\ToolsAiCrm\Container;
use Shef\ToolsAiCrm\Deal\AckReset;
use Shef\ToolsAiCrm\Deal\ActionSummary;
use Shef\ToolsAiCrm\Deal\DealFacts;
use Shef\ToolsAiCrm\Deal\HealthAnalyzer;
use Shef\ToolsAiCrm\Deal\Profile;
use Shef\ToolsAiCrm\Deal\ProfilePicker;
use Shef\ToolsAiCrm\Deal\RiskScale;
use Shef\ToolsAi\Public\Service\LlmService;
use Shef\ToolsAiCrm\Core\ModelException;

/**
 * Общий блок анализа сущности (1.9.0): один проход по списку записей
 * одного типа — сделки, лида или смарт-процесса.
 *
 * Тот же порядок, что был у анализа сделок до 1.9.0 —
 * перенесён без изменения смысла: профиль -> срок повтора -> сводка ->
 * модель -> журнал расхода -> шкала профиля (дело, комментарий, эскалация)
 * -> поля «AI: …» и отметки -> строка проверки. Что зависит от типа:
 *
 *   - сводка — Deal\ContextBuilder с типом (Container::getHealthAnalyzer($type));
 *   - строка проверки — CheckStore (одна таблица на все типы, 1.9.1);
 *   - шаблон промпта — по воронке записи (Prompt\PromptLevel, 1.9.1);
 *   - поля — Analysis\EntityFields на ENTITY_ID типа;
 *   - дела и комментарии — Deal\Escalation с типом.
 *
 * Сколько анализов сделать — $limit: лимит агента типа
 * (Config::getAnalysisMaxPerRun(), 1.9.1). Особая логика сделки — возможности
 * типа (EntityType), а не отдельный путь.
 */
final class Runner
{
	/** Ошибок провайдера подряд, после которых прогон останавливается. */
	public const PROVIDER_ERRORS_MAX = 3;

	/** Причина остановки, если shef.toolsai отказал без текста. */
	public const EXCEEDED_REASON = 'месячная квота модуля shef.toolsai исчерпана или нет действующей лицензии';

	/** Сколько анализов (запросов к модели) сделал последний process(). */
	public int $analyzed = 0;

	/** Прогон остановлен (провайдер лежит, квота) — следующим типам не идти. */
	public bool $stopped = false;

	/** Почему прогон остановлен (#452): ручному прогону по ID — в отчёт по записям, которые не дошли. */
	public string $stopReason = '';

	/** Код остановки (#506): строке записи, до которой не дошло, — поле stop */
	public const STOP_QUOTA = 'quota';
	public const STOP_LICENSE = 'license';
	public const STOP_PROVIDER = 'provider';
	public string $stopCode = '';

	public function __construct(private readonly EntityType $type)
	{
	}

	/**
	 * @param int[] $ids записи по порядку
	 * @param Profile[] $profiles включённые профили ЭТОГО типа
	 * @param bool $checkDue проверять срок повтора (агент — да, ручной прогон по ID — нет)
	 * @return array<int, array{risk: int, needSenior: bool, skipped: bool, why: string, escalated: string[], error: string, profile: int, action: string, costMicro: int}>
	 */
	public function process(array $ids, array $profiles, int $limit, bool $checkDue): array
	{
		$type = $this->type;
		$notDue = false;
		$pick = static function(DealFacts $facts) use ($profiles, $checkDue, $type, &$notDue): ?Profile
		{
			$notDue = false;
			$profile = ProfilePicker::pick($profiles, $facts->categoryId, $facts->clientType, $facts->leadClient, $facts->stageId, $facts->stageSemantic);
			if($profile !== null && $checkDue)
			{
				$checkedAt = CheckStore::get($type, $facts->dealId)['CHECKED_AT'] ?? null;
				if(!ProfilePicker::isDue($profile, $checkedAt instanceof DateTime ? $checkedAt->getTimestamp() : null, time(), $facts->stageMovedAt))
				{
					$notDue = true;

					return null;
				}
			}

			return $profile;
		};

		$analyzer = Container::getHealthAnalyzer($type);
		// Шаблон промпта по воронке записи (1.9.1): воронка -> тип -> стандартный
		// (Prompt\PromptStore::resolve()). Один запрос к таблице на воронку за прогон.
		$templates = [];
		$template = static function(\Shef\ToolsAiCrm\Deal\DealFacts $facts) use ($type, &$templates): Prompt\PromptTemplate
		{
			return $templates[$facts->categoryId] ??= Prompt\PromptStore::resolve(Prompt\PromptLevel::of($type, $facts->categoryId))['template'];
		};
		$escalation = Container::getEscalation();
		$logger = Container::getLogger(static::class);

		$report = [];
		$this->analyzed = 0;
		$this->stopped = false;
		$this->stopReason = '';
		$this->stopCode = '';
		$providerErrors = 0;

		foreach($ids as $id)
		{
			if($this->analyzed >= $limit)
			{
				break;
			}

			if($providerErrors >= static::PROVIDER_ERRORS_MAX)
			{
				$logger?->warning('Анализ остановлен: провайдер не отвечает '.$providerErrors.' раза подряд');
				$this->stopped = true;
				$this->stopReason = 'провайдер не отвечает '.$providerErrors.' раза подряд';
				$this->stopCode = self::STOP_PROVIDER;
				break;
			}

			// Квота и лицензия — перед каждой записью (срок лицензии мог кончиться посреди прогона):
			// отказ даёт shef.toolsai (LlmService::allow), причина — его словами
			$gate = Container::allow();
			if(!$gate->isSuccess())
			{
				$code = $gate->getErrorCode();
				$this->stopReason = implode(' ', $gate->getErrorMessages()) ?: static::EXCEEDED_REASON;
				$this->stopCode = $code === LlmService::ERROR_NO_LICENSE ? self::STOP_LICENSE : self::STOP_QUOTA;
				$logger?->warning('Анализ остановлен: '.$this->stopReason);
				$this->stopped = true;
				break;
			}

			$row = ['risk' => 0, 'needSenior' => false, 'skipped' => false, 'why' => '', 'escalated' => [], 'error' => '', 'profile' => 0, 'action' => '', 'costMicro' => 0];

			// История риска (1.9.0): последние анализы — модели в сводку и в
			// поле «AI: история». Сбой таблицы анализ не рвёт.
			$history = [];
			try
			{
				$history = HistoryTable::getLast($type->entityTypeId, $id, History::FIELD_LIMIT);
			}
			catch(\Throwable $throwable)
			{
				$logger?->error($throwable, ['itemId' => $id]);
			}

			try
			{
				$analysis = $analyzer->analyze($id, $pick, History::toPromptText($history), $template);
			}
			catch(ModelException $exception)
			{
				$this->analyzed++;
				$logger?->error($exception, ['itemId' => $id, 'entityTypeId' => $type->entityTypeId]);

				// Ответ оплачен, но негоден — трата уже в журнале и в квоте (LlmService).
				$row['error'] = $exception->getMessage();
				$row['action'] = 'ошибка';
				$row['costMicro'] = $exception->spentMicro;
				$report[$id] = $row;

				// Провайдер лежит — это не свойство записи: CHECKED_AT не пишем.
				if($exception->isDown())
				{
					$providerErrors++;
					continue;
				}

				// Запись, на которой модель падает сама, падала бы каждый прогон
				// за деньги. Отметить проверенной с ошибкой.
				$this->saveCheck($id, ['CHECKED_AT' => new DateTime(), 'SKIPPED' => 'Y', 'WHY' => mb_substr('Ошибка модели: '.$row['error'], 0, 500), 'ACTION' => 'ошибка модели'], $logger);
				continue;
			}
			catch(\Throwable $throwable)
			{
				// Записи нет или CRM не отдала данные — отметить, иначе она
				// будет первой кандидаткой каждый прогон.
				$logger?->error($throwable, ['itemId' => $id, 'entityTypeId' => $type->entityTypeId]);
				$row['error'] = $throwable->getMessage();
				$row['action'] = 'ошибка';
				$report[$id] = $row;
				$this->saveCheck($id, ['CHECKED_AT' => new DateTime(), 'SKIPPED' => 'Y', 'WHY' => mb_substr('Ошибка: '.$row['error'], 0, 500), 'ACTION' => 'ошибка'], $logger);
				continue;
			}

			if($notDue)
			{
				continue;
			}

			$providerErrors = 0;
			$verdict = $analysis->verdict;

			// Журнал расхода («в работе» и итог) ведёт shef.toolsai (LlmService::completeJson)
			if($analysis->llm !== null)
			{
				$this->analyzed++;
			}

			$escalated = [];
			$marked = null;
			$profile = $analysis->profile;
			$facts = $analysis->facts;
			$check = null;
			try
			{
				$check = CheckStore::get($type, $id);
			}
			catch(\Throwable $throwable)
			{
				$logger?->error($throwable, ['itemId' => $id]);
			}
			$action = $verdict->skipped
				? 'ничего: '.implode('; ', ActionSummary::reasons($verdict, $profile ?? Profile::fromRow([]), 0, 0))
				: '';
			if($profile !== null && $facts !== null && !$verdict->skipped)
			{
				$border = time() - $profile->reanalyzeDays * 86400;
				$recentAt = static fn(mixed $at): ?int => $at instanceof DateTime && $at->getTimestamp() > $border ? $at->getTimestamp() : null;
				$isRecent = static fn(mixed $at): bool => $recentAt($at) !== null;
				try
				{
					$decision = RiskScale::decide(
						$verdict,
						$profile,
						$facts->assignedById,
						$facts->openActivities,
						$isRecent($check['MANAGER_TODO_AT'] ?? null),
						$isRecent($check['ESCALATED_AT'] ?? null),
						$facts->overdueActivities,
						$isRecent($check['OVERDUE_NOTIFIED_AT'] ?? null),
						$isRecent($check['NOTE_COMMENTED_AT'] ?? null),
					);
					$escalated = $escalation->apply($id, $verdict, $profile, $decision, $facts->assignedById, $facts, $type);
					$action = ActionSummary::build(
						$escalated,
						$verdict,
						$profile,
						$facts->assignedById,
						$facts->openActivities,
						$recentAt($check['MANAGER_TODO_AT'] ?? null),
						$recentAt($check['ESCALATED_AT'] ?? null),
					);
					// Сбой постановки дела — в лог и в «Эскалацию», а не тихо (#41)
					foreach($escalation->errors as $error)
					{
						$logger?->warning($error, ['itemId' => $id]);
					}
					$action = ActionSummary::withErrors($action, $escalation->errors);
				}
				catch(\Throwable $throwable)
				{
					$logger?->error($throwable, ['itemId' => $id]);
					$row['error'] = 'Эскалация не удалась: '.$throwable->getMessage();
					$action = 'ошибка эскалации';
				}

				// Поля «AI: …» и отметки; сбой записи не мешает отметке о проверке.
				// Отметки ДО записи — «были ли отметки» для истории.
				$marked = null;
				$now = time();
				try
				{
					$previous = AckReset::previousRisk($check);
					$fieldsError = EntityFields::writeAnalysis(
						$type,
						$id,
						EntityFields::buildAnalysisValues($verdict->risk, $verdict->why, $verdict->nextStep, new DateTime()),
						AckReset::shouldReset($previous, $verdict->risk, $profile->lowBorder, $profile->highBorder),
						$verdict->risk >= $profile->lowBorder,
						$profile->seniorId,
						static function(array $acks) use (&$marked, $history, $verdict, $now): array
						{
							$marked = History::isMarked($acks['senior'], $acks['manager']);

							return [EntityFields::HISTORY => History::toFieldText(array_merge([['at' => $now, 'risk' => $verdict->risk, 'marked' => $marked]], $history))];
						},
					);
				}
				catch(\Throwable $throwable)
				{
					$fieldsError = 'ошибка записи полей: '.$throwable->getMessage();
					$logger?->error($throwable, ['itemId' => $id]);
				}
				// Вердикт есть, а в карточку не лёг (#128) — видно в «последних проверках» и в логе
				if($fieldsError !== null)
				{
					$action = trim($action.'; поля не записаны: '.$fieldsError, '; ');
					$logger?->warning('Анализ: поля «AI: …» не записаны — '.$fieldsError, ['itemId' => $id, 'entityTypeId' => $type->entityTypeId]);
				}
			}

			// Оценить нечего (1.14.7): поля записи — риск -1, причина, дата;
			// «что сделать» пусто. Без модели, без расхода и эскалации. Для
			// отметок -1 — зона «ниже порога» (сброс при смене зоны, без «ждёт отметки»).
			if($verdict->nothingToAssess && $profile !== null)
			{
				$marked = null;
				$now = time();
				try
				{
					$fieldsError = EntityFields::writeAnalysis(
						$type,
						$id,
						EntityFields::buildAnalysisValues($verdict->risk, $verdict->why, '', new DateTime()),
						AckReset::shouldReset(AckReset::previousRisk($check), $verdict->risk, $profile->lowBorder, $profile->highBorder),
						false,
						$profile->seniorId,
						static function(array $acks) use (&$marked, $history, $verdict, $now): array
						{
							$marked = History::isMarked($acks['senior'], $acks['manager']);

							return [EntityFields::HISTORY => History::toFieldText(array_merge([['at' => $now, 'risk' => $verdict->risk, 'marked' => $marked]], $history))];
						},
					);
				}
				catch(\Throwable $throwable)
				{
					$fieldsError = 'ошибка записи полей: '.$throwable->getMessage();
					$logger?->error($throwable, ['itemId' => $id]);
				}
				// Вердикт есть, а в карточку не лёг (#128) — видно в «последних проверках» и в логе
				if($fieldsError !== null)
				{
					$action = trim($action.'; поля не записаны: '.$fieldsError, '; ');
					$logger?->warning('Анализ: поля «AI: …» не записаны — '.$fieldsError, ['itemId' => $id, 'entityTypeId' => $type->entityTypeId]);
				}
			}

			$fields = [
				'CHECKED_AT' => new DateTime(),
				'RISK' => $verdict->risk,
				'NEED_SENIOR' => $verdict->needSenior ? 'Y' : 'N',
				'SKIPPED' => $verdict->skipped ? 'Y' : 'N',
				'WHY' => $verdict->skipped ? $verdict->skipReason : $verdict->why,
				'NEXT_STEP' => $verdict->nextStep,
				'PROFILE_ID' => $profile?->id ?? 0,
				'ACTION' => mb_substr($action, 0, 500),
			];
			if(in_array('comment', $escalated, true) || in_array('todo', $escalated, true))
			{
				$fields['ESCALATED_AT'] = new DateTime();
			}
			if(in_array('manager_todo', $escalated, true))
			{
				$fields['MANAGER_TODO_AT'] = new DateTime();
			}
			if(in_array('overdue_todo', $escalated, true))
			{
				$fields['OVERDUE_NOTIFIED_AT'] = new DateTime();
			}
			if(in_array('note', $escalated, true))
			{
				$fields['NOTE_COMMENTED_AT'] = new DateTime();
			}
			$this->saveCheck($id, $fields, $logger);

			// Строка истории — только когда модель звали и вердикт есть.
			if(($analysis->llm !== null && !$verdict->skipped) || $verdict->nothingToAssess)
			{
				try
				{
					HistoryTable::add([
						'ENTITY_TYPE_ID' => $type->entityTypeId,
						'ENTITY_ID' => $id,
						'CREATED_AT' => new DateTime(),
						'RISK' => $verdict->risk,
						'WHY' => mb_substr($verdict->why, 0, 255),
						'ACTION' => mb_substr($action, 0, 500),
						'COST_MICRO' => $analysis->llm?->costMicro ?? 0,
						'MARKED' => ($marked ?? false) ? 'Y' : 'N',
						// Свои поля шаблона и оценка менеджера (1.67.0) — одним JSON
						'EXTRA' => ($extra = $verdict->extra + $verdict->managerExtra()) !== [] ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null,
					]);
				}
				catch(\Throwable $throwable)
				{
					$logger?->error($throwable, ['itemId' => $id]);
				}
			}

			$report[$id] = [
				'risk' => $verdict->risk,
				'needSenior' => $verdict->needSenior,
				'skipped' => $verdict->skipped,
				// причина пропуска — для отчёта cli/deal-health.php (1.67.1)
				'why' => $verdict->skipped ? $verdict->skipReason : '',
				'escalated' => $escalated,
				'error' => $row['error'],
				'profile' => $profile?->id ?? 0,
				'action' => $action,
				'costMicro' => $analysis->llm?->costMicro ?? 0,
			];
		}

		// Ручной прогон по ID (#452): записи, до которых не дошло, — с причиной, а не молча
		if($this->stopped && !$checkDue)
		{
			foreach($ids as $id)
			{
				$report[$id] ??= ['risk' => 0, 'needSenior' => false, 'skipped' => false, 'why' => '', 'escalated' => [], 'error' => 'прогон остановлен: '.$this->stopReason, 'stop' => $this->stopCode, 'profile' => 0, 'action' => '', 'costMicro' => 0];
			}
		}

		return $report;
	}

	private function saveCheck(int $id, array $fields, ?\Psr\Log\LoggerInterface $logger): void
	{
		try
		{
			CheckStore::save($this->type, $id, $fields);
		}
		catch(\Throwable $throwable)
		{
			$logger?->error($throwable, ['itemId' => $id]);
		}
	}
}
