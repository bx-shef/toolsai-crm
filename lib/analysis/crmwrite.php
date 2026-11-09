<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Analysis;

use Bitrix\Main\Event;
use Bitrix\Main\Loader;

/**
 * Одна запись модуля в саму запись CRM (#497): итоги анализа, кнопки
 * отметок, сброс наших полей после конвертации, теги звонка в «Комментарий».
 * Смена ответственного из вида «AI» сюда не идёт — это действие сотрудника,
 * штатная операция (View\RecordActions::reassign()).
 *
 * Пишет фабрикой CRM (Service\Factory::getUpdateOperation). Режим —
 * настройка DEAL_crmevents (Config::isCrmEventsEnabled(), по умолчанию нет):
 *   нет — без роботов, БП и записи в историю (disableAutomation,
 *         disableBizProc, disableSaveToHistory). Совместимые
 *         OnBefore/OnAfterCrm…Update и onCrmDynamicItemUpdate идут
 *         (BeforeSave/AfterSave операции) — замер на стенде, #497;
 *   да  — как ручное сохранение: роботы, БП, история.
 * В обоих режимах — без проверки прав (агент работает без пользователя),
 * обязательных полей и запущенных БП: иначе итог агента может не лечь.
 *
 * После успешной записи — своё событие shef.toolsaicrm:OnAfterCrmWrite
 * (docs/guide/32-public-api.md), в обоих режимах.
 */
final class CrmWrite
{
	public const MODULE_ID = 'shef.toolsaicrm';
	public const EVENT = 'OnAfterCrmWrite';

	public const SOURCE_ANALYSIS = 'analysis';
	public const SOURCE_FLAG = 'flag';
	public const SOURCE_CONVERSION = 'conversion';
	public const SOURCE_CALLTAGS = 'calltags';
	public const SOURCES = [self::SOURCE_ANALYSIS, self::SOURCE_FLAG, self::SOURCE_CONVERSION, self::SOURCE_CALLTAGS];

	/** Причины отказа (текст — для журнала и «последних проверок»). */
	public const NO_CRM = 'модуль CRM не подключён';
	public const NOT_FOUND = 'запись не найдена';
	/** Ни одного поля из значений у записи нет или менять нечего. */
	public const NOTHING = 'нечего записывать';

	/** Пишет сам модуль — защита полей (Deal\Field\Handlers) пропускает. */
	private static bool $internalWrite = false;

	public static function isInternalWrite(): bool
	{
		return self::$internalWrite;
	}

	/**
	 * Записать поля.
	 *
	 * Поля, значение которых не меняется (Item::isChanged()), не пишутся и в
	 * событие не идут; не меняется ни одно — записи и события нет, это успех.
	 *
	 * @param array<string, mixed> $values
	 * @param (callable(object): array<string, mixed>)|null $more ещё значения по загруженной записи; ключи $values главнее
	 * @return ?string null — записано или менять нечего; иначе причина (self::NO_CRM, NOT_FOUND, NOTHING, сбой или ошибка CRM)
	 */
	public static function update(int $entityTypeId, int $id, array $values, string $source, ?callable $more = null): ?string
	{
		if(!Loader::includeModule('crm') || !class_exists(\Bitrix\Crm\Service\Container::class))
		{
			return self::NO_CRM;
		}
		try
		{
			$factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory($entityTypeId);
			$item = $factory?->getItem($id);
			if($item === null)
			{
				return self::NOT_FOUND;
			}

			if($more !== null)
			{
				$values += $more($item);
			}

			$known = false;
			$written = [];
			foreach($values as $code => $value)
			{
				if($item->hasField($code))
				{
					$known = true;
					$item->set($code, $value);
					if(!method_exists($item, 'isChanged') || $item->isChanged($code))
					{
						$written[$code] = $value;
					}
				}
			}
			if(!$known)
			{
				return self::NOTHING;
			}
			if($written === [])
			{
				return null;
			}

			$crmEvents = \Shef\ToolsAiCrm\Container::getConfig()->isCrmEventsEnabled();
			$operation = static::configure($factory->getUpdateOperation($item), $crmEvents);

			// Защита полей пропускает только саму запись модуля: флаг снимается
			// действием «после сохранения» — оно идёт до БП и роботов
			// (crm Service\Operation::launch()), их правки наших полей защита
			// откатывает. Вложенная запись возвращает флаг внешней.
			$previous = self::$internalWrite;
			self::$internalWrite = true;
			static::releaseAfterSave($operation, $previous);
			try
			{
				$result = $operation->launch();
			}
			finally
			{
				self::$internalWrite = $previous;
			}
		}
		catch(\Throwable $throwable)
		{
			\Shef\ToolsAiCrm\Container::getLogger(static::class)?->error($throwable);

			return 'сбой записи в CRM: '.mb_substr($throwable->getMessage(), 0, 300);
		}
		if(!$result->isSuccess())
		{
			return 'CRM не сохранила поля: '.mb_substr(implode('; ', $result->getErrorMessages()) ?: 'без текста ошибки', 0, 300);
		}

		static::notify($entityTypeId, $id, $written, $source, $crmEvents);

		return null;
	}

	/** Действие «после сохранения», которое возвращает флаг защиты полей. */
	private static function releaseAfterSave(object $operation, bool $previous): void
	{
		if(!class_exists(\Bitrix\Crm\Service\Operation\Action::class) || !method_exists($operation, 'addAction'))
		{
			return;
		}
		$operation->addAction(\Bitrix\Crm\Service\Operation::ACTION_AFTER_SAVE, new class(static function() use ($previous): void { self::$internalWrite = $previous; }) extends \Bitrix\Crm\Service\Operation\Action
		{
			public function __construct(private \Closure $release)
			{
				parent::__construct();
			}

			public function process(\Bitrix\Crm\Item $item): \Bitrix\Main\Result
			{
				($this->release)();

				return new \Bitrix\Main\Result();
			}
		});
	}

	/**
	 * Что выключить у операции (@internal, для теста). Без событий — как запись
	 * агента 1.8–1.84: роботы, БП и история выключены. С событиями — только
	 * проверки: права, поля, обязательные поля, запущенные БП, лимиты тарифа.
	 */
	public static function configure(object $operation, bool $crmEvents): object
	{
		$operation
			->disableCheckAccess()
			->disableCheckFields()
			->disableCheckRequiredUserFields()
			->disableCheckWorkflows();
		if(method_exists($operation, 'disableCheckLimits'))
		{
			$operation->disableCheckLimits();
		}
		if(!$crmEvents)
		{
			$operation
				->disableAutomation()
				->disableBizProc()
				->disableSaveToHistory();
		}

		return $operation;
	}

	/** Своё событие после записи. Сбой обработчика записи не отменяет. */
	private static function notify(int $entityTypeId, int $id, array $fields, string $source, bool $crmEvents): void
	{
		try
		{
			(new Event(self::MODULE_ID, self::EVENT, [
				'entityTypeId' => $entityTypeId,
				'id' => $id,
				'fields' => $fields,
				'source' => $source,
				'crmEvents' => $crmEvents,
			]))->send();
		}
		catch(\Throwable $throwable)
		{
			\Shef\ToolsAiCrm\Container::getLogger(static::class)?->error($throwable);
		}
	}
}
