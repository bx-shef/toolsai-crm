<?php declare(strict_types=1);

/**
 * Запись полей «AI: …» с причиной (1.24.15, #128): EntityFields::writeReason()
 * отдаёт текст, почему поля не легли, а не молчаливый false. Агент анализа
 * отработал до «Проверить и включить» — полей у сделок нет, вердикт есть, а в
 * карточке пусто и нигде ни слова.
 *
 * Фабрика CRM — подставная (ниже), класс EntityFields — настоящий.
 */

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
}

namespace
{
	$root = dirname(__DIR__);

	require_once $root.'/tests/stub/autoload.php';
	require_once $root.'/tests/assert.php';

	use Bitrix\Crm\Service\Container;
	use Shef\ToolsAiCrm\Analysis\EntityFields;
	use Shef\ToolsAiCrm\Analysis\EntityType;

	/** Запись с набором полей; launch() — успех или ошибки. */
	$fake = static function(?array $fields, array $errors = []): object
	{
		$item = $fields === null ? null : new class($fields) {
			public array $set = [];
			public function __construct(private array $fields) {}
			public function hasField(string $code): bool { return in_array($code, $this->fields, true); }
			public function set(string $code, mixed $value): void { $this->set[$code] = $value; }
			public function get(string $code): mixed { return null; }
			public function getAssignedById(): int { return 1; }
		};

		return new class($item, $errors) {
			public function __construct(public ?object $item, private array $errors) {}
			public function getItem(int $id): ?object { return $this->item; }
			public function getUpdateOperation(object $item): object
			{
				$errors = $this->errors;

				return new class($errors) {
					public function __construct(private array $errors) {}
					public function __call(string $name, array $args): static { return $this; }
					public function launch(): object
					{
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

	$deal = EntityType::deal();
	$values = [EntityFields::RISK => 80];

	Check::group('writeReason: причина вместо молчаливого false');

	Container::$factory = $fake([EntityFields::RISK]);
	Check::same('поля есть, CRM сохранила — null', EntityFields::writeReason($deal, 1, $values), null);
	Check::same('write() — прежний bool', EntityFields::write($deal, 1, $values), true);

	Container::$factory = $fake([]);
	Check::same('полей нет (до «Проверить и включить») — причина с подсказкой', EntityFields::writeReason($deal, 1, $values), EntityFields::NO_FIELDS);
	Check::same('write() на тех же данных — false', EntityFields::write($deal, 1, $values), false);

	Container::$factory = $fake(null);
	Check::same('записи нет', EntityFields::writeReason($deal, 1, $values), 'запись не найдена');

	Container::$factory = $fake([EntityFields::RISK], ['Поле заблокировано']);
	Check::same('CRM не сохранила — текст её ошибки', EntityFields::writeReason($deal, 1, $values), 'CRM не сохранила поля: Поле заблокировано');

	Check::group('writeAnalysis отдаёт причину — Runner пишет её в «последние проверки» (#128)');

	Container::$factory = $fake([]);
	Check::same('writeAnalysis без полей — причина', EntityFields::writeAnalysis($deal, 1, $values, false, true, 0), EntityFields::NO_FIELDS);
	$runner = (string)file_get_contents($root.'/lib/analysis/runner.php');
	Check::same('Runner проверяет результат обоих вызовов writeAnalysis', [
		substr_count($runner, '$fieldsError = EntityFields::writeAnalysis('),
		substr_count($runner, "'; поля не записаны: '.\$fieldsError"),
	], [2, 2]);

	Check::finish();
}
