<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\CallScript;

use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Shef\ToolsAiCrm\CallScript\Model\CallScriptRuleTable;
use Shef\ToolsAiCrm\Deal\Profile;
use Shef\ToolsAiCrm\Main\Constants;

/**
 * Подбор скрипта оценки звонка по правилам (#408, часть B, вариант 1).
 *
 * Режим «По правилам» (CALLSCRIPT_mode = rules): на crm:OnBeforeCrmActivityAdd
 * звонка телефонии Битрикс24 (ORIGIN_ID «VI_…») модуль закрепляет за звонком
 * скрипт пользователя — так же, как карточка звонка, когда оператор выбирает
 * скрипт (crm CallCardPlacement::attachCallAssessmentAction():
 * MultiValueStoreService::set(VoxImplantManager::insertPrefix($callId), $id)).
 * Ядро берёт закреплённый скрипт раньше подбора по типу клиента
 * (ItemFactory::getByActivityId()), и оценка одна — штатная. Тексты и
 * критерии скрипта модуль не трогает.
 *
 * Закрепил пользователь — не трогаем. Правило не подошло — запасной скрипт
 * (CALLSCRIPT_fallback), пусто — ничего, штатный выбор. Сбой — тихо: звонок
 * важнее подбора.
 */
final class Events
{
	public const OPTION_MODE = 'CALLSCRIPT_mode';
	public const OPTION_FALLBACK = 'CALLSCRIPT_fallback';
	public const MODE_CORE = 'core';
	public const MODE_RULES = 'rules';

	/** [модуль, событие, класс, метод] */
	public const ADD = ['crm', 'OnBeforeCrmActivityAdd', 'Shef\\ToolsAiCrm\\CallScript\\Events', 'onBeforeActivityAdd'];

	/** Тип дела «звонок» (CCrmActivityType::Call). */
	private const CALL = 2;

	/**
	 * Умеет ли ядро то, на что опирается подбор: скрипты с окном доступности
	 * и закрепление скрипта за звонком (crm 26.1750; на старых версиях — нет).
	 */
	public static function isSupported(): bool
	{
		return method_exists('\Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentController', 'getCurrentAvailableAssessmentFilter')
			&& method_exists('\Bitrix\Crm\MultiValueStoreService', 'set')
			&& method_exists('\Bitrix\Crm\Integration\VoxImplantManager', 'insertPrefix');
	}

	public static function isRulesMode(): bool
	{
		return Option::get(Constants::MODULE_ID, static::OPTION_MODE, static::MODE_CORE) === static::MODE_RULES;
	}

	public static function getFallback(): int
	{
		$value = Option::get(Constants::MODULE_ID, static::OPTION_FALLBACK, '');

		return 1 === preg_match('/^[1-9]\d{0,9}$/', $value) ? (int)$value : 0;
	}

	/**
	 * Какой скрипт закрепить. Чистая функция: правило по записи, иначе запасной.
	 *
	 * @param Rule[] $rules
	 * @param int[] $available ID включённых скриптов
	 */
	public static function choose(array $rules, ?array $target, array $available, int $fallback): int
	{
		if($target !== null)
		{
			[$entityTypeId, $categoryId, $stageId, $semantic] = $target;
			$rule = RulePicker::pick($rules, $entityTypeId, $categoryId, $stageId, $semantic, $available);
			if($rule !== null)
			{
				return $rule->assessmentId;
			}
		}

		return $fallback > 0 && in_array($fallback, $available, true) ? $fallback : 0;
	}

	/** Звонок телефонии Битрикс24 -> ID звонка; иначе null. */
	public static function callId(array $fields): ?string
	{
		if((int)($fields['TYPE_ID'] ?? 0) !== self::CALL)
		{
			return null;
		}
		$origin = (string)($fields['ORIGIN_ID'] ?? '');
		if(1 !== preg_match('/^VI_([\w.\-]{1,100})$/', $origin, $m))
		{
			return null;
		}

		return $m[1];
	}

	public static function onBeforeActivityAdd(array &$fields): bool
	{
		try
		{
			if(!static::isRulesMode() || !static::isSupported())
			{
				return true;
			}
			$callId = static::callId($fields);
			if($callId === null || !\Bitrix\Main\Loader::includeModule('crm'))
			{
				return true;
			}
			$key = \Bitrix\Crm\Integration\VoxImplantManager::insertPrefix($callId);
			$store = \Bitrix\Crm\MultiValueStoreService::getInstance();
			if($store->getFirstValue($key))
			{
				// Скрипт уже выбран (оператор в карточке звонка) — выбор пользователя главнее
				return true;
			}
			try
			{
				$rules = CallScriptRuleTable::getRules(true);
			}
			catch(\Throwable)
			{
				// Таблицы нет — правил нет, запасной скрипт всё равно действует
				$rules = [];
			}
			$id = static::choose($rules, static::target($fields), static::availableScripts(), static::getFallback());
			if($id > 0)
			{
				$store->set($key, (string)$id);
			}
		}
		catch(\Throwable $e)
		{
			\Shef\ToolsAiCrm\Container::getLogger()?->warning('Подбор скрипта звонка: '.$e->getMessage());
		}

		return true;
	}

	/**
	 * Запись звонка и её стадия: [entityTypeId, воронка, стадия, семантика]; нет записи — null.
	 *
	 * @return array{0: int, 1: int, 2: string, 3: string}|null
	 */
	private static function target(array $fields): ?array
	{
		$owner = RulePicker::pickOwner((int)($fields['OWNER_TYPE_ID'] ?? 0), (int)($fields['OWNER_ID'] ?? 0), is_array($fields['BINDINGS'] ?? null) ? $fields['BINDINGS'] : []);
		if($owner === null)
		{
			return null;
		}
		$factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory($owner[0]);
		$item = $factory?->getItem($owner[1]);
		if($item === null)
		{
			return null;
		}
		$categories = $factory->isCategoriesSupported();
		$categoryId = $categories && $item->hasField('CATEGORY_ID') ? (int)$item->getCategoryId() : 0;
		$stageId = $factory->isStagesEnabled() && $item->hasField('STAGE_ID') ? (string)$item->getStageId() : '';
		$semantic = '';
		if($stageId !== '')
		{
			foreach($factory->getStages($categories ? $categoryId : null) as $stage)
			{
				if($stage->getStatusId() === $stageId)
				{
					$semantic = Profile::parseOutcome((string)$stage->getSemantics());
					break;
				}
			}
		}

		return [$owner[0], $categoryId, $stageId, $semantic];
	}

	/**
	 * Включённые скрипты оценки CRM: ID => название.
	 *
	 * Для звонка ($forCall) — только те, что ядро примет закреплёнными: в окне
	 * доступности (getCurrentAvailableAssessmentFilter()) и, при речевой
	 * аналитике нового вида, с критериями (как ItemFactory с requireScoringCriteria).
	 * Иначе закреплённый скрипт оставил бы звонок совсем без автооценки.
	 *
	 * @return array<int, string>
	 */
	public static function scriptNames(bool $forCall = false): array
	{
		$controller = \Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentController::getInstance();
		$filter = ['=IS_ENABLED' => 'Y'];
		if($forCall)
		{
			$available = $controller->getCurrentAvailableAssessmentFilter();
			if($available !== [])
			{
				$filter[] = $available;
			}
			if(method_exists(\Bitrix\Crm\Integration\AI\AIManager::class, 'isCallScoringV2Enabled') && \Bitrix\Crm\Integration\AI\AIManager::isCallScoringV2Enabled())
			{
				$filter['!=CRITERIA.ID'] = null;
			}
		}
		$result = [];
		foreach($controller->getList(['select' => ['ID', 'TITLE'], 'filter' => $filter, 'order' => ['TITLE' => 'ASC'], 'limit' => 500]) as $entity)
		{
			$result[(int)$entity->getId()] = (string)$entity->getTitle();
		}

		return $result;
	}

	/** @return int[] */
	private static function availableScripts(): array
	{
		return array_keys(static::scriptNames(true));
	}

	public static function isRegistered(): bool
	{
		[$from, $event, $class] = self::ADD;
		foreach(EventManager::getInstance()->findEventHandlers($from, $event) as $handler)
		{
			if(($handler['TO_MODULE_ID'] ?? '') === Constants::MODULE_ID && ltrim((string)($handler['TO_CLASS'] ?? ''), '\\') === ltrim($class, '\\'))
			{
				return true;
			}
		}

		return false;
	}

	/** Подписка. Идемпотентно. Таблицу правил заводит страница правил (CallScriptRuleTable::init()). */
	public static function register(): void
	{
		[$from, $event, $class, $method] = self::ADD;
		$manager = EventManager::getInstance();
		$manager->unRegisterEventHandler($from, $event, Constants::MODULE_ID, $class, $method);
		$manager->registerEventHandler($from, $event, Constants::MODULE_ID, $class, $method);
	}
}
