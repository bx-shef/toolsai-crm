<?php declare(strict_types=1);

/**
 * #497: одна запись модуля в CRM (Analysis\CrmWrite). Режим по настройке
 * DEAL_crmevents: выключено — без роботов, БП и истории; включено — только
 * проверки выключены. Своё событие OnAfterCrmWrite — после успешной записи,
 * в обоих режимах, только с изменёнными полями. Все пути (анализ, отметка,
 * конвертация) идут через неё.
 * Защита полей видит запись модуля только до «после сохранения» (БП и роботы
 * идут позже — их правки защита откатывает).
 *
 * Фабрика CRM — подставная, записывает вызовы операции.
 */

namespace Bitrix\Crm
{
	class Item {}
}

namespace Bitrix\Crm\Service
{
	final class Container
	{
		public static ?object $factory = null;

		public static function getInstance(): static
		{
			return new static();
		}

		public function getFactory(int $entityTypeId): ?object
		{
			return static::$factory;
		}
	}

	class Operation
	{
		public const ACTION_AFTER_SAVE = 'afterSave';
	}
}

namespace Bitrix\Crm\Service\Operation
{
	abstract class Action
	{
		public function __construct() {}

		abstract public function process(\Bitrix\Crm\Item $item): \Bitrix\Main\Result;
	}
}

namespace
{
	$root = dirname(__DIR__);

	require_once $root.'/tests/stub/autoload.php';
	require_once $root.'/tests/assert.php';

	use Bitrix\Crm\Service\Container;
	use Bitrix\Main\Config\Option;
	use Bitrix\Main\Event;
	use Shef\ToolsAiCrm\Analysis\CrmWrite;
	use Shef\ToolsAiCrm\Analysis\EntityFields;
	use Shef\ToolsAiCrm\Analysis\EntityType;

	$calls = new \ArrayObject();
	$seen = new \ArrayObject();
	/** $during — что сделать «во время launch()» (вложенная запись); $throw — исключение CRM. */
	$fake = static function(array $fields, array $errors = [], array $current = [], ?\Throwable $throw = null, ?\Closure $during = null) use ($calls, $seen): object
	{
		$item = new class($fields, $current) {
			public array $set = [];
			public function __construct(private array $fields, private array $current) {}
			public function hasField(string $code): bool { return in_array($code, $this->fields, true); }
			public function set(string $code, mixed $value): void { $this->set[$code] = $value; }
			public function get(string $code): mixed { return $this->current[$code] ?? null; }
			public function isChanged(string $code): bool { return array_key_exists($code, $this->set) && $this->set[$code] !== ($this->current[$code] ?? null); }
			public function getAssignedById(): int { return 1; }
		};

		return new class($item, $errors, $calls, $seen, $throw, $during) {
			public function __construct(public ?object $item, private array $errors, private \ArrayObject $calls, private \ArrayObject $seen, private ?\Throwable $throw, private ?\Closure $during) {}
			public function getItem(int $id): ?object { return $this->item; }
			public function getUpdateOperation(object $item): object
			{
				$this->calls->exchangeArray([]);

				return new class($this->errors, $this->calls, $this->seen, $this->throw, $this->during) {
					private array $after = [];
					public function __construct(private array $errors, private \ArrayObject $calls, private \ArrayObject $seen, private ?\Throwable $throw, private ?\Closure $during) {}
					public function __call(string $name, array $args): static { $this->calls[] = $name; return $this; }
					public function disableCheckLimits(): static { $this->calls[] = 'disableCheckLimits'; return $this; }
					public function addAction(string $placement, object $action): static { $this->after[$placement][] = $action; return $this; }
					public function launch(): object
					{
						$this->calls[] = 'launch';
						// сохранение: флаг поднят
						$this->seen[] = EntityFields::isInternalWrite();
						if($this->throw !== null)
						{
							throw $this->throw;
						}
						if($this->during !== null)
						{
							($this->during)();
							$this->seen[] = EntityFields::isInternalWrite();
						}
						foreach($this->after['afterSave'] ?? [] as $action)
						{
							$action->process(new \Bitrix\Crm\Item());
						}
						// БП и роботы: флаг снят
						$this->seen[] = EntityFields::isInternalWrite();

						return new class($this->errors) {
							public function __construct(private array $errors) {}
							public function isSuccess(): bool { return $this->errors === []; }
							public function getErrorMessages(): array { return $this->errors; }
						};
					}
				};
			}
		};
	};
	$disabled = static function() use ($calls): array { $c = array_values(array_diff($calls->getArrayCopy(), ['launch'])); sort($c); return $c; };
	$checks = ['disableCheckAccess', 'disableCheckFields', 'disableCheckLimits', 'disableCheckRequiredUserFields', 'disableCheckWorkflows'];
	$quiet = array_merge($checks, ['disableAutomation', 'disableBizProc', 'disableSaveToHistory']);
	sort($checks);
	sort($quiet);
	$deal = EntityType::deal();
	$lastEvent = static fn(): ?Event => Event::$sent === [] ? null : Event::$sent[array_key_last(Event::$sent)];

	Check::group('режим: выключено по умолчанию — без роботов, БП и истории');
	Option::$values = [];
	Event::$sent = [];
	Container::$factory = $fake([EntityFields::RISK]);
	Check::same('запись прошла', EntityFields::writeReason($deal, 7, [EntityFields::RISK => 80]), null);
	Check::same('выключены роботы, БП, история и проверки', $disabled(), $quiet);
	Check::same('событие модуля', [$lastEvent()?->moduleId, $lastEvent()?->type], ['shef.toolsaicrm', 'OnAfterCrmWrite']);
	Check::same('параметры события', $lastEvent()?->getParameters(), ['entityTypeId' => 2, 'id' => 7, 'fields' => [EntityFields::RISK => 80], 'source' => 'analysis', 'crmEvents' => false]);
	Check::same('защита полей: сохранение — запись модуля; после «после сохранения» (БП, роботы) и после записи — нет', [$seen->getArrayCopy(), EntityFields::isInternalWrite()], [[true, false], false]);

	Check::group('режим: включено — роботы, БП и история работают, проверки выключены');
	Option::$values = ['shef.toolsaicrm' => ['DEAL_crmevents' => 'Y']];
	Check::same('запись прошла', EntityFields::writeReason($deal, 7, [EntityFields::RISK => 80]), null);
	Check::same('выключены только проверки', $disabled(), $checks);
	Check::same('событие с crmEvents = true', $lastEvent()?->getParameters()['crmEvents'] ?? null, true);
	Option::$values = [];

	Check::group('вложенная запись возвращает флаг внешней');
	$seen->exchangeArray([]);
	$innerFactory = $fake([EntityFields::RISK]);
	Container::$factory = $fake([EntityFields::RISK], [], [], null, static function() use (&$innerFactory): void
	{
		$outer = Container::$factory;
		Container::$factory = $innerFactory;
		EntityFields::writeReason(EntityType::deal(), 8, [EntityFields::RISK => 1], null, CrmWrite::SOURCE_CONVERSION);
		Container::$factory = $outer;
	});
	EntityFields::writeReason($deal, 7, [EntityFields::RISK => 80]);
	Check::same('внешняя: сохранение; вложенная: сохранение и после — флаг внешней (поднят); внешняя после вложенной — поднят; после «после сохранения» — снят', $seen->getArrayCopy(), [true, true, true, true, false]);

	Check::group('не изменилось — ни записи, ни события');
	Event::$sent = [];
	$calls->exchangeArray([]);
	Container::$factory = $fake([EntityFields::RISK, EntityFields::WHY], [], [EntityFields::RISK => 80, EntityFields::WHY => 'a']);
	Check::same('успех', EntityFields::writeReason($deal, 7, [EntityFields::RISK => 80, EntityFields::WHY => 'a']), null);
	Check::same('launch не звали, событий нет', [in_array('launch', $calls->getArrayCopy(), true), count(Event::$sent)], [false, 0]);
	Container::$factory = $fake([EntityFields::RISK, EntityFields::WHY], [], [EntityFields::RISK => 80, EntityFields::WHY => 'a']);
	EntityFields::writeReason($deal, 7, [EntityFields::RISK => 81, EntityFields::WHY => 'a']);
	Check::same('в событии только изменённое', $lastEvent()?->getParameters()['fields'] ?? null, [EntityFields::RISK => 81]);

	Check::group('значения вызывающего главнее $more');
	Container::$factory = $fake([EntityFields::RISK]);
	EntityFields::writeReason($deal, 7, [EntityFields::RISK => 5], static fn(object $item): array => [EntityFields::RISK => 9]);
	Check::same('риск — из $values', Container::$factory->item->set[EntityFields::RISK] ?? null, 5);

	Check::group('исключение CRM — причина, а не исключение');
	Container::$factory = $fake([EntityFields::RISK], [], [], new \RuntimeException('db gone'));
	Check::same('причина', EntityFields::writeReason($deal, 7, [EntityFields::RISK => 1]), 'сбой записи в CRM: db gone');
	Check::same('флаг снят после исключения', EntityFields::isInternalWrite(), false);

	Check::group('источник в событии');
	Container::$factory = $fake([EntityFields::ACK_MANAGER, EntityFields::DONE_MANAGER]);
	EntityFields::write($deal, 3, [EntityFields::ACK_MANAGER => 'x', EntityFields::DONE_MANAGER => 1], null, CrmWrite::SOURCE_FLAG);
	Check::same('отметка — flag, записаны только поля записи', [$lastEvent()?->getParameters()['source'], $lastEvent()?->getParameters()['fields']], ['flag', [EntityFields::ACK_MANAGER => 'x', EntityFields::DONE_MANAGER => 1]]);

	Check::group('нет записи, нет полей, ошибка CRM — события нет');
	Event::$sent = [];
	Container::$factory = $fake([]);
	Check::same('полей нет — прежняя причина EntityFields', EntityFields::writeReason($deal, 1, [EntityFields::RISK => 1]), EntityFields::NO_FIELDS);
	Container::$factory = $fake([EntityFields::RISK], ['Поле заблокировано']);
	Check::same('ошибка CRM — её текст', EntityFields::writeReason($deal, 1, [EntityFields::RISK => 1]), 'CRM не сохранила поля: Поле заблокировано');
	Check::same('флаг записи снят и после ошибки', EntityFields::isInternalWrite(), false);
	Check::same('событий нет', count(Event::$sent), 0);


	Check::group('все пути записи — через CrmWrite');
	$src = static fn(string $f): string => (string)file_get_contents($root.'/'.$f);
	Check::same('getUpdateOperation только в CrmWrite', array_values(array_filter(
		array_map(static fn(string $f): string => substr($f, strlen($root) + 1), array_merge(glob($root.'/lib/*/*.php') ?: [], glob($root.'/lib/*/*/*.php') ?: [])),
		static fn(string $f): bool => str_contains($src($f), '->getUpdateOperation(')
	)), ['lib/analysis/crmwrite.php']);
	Check::same('в модуле нет disableAllChecks()', str_contains($src('lib/analysis/entityfields.php'), 'disableAllChecks'), false);
	Check::same('источники отметки и конвертации — аргументом write()', [
		str_contains($src('lib/controller/dealflag.php'), 'EntityFields::doneFlag($value)], null, \Shef\ToolsAiCrm\Analysis\CrmWrite::SOURCE_FLAG)'),
		str_contains($src('lib/deal/field/handlers.php'), '$reset, null, \Shef\ToolsAiCrm\Analysis\CrmWrite::SOURCE_CONVERSION)'),
	], [true, true]);

	Check::finish();
}
