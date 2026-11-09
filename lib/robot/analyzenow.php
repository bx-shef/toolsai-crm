<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Robot;

use Shef\ToolsAiCrm\Agent\AnalysisAgent;
use Shef\ToolsAiCrm\Analysis\CheckStore;
use Shef\ToolsAiCrm\Analysis\EntityType;
use Shef\ToolsAiCrm\Analysis\EntityTypes;
use Shef\ToolsAiCrm\Container;

/**
 * Робот «AI: оценить сейчас» (1.29.0, #73): анализ записи сразу, не дожидаясь
 * агента. Тот же путь, что ручной прогон по ID (AnalysisAgent::process с
 * $onlyIds): профиль, модель, журнал расхода и квота, шкала (дела,
 * комментарии), поля «AI: …» — всё как у агента. Под той же блокировкой
 * типа: робот рядом с агентом не платит дважды за одну запись.
 *
 * Робот запускается при каждом вызове из процесса, без паузы между
 * запусками: сколько раз и когда его вызывать, решает процесс, расход
 * ограничивает квота модуля. Проверки — лицензия, квота, блокировка,
 * профиль; у каждого отказа своя причина (reason) и своё сообщение (#506).
 */
final class AnalyzeNow
{
	public const DONE = 'done';
	public const SKIPPED = 'skipped';
	public const BUSY = 'busy';
	public const OFF = 'off';
	public const ERROR = 'error';

	/** Причины отказа (reason): по ним итог «Оценить запись» пишет, что мешает */
	public const REASON_UNSUPPORTED = 'unsupported';
	public const REASON_LICENSE = 'license';
	public const REASON_QUOTA = 'quota';
	public const REASON_PROFILE = 'noProfile';
	public const REASON_BUSY = 'busy';

	/** Что делать при исчерпанной квоте — журнал робота и итог «Оценить запись» */
	public static function quotaMessage(string $what): string
	{
		return 'Месячная квота AI исчерпана — '.$what.'. Расход за месяц — в разделе «Расход и включение» настроек Tools AI'
			.' (CRM → Настройки → «AI: настройки shef.toolsai»), лимит — поле «Месячная квота» в «Общих настройках».'
			.' Поднимите лимит или дождитесь следующего месяца.';
	}

	/**
	 * @return array{status: string, reason: string, risk: ?int, why: string, nextStep: string, action: string, message: string}
	 */
	public static function run(int $entityTypeId, int $id): array
	{
		return self::runWith(
			$entityTypeId,
			$id,
			static fn(): bool => Container::isLicensed(),
			static fn(): string => Container::licenseReason(),
			static fn(): bool => Container::allow()->getErrorCode() === \Shef\ToolsAi\Public\Service\LlmService::ERROR_QUOTA,
			static fn(): ?array => AnalysisAgent::runLocked($entityTypeId, static fn(): array => AnalysisAgent::process($entityTypeId, [$id])),
			static fn(EntityType $type): array => CheckStore::get($type, $id) ?? [],
		);
	}

	/**
	 * Тот же запуск с подставными зависимостями — robot_test.php.
	 *
	 * @param \Closure(): bool $licensed
	 * @param \Closure(): string $reason
	 * @param \Closure(): bool $exceeded месячная квота модуля исчерпана
	 * @param \Closure(): ?array $analyze отчёт агента по записям или null — занято
	 * @param \Closure(EntityType): array $check строка проверки записи
	 * @return array{status: string, reason: string, risk: ?int, why: string, nextStep: string, action: string, message: string}
	 */
	public static function runWith(int $entityTypeId, int $id, \Closure $licensed, \Closure $reason, \Closure $exceeded, \Closure $analyze, \Closure $check): array
	{
		$result = static fn(string $status, string $message, array $extra = []): array => $extra + [
			'status' => $status, 'reason' => '', 'risk' => null, 'why' => '', 'nextStep' => '', 'action' => '', 'message' => $message,
		];

		$type = EntityTypes::get($entityTypeId);
		if($type === null || $id <= 0)
		{
			return $result(self::OFF, 'Анализ этого типа записей модулем не поддерживается.', ['reason' => self::REASON_UNSUPPORTED]);
		}
		$noLicense = static fn(): array => $result(self::OFF, 'Анализ не запущен: '.($reason() ?: 'нет действующей лицензии shef.toolsai.'), ['reason' => self::REASON_LICENSE]);
		$noQuota = static fn(): array => $result(self::OFF, self::quotaMessage('анализ не запущен'), ['reason' => self::REASON_QUOTA]);
		if(!$licensed())
		{
			return $noLicense();
		}
		if($exceeded())
		{
			return $noQuota();
		}

		$report = $analyze();
		if($report === null)
		{
			return $result(self::BUSY, 'Сейчас идёт прогон агента анализа — попробуйте позже.', ['reason' => self::REASON_BUSY]);
		}
		$row = $report[$id] ?? null;
		if($row === null)
		{
			return $result(self::OFF, 'Нет включённого профиля анализа для этой записи.', ['reason' => self::REASON_PROFILE]);
		}
		// Runner остановился до записи: квота или лицензия кончились за время
		// ожидания блокировки — та же причина, что и до прогона
		$stop = (string)($row['stop'] ?? '');
		if($stop === \Shef\ToolsAiCrm\Analysis\Runner::STOP_QUOTA)
		{
			return $noQuota();
		}
		if($stop === \Shef\ToolsAiCrm\Analysis\Runner::STOP_LICENSE)
		{
			return $noLicense();
		}
		if(($row['error'] ?? '') !== '')
		{
			return $result(self::ERROR, 'Ошибка анализа: '.$row['error']);
		}

		return $result(
			!empty($row['skipped']) ? self::SKIPPED : self::DONE,
			!empty($row['skipped']) ? 'Анализ пропущен: '.(string)($row['action'] ?? '') : 'Риск '.(int)$row['risk'].'%. '.(string)($row['action'] ?? ''),
			self::fromCheck($check($type)) + ['action' => (string)($row['action'] ?? '')]
		);
	}

	/** Последний вердикт записи из строки проверки. */
	private static function fromCheck(array $check): array
	{
		return [
			'risk' => isset($check['RISK']) && ($check['SKIPPED'] ?? 'N') !== 'Y' ? (int)$check['RISK'] : null,
			'why' => (string)($check['WHY'] ?? ''),
			'nextStep' => (string)($check['NEXT_STEP'] ?? ''),
		];
	}
}
