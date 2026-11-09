<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Analysis;

use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Shef\ToolsAiCrm\Deal\Field\AckType;
use Shef\ToolsAiCrm\Deal\Field\Handlers;
use Shef\ToolsAiCrm\Deal\Field\AckValue;
use Shef\ToolsAiCrm\Deal\Field\CardLayout;
use Shef\ToolsAiCrm\Deal\Verdict;

/**
 * Поля «AI: …» на любой сущности блока анализа: сделка, лид,
 * смарт-процесс. Коды те же, что у сделки с 1.8.0, меняется только
 * ENTITY_ID (EntityType::$ufEntityId). С 1.9.1 обёртки для сделки нет:
 * сделка — один из типов.
 *
 *   UF_SHEF_AI_RISK        «AI: риск, %»       integer, в фильтре списка
 *   UF_SHEF_AI_DATE        «AI: дата анализа»  datetime
 *   UF_SHEF_AI_WHY         «AI: почему»        string (3 строки)
 *   UF_SHEF_AI_TODO        «AI: что сделать»   string (3 строки)
 *   UF_SHEF_AI_ACK_SENIOR  «AI: риск под контролем»  AckType, кнопка старшему
 *   UF_SHEF_AI_ACK_MANAGER «AI: отработано»          AckType, кнопка ответственному
 *   UF_SHEF_AI_DONE_*      флажки «отметил» для фильтра списка
 *   UF_SHEF_AI_HISTORY     «AI: история»      string, последние анализы (1.9.0)
 *
 * Создаёт «Проверить и включить» (Main\Setup) идемпотентно через
 * CUserTypeEntity — у типов с включённым профилем; удаляет деинсталляция
 * без savedata.
 *
 * ТОЛЬКО ЧТЕНИЕ. Флага «только чтение» у пользовательского поля в ядре нет,
 * а карточка CRM (crm/lib/Service/EditorAdapter.php::prepareEntityUserFields)
 * считает любое UF редактируемым. Поэтому три штатных механизма вместе:
 *   - EDIT_IN_LIST = N — в списке (гриде) поле не редактируется;
 *   - событие main:onGetPublicEdit (CUserTypeManager::GetPublicEdit) —
 *     для наших полей вместо редактора отдаётся просмотр;
 *   - событие «до изменения» записи — правка наших полей кем угодно,
 *     кроме самого модуля (write()), откатывается к значению из базы:
 *     сделка — crm:OnBeforeCrmDealUpdate, лид — crm:OnBeforeCrmLeadUpdate
 *     (crm/lib/Service/Factory/Lead.php:542). У смарт-процессов
 *     совместимого «до изменения» нет — их поля держат только
 *     EDIT_IN_LIST = N и onGetPublicEdit (docs/private/02-deal-health.md).
 *
 * ЗАПИСЬ — только через CrmWrite::update() (#497): фабрика CRM, роботы,
 * БП и история — по настройке DEAL_crmevents (по умолчанию выключены:
 * иначе каждое обновление поля «риск» запускало бы роботы «изменение записи»).
 */
final class EntityFields
{
	public const RISK = 'UF_SHEF_AI_RISK';
	public const DATE = 'UF_SHEF_AI_DATE';
	public const WHY = 'UF_SHEF_AI_WHY';
	public const TODO = 'UF_SHEF_AI_TODO';
	public const ACK_SENIOR = 'UF_SHEF_AI_ACK_SENIOR';
	public const ACK_MANAGER = 'UF_SHEF_AI_ACK_MANAGER';
	public const DONE_SENIOR = 'UF_SHEF_AI_DONE_SENIOR';
	public const DONE_MANAGER = 'UF_SHEF_AI_DONE_MANAGER';
	/** «AI: история» — последние анализы строкой (1.9.0). */
	public const HISTORY = 'UF_SHEF_AI_HISTORY';
	public const DONE_FLAGS = [self::ACK_SENIOR => self::DONE_SENIOR, self::ACK_MANAGER => self::DONE_MANAGER];

	/**
	 * События, которые держат поля: [модуль, событие, класс, метод]. Имена
	 * методов Handlers — те, что уже записаны в b_module_to_module (1.8.0, 1.9.0).
	 */
	public const EVENTS = [
		['main', 'OnUserTypeBuildList', AckType::class, 'getUserTypeDescription'],
		['main', 'onGetPublicEdit', Handlers::class, 'onGetPublicEdit'],
		['crm', 'OnBeforeCrmDealUpdate', Handlers::class, 'onBeforeDealUpdate'],
		['crm', 'OnBeforeCrmLeadUpdate', Handlers::class, 'onBeforeLeadUpdate'],
		// 1.11.0: очистка наших полей у записи, созданной из лида (ConversionCleaner).
		['crm', 'OnBeforeCrmDealAdd', Handlers::class, 'onBeforeDealAdd'],
		['crm', 'onCrmDynamicItemAdd', Handlers::class, 'onDynamicItemAdd'],
	];

	/** Зарегистрировать события (идемпотентно). */
	public static function registerEvents(string $moduleId): void
	{
		$manager = EventManager::getInstance();
		foreach(static::EVENTS as [$from, $event, $class, $method])
		{
			$manager->unRegisterEventHandler($from, $event, $moduleId, $class, $method);
			$manager->registerEventHandler($from, $event, $moduleId, $class, $method);
		}
	}

	public static function unregisterEvents(string $moduleId): void
	{
		$manager = EventManager::getInstance();
		foreach(static::EVENTS as [$from, $event, $class, $method])
		{
			$manager->unRegisterEventHandler($from, $event, $moduleId, $class, $method);
		}
	}

	/** Пишет сам модуль — защита полей от правки пропускает (флаг — у CrmWrite, #497). */
	public static function isInternalWrite(): bool
	{
		return CrmWrite::isInternalWrite();
	}

	/** Все наши поля. */
	public static function getCodes(): array
	{
		return array_keys(static::getDefinitions(EntityType::deal()));
	}

	/** Поля без своего типа: им редактор подменяет onGetPublicEdit. */
	public static function getPlainCodes(): array
	{
		return [static::RISK, static::DATE, static::WHY, static::TODO, static::DONE_SENIOR, static::DONE_MANAGER, static::HISTORY];
	}

	/** Подсказки полей (ru, en) — что значит поле и кто его пишет. */
	public const HELP = [
		self::RISK => ['ru' => 'Риск потерять запись по оценке AI, 0–100. −1 — оценить нечего (нет звонков, писем, комментариев). Пишет агент анализа shef.toolsai.', 'en' => 'AI risk of losing the record, 0–100; −1 — nothing to assess. Written by the shef.toolsai analysis agent.'],
		self::DATE => ['ru' => 'Когда AI последний раз разобрал запись. Свежие и активные записи агент не трогает до порога тишины профиля.', 'en' => 'When AI last analysed the record.'],
		self::WHY => ['ru' => 'Почему AI поставил такой риск — факты из звонков, писем и истории записи.', 'en' => 'Why AI set this risk — facts from calls, emails and history.'],
		self::TODO => ['ru' => 'Что сделать, чтобы снизить риск, — следующий шаг от AI.', 'en' => 'Next step suggested by AI to lower the risk.'],
		self::ACK_SENIOR => ['ru' => 'Кнопка старшего: риск под контролем. Попросит коротко написать почему — пояснение уйдёт комментарием в ленту. При новом риске отметка сбросится.', 'en' => 'Senior button: risk under control.'],
		self::ACK_MANAGER => ['ru' => 'Кнопка менеджера: риск отработан. Отметку увидит руководитель; при новом риске она сбросится.', 'en' => 'Manager button: risk handled.'],
		self::DONE_SENIOR => ['ru' => '«Да», когда старший нажал «Риск под контролем». Только чтение — меняет кнопка.', 'en' => 'Yes when the senior marked the risk as under control.'],
		self::DONE_MANAGER => ['ru' => '«Да», когда менеджер нажал «Отработано». Только чтение — меняет кнопка.', 'en' => 'Yes when the manager marked the risk as handled.'],
		self::HISTORY => ['ru' => 'История оценок AI по датам: риск и коротко почему.', 'en' => 'History of AI assessments by date.'],
	];

	/**
	 * Описания для CUserTypeEntity::Add(). Чистая функция — без ядра.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function getDefinitions(EntityType $type): array
	{
		$label = static fn(string $ru, string $en): array => [
			'EDIT_FORM_LABEL' => ['ru' => $ru, 'en' => $en],
			'LIST_COLUMN_LABEL' => ['ru' => $ru, 'en' => $en],
			'LIST_FILTER_LABEL' => ['ru' => $ru, 'en' => $en],
		];
		// Подсказка «?» у поля в карточке (1.63.5): штатный HELP_MESSAGE пользовательского поля
		$help = static fn(string $code): array => ['HELP_MESSAGE' => static::HELP[$code]];
		$base = static fn(string $code, string $type2, int $sort, string $filter, array $settings = []): array => [
			'ENTITY_ID' => $type->ufEntityId,
			'FIELD_NAME' => $code,
			'USER_TYPE_ID' => $type2,
			'XML_ID' => $code,
			'SORT' => $sort,
			'MULTIPLE' => 'N',
			'MANDATORY' => 'N',
			'SHOW_FILTER' => $filter,
			'SHOW_IN_LIST' => 'Y',
			'EDIT_IN_LIST' => 'N',
			'IS_SEARCHABLE' => 'N',
			'SETTINGS' => $settings,
		];

		return [
			static::RISK => $base(static::RISK, 'integer', 9000, 'E', ['MIN_VALUE' => Verdict::NOTHING_RISK, 'MAX_VALUE' => 100]) + $label('AI: риск, %', 'AI: risk, %') + $help(static::RISK),
			static::DATE => $base(static::DATE, 'datetime', 9010, 'E') + $label('AI: дата анализа', 'AI: analysis date') + $help(static::DATE),
			static::WHY => $base(static::WHY, 'string', 9020, 'N', ['ROWS' => 3]) + $label('AI: почему', 'AI: why') + $help(static::WHY),
			static::TODO => $base(static::TODO, 'string', 9030, 'N', ['ROWS' => 3]) + $label('AI: что сделать', 'AI: next step') + $help(static::TODO),
			static::ACK_SENIOR => $base(static::ACK_SENIOR, AckType::USER_TYPE_ID, 9040, 'N', ['ROLE' => AckValue::ROLE_SENIOR]) + $label('AI: риск под контролем', 'AI: risk under control') + $help(static::ACK_SENIOR),
			static::ACK_MANAGER => $base(static::ACK_MANAGER, AckType::USER_TYPE_ID, 9050, 'N', ['ROLE' => AckValue::ROLE_MANAGER]) + $label('AI: отработано', 'AI: handled') + $help(static::ACK_MANAGER),
			static::DONE_SENIOR => $base(static::DONE_SENIOR, 'boolean', 9060, 'I', ['DEFAULT_VALUE' => 0, 'DISPLAY' => 'CHECKBOX']) + $label('AI: старший отметил', 'AI: senior marked') + $help(static::DONE_SENIOR),
			static::DONE_MANAGER => $base(static::DONE_MANAGER, 'boolean', 9070, 'I', ['DEFAULT_VALUE' => 0, 'DISPLAY' => 'CHECKBOX']) + $label('AI: менеджер отметил', 'AI: manager marked') + $help(static::DONE_MANAGER),
			static::HISTORY => $base(static::HISTORY, 'string', 9080, 'N', ['ROWS' => 5]) + $label('AI: история', 'AI: history') + $help(static::HISTORY),
		];
	}

	/**
	 * Создать недостающие поля. Есть — порядок и подписи администратора не
	 * трогаем; пустые подписи и подсказку дописываем (mergeLabels()).
	 *
	 * @return array{ok: bool, message: string}
	 */
	public static function ensure(EntityType $type): array
	{
		global $USER_FIELD_MANAGER, $APPLICATION;

		// Тип только что зарегистрирован событием — список типов процесса
		// собран раньше и его не знает (CUserTypeManager::GetUserType).
		if(is_object($USER_FIELD_MANAGER) && !$USER_FIELD_MANAGER->GetUserType(AckType::USER_TYPE_ID))
		{
			$USER_FIELD_MANAGER->CleanCache();
		}

		$created = [];
		$errors = [];
		$entity = new \CUserTypeEntity();
		foreach(static::getDefinitions($type) as $code => $definition)
		{
			// Поле до 1.14.7 создано с MIN_VALUE 0: у целого поля ядра 0 —
			// «без нижней границы» (CUserTypeInteger::CheckFields проверяет
			// только ненулевую), риск -1 проходит — существующее не трогаем.
			$row = \CUserTypeEntity::GetList([], ['ENTITY_ID' => $type->ufEntityId, 'FIELD_NAME' => $code])->Fetch();
			if($row)
			{
				// Пустые подписи и подсказку — дописать из описания, подписи администратора не трогаем.
				// Update ядра стирает ВСЕ подписи поля и пишет только переданные
				// (usertype.php: DELETE FROM b_user_field_lang) — поэтому отдаём полный набор (1.64.3;
				// 1.63.5–1.64.2 передавали один HELP_MESSAGE и стирали название поля).
				$full = \CUserTypeEntity::GetByID((int)$row['ID']);
				$labels = static::mergeLabels(is_array($full) ? $full : [], $definition);
				if($labels !== null)
				{
					$entity->Update((int)$row['ID'], $labels);
				}
				continue;
			}
			if((int)$entity->Add($definition) > 0)
			{
				$created[] = $code;
				continue;
			}
			$exception = is_object($APPLICATION) ? $APPLICATION->GetException() : null;
			$errors[] = $code.($exception ? ': '.$exception->GetString() : '');
		}

		if($errors !== [])
		{
			return ['ok' => false, 'message' => 'не созданы: '.implode('; ', $errors)];
		}

		return ['ok' => true, 'message' => $created === [] ? 'на месте' : 'созданы: '.implode(', ', $created)];
	}

	/** Подписи пользовательского поля — все, что Update ядра переписывает целиком. */
	public const LABEL_KEYS = ['EDIT_FORM_LABEL', 'LIST_COLUMN_LABEL', 'LIST_FILTER_LABEL', 'ERROR_MESSAGE', 'HELP_MESSAGE'];

	/**
	 * Подписи для Update существующего поля (1.64.3): текущие по языкам, пустые и
	 * прежние стандартные («ИИ: …», 1.65.0) — из описания. Чистая функция. null — дописывать нечего (Update не нужен).
	 *
	 * @param array<string, mixed> $current CUserTypeEntity::GetByID(): подпись => [язык => текст]
	 * @param array<string, mixed> $definition getDefinitions()[код]
	 * @return array<string, array<string, string>>|null полный набор LABEL_KEYS
	 */
	public static function mergeLabels(array $current, array $definition): ?array
	{
		$result = [];
		$changed = false;
		foreach(self::LABEL_KEYS as $key)
		{
			$have = is_array($current[$key] ?? null) ? array_map('strval', $current[$key]) : [];
			$want = is_array($definition[$key] ?? null) ? $definition[$key] : [];
			foreach($want as $lang => $text)
			{
				// Пусто или прежняя стандартная подпись модуля («ИИ: …» до 1.65.0, #344) — наша
				$legacy = preg_replace('/^AI(?=[: -])/u', 'ИИ', (string)$text);
				$now = trim($have[$lang] ?? '');
				if(($now === '' || ($now === $legacy && $legacy !== (string)$text)) && trim((string)$text) !== '')
				{
					$have[$lang] = (string)$text;
					$changed = true;
				}
			}
			$result[$key] = $have;
		}

		return $changed ? $result : null;
	}

	/** Удалить поля вместе со значениями. Деинсталляция без savedata. */
	public static function remove(EntityType $type): void
	{
		$entity = new \CUserTypeEntity();
		foreach(static::getCodes() as $code)
		{
			$row = \CUserTypeEntity::GetList([], ['ENTITY_ID' => $type->ufEntityId, 'FIELD_NAME' => $code])->Fetch();
			if(is_array($row))
			{
				$entity->Delete((int)$row['ID']);
			}
		}
	}

	/**
	 * Удалить поля со всех сущностей, где они есть (деинсталляция): по
	 * ENTITY_ID вида CRM_DEAL / CRM_LEAD / CRM_{N}, а не по списку типов —
	 * смарт-процесс могли уже удалить.
	 */
	public static function removeEverywhere(): void
	{
		$entity = new \CUserTypeEntity();
		foreach(static::getCodes() as $code)
		{
			$list = \CUserTypeEntity::GetList([], ['FIELD_NAME' => $code]);
			while($row = $list->Fetch())
			{
				if(EntityType::isAnalysisUfEntity($row['ENTITY_ID'] ?? null))
				{
					$entity->Delete((int)$row['ID']);
				}
			}
		}
	}

	/**
	 * Положить наши поля в общую карточку сущности: раздел «AI-анализ» в
	 * каждой воронке (у лида — одна).
	 *
	 * Ключ настройки карточки — штатный: Crm\Entity\EntityEditorConfig::getConfigId()
	 * (crm/lib/Entity/EntityEditorConfig.php:140) -> EntityEditorOptionBuilder::build()
	 * (crm/lib/Entity/EntityEditorOptionBuilder.php:40-67):
	 *   - сделка  `deal_details` + `_c_{N}` для N > 0 (EntityEditorOptionMap:11,
	 *     Category\EditorHelper::getEditorConfigId():14-24, строчная «c»);
	 *   - лид     `lead_details` (EntityEditorOptionMap:10; у «повторного лида» —
	 *     префикс типа клиента, :44-48 и :82-95; общий лид — без префикса);
	 *   - смарт-процесс `DYNAMIC_{entityTypeId}_details` + `_C{N}`
	 *     (buildDynamicOption(), :69-80 — заглавная «C», без EditorHelper).
	 * Воронку ядро берёт из extras CATEGORY_ID (или DEAL_CATEGORY_ID —
	 * EntityEditorConfig.php:556-559). Хранилище — CUserOptions «crm.entity.editor»
	 * + «_common» (UI\Form\EntityEditorConfiguration), как у сделки с 1.8.3.
	 * Общей настройки нет — карточка штатная, поля и так видны: не создаём.
	 *
	 * @param int[] $categoryIds воронки
	 * @return array{ok: bool, message: string}
	 */
	public static function ensureCard(EntityType $type, array $categoryIds): array
	{
		if(!Loader::includeModule('crm') || !Loader::includeModule('ui'))
		{
			return ['ok' => false, 'message' => 'нет модуля crm/ui'];
		}
		$categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));
		sort($categoryIds);
		if($categoryIds === [])
		{
			return ['ok' => true, 'message' => 'профилей анализа нет — карточку не трогали'];
		}

		$userId = (int)\Bitrix\Main\Engine\CurrentUser::get()->getId() ?: 1;
		$category = \Bitrix\Crm\Entity\EntityEditorConfig::CATEGORY_NAME;
		$scope = \Bitrix\Crm\Entity\EntityEditorConfigScope::COMMON;
		$storage = new \Bitrix\UI\Form\EntityEditorConfiguration($category, $userId);
		$added = $already = $default = $errors = [];
		foreach($categoryIds as $categoryId)
		{
			try
			{
				// Ключ воронки — возможность типа: у сделки прежний DEAL_CATEGORY_ID (1.8.3).
				$extras = $type->cardCategoryExtra !== '' ? [$type->cardCategoryExtra => $categoryId] : [];
				$configId = (new \Bitrix\Crm\Entity\EntityEditorConfig($type->entityTypeId, $userId, $scope, $extras))->getConfigId();
				$config = \CUserOptions::GetOption($category, $configId.'_common', null, 0);
				if(!is_array($config) || $config === [])
				{
					$default[] = $categoryId;
					continue;
				}
				$merged = CardLayout::merge($config, static::getCodes());
				if($merged['added'] === [])
				{
					$already[] = $categoryId;
					continue;
				}
				$storage->set($configId, $merged['config'], ['scope' => $scope]);
				$added[] = $categoryId;
			}
			catch(\Throwable $throwable)
			{
				$errors[] = $categoryId.': '.$throwable->getMessage();
			}
		}

		return ['ok' => $errors === [], 'message' => CardLayout::report($added, $already, $default, $errors)];
	}

	/** Значения полей после анализа (без отметок). Чистая функция. */
	public static function buildAnalysisValues(int $risk, string $why, string $nextStep, mixed $analyzedAt): array
	{
		return [
			static::RISK => $risk,
			static::DATE => $analyzedAt,
			static::WHY => $why,
			static::TODO => $nextStep,
		];
	}

	/**
	 * Отметки после анализа (AckValue::next()). Чистая функция.
	 *
	 * @param bool $reset зона шкалы сменилась (Deal\AckReset::shouldReset())
	 */
	public static function buildAckValues(bool $reset, bool $riskSignificant, mixed $currentSenior, mixed $currentManager, int $seniorId, int $assignedId): array
	{
		$values = [];
		foreach([
			static::ACK_SENIOR => [$currentSenior, $seniorId],
			static::ACK_MANAGER => [$currentManager, $assignedId],
		] as $code => [$current, $addressee])
		{
			$value = AckValue::next($current, $reset, $riskSignificant, $addressee);
			if($value !== null)
			{
				$values[$code] = $value;
				$values[static::DONE_FLAGS[$code]] = static::doneFlag($value);
			}
		}

		return $values;
	}

	/** Флажок для фильтра по значению отметки: 1 — отмечено, иначе 0. */
	public static function doneFlag(mixed $ackValue): int
	{
		return AckValue::parse($ackValue) !== null ? 1 : 0;
	}

	/** Поле отметки по роли. */
	public static function ackCode(string $role): string
	{
		return $role === AckValue::ROLE_SENIOR ? static::ACK_SENIOR : static::ACK_MANAGER;
	}

	/**
	 * Записать итог анализа: поля и отметки (текущие отметки и
	 * ответственный — из той же записи, что и пишется).
	 *
	 * @param (callable(array{senior: mixed, manager: mixed}): array)|null $seenAcks
	 *     получит отметки ДО записи (для истории: «были ли отметки») и
	 *     вернёт ещё значения полей (поле «AI: история»)
	 * @return ?string null — записано; иначе причина (writeReason()), 1.24.15
	 */
	public static function writeAnalysis(EntityType $type, int $id, array $values, bool $reset, bool $riskSignificant, int $seniorId, ?callable $seenAcks = null): ?string
	{
		return static::writeReason($type, $id, $values, static function(object $item) use ($reset, $riskSignificant, $seniorId, $seenAcks): array
		{
			$senior = $item->hasField(static::ACK_SENIOR) ? $item->get(static::ACK_SENIOR) : null;
			$manager = $item->hasField(static::ACK_MANAGER) ? $item->get(static::ACK_MANAGER) : null;
			$more = $seenAcks !== null ? $seenAcks(['senior' => $senior, 'manager' => $manager]) : [];

			return static::buildAckValues($reset, $riskSignificant, $senior, $manager, $seniorId, (int)$item->getAssignedById()) + $more;
		});
	}

	/**
	 * Записать поля через CrmWrite (роботы и БП — по DEAL_crmevents). Полей нет (не нажимали
	 * «Проверить и включить») — молча пропускаем.
	 *
	 * @param array<string, mixed> $values
	 * @param (callable(object): array<string, mixed>)|null $more ещё значения по загруженной записи
	 */
	public static function write(EntityType $type, int $id, array $values, ?callable $more = null, string $source = CrmWrite::SOURCE_ANALYSIS): bool
	{
		return static::writeReason($type, $id, $values, $more, $source) === null;
	}

	/** Причина, если полей «AI: …» у записи нет: их создаёт «Проверить и включить». */
	public const NO_FIELDS = 'поля «AI: …» не созданы — нажмите «Проверить и включить»';

	/**
	 * То же, что write(), но с причиной (1.24.15, #128): null — записано,
	 * иначе текст — почему поля не легли (нет crm, нет записи, полей нет,
	 * ошибка сохранения CRM). Раньше всё это было молчаливым false.
	 *
	 * @param array<string, mixed> $values
	 * @param (callable(object): array<string, mixed>)|null $more
	 */
	public static function writeReason(EntityType $type, int $id, array $values, ?callable $more = null, string $source = CrmWrite::SOURCE_ANALYSIS): ?string
	{
		$why = CrmWrite::update($type->entityTypeId, $id, $values, $source, $more);

		return $why === CrmWrite::NOTHING ? static::NO_FIELDS : $why;
	}
}
