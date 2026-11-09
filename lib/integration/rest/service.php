<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Integration\Rest;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\UserTable;
use Bitrix\Rest\RestException;
use Shef\ToolsAiCrm\Analysis\Model\EntityCheckTable;
use Shef\ToolsAiCrm\Analysis\Model\HistoryTable;
use Shef\ToolsAiCrm\Container;
use Shef\ToolsAiCrm\Deal\Model\DealProfileTable;
use Shef\ToolsAiCrm\Rest\McpTools;
use Shef\ToolsAiCrm\Stats\AnalysisOwners;
use Shef\ToolsAiCrm\Stats\ManagerScores;

/**
 * REST-методы авто-анализа CRM для MCP-сервера toolsai-mcp — область
 * shef_toolsaicrm, подписка rest:OnRestServiceBuildDescription (installEvents).
 * Что отдаёт манифест и как разбираются аргументы — Rest\McpTools.
 *
 * Вызов идёт токеном сотрудника: право «Статистика по всему порталу»
 * (reports.all, роли shef.toolsai — публичный AccessService) проверяется для
 * него самого в каждом методе, не только в манифесте: манифест кешируется
 * сервером на 5 минут. Сотрудник — только из токена (CurrentUser), параметра
 * «кто» нет.
 *
 * Ошибки — RestException с кодом и понятным текстом.
 */
class Service
{
	public const HISTORY_LIMIT = 20000;
	/** Действие ролей shef.toolsai, открывающее отчёт «Оценка работы менеджеров». */
	public const ACTION_REPORTS_ALL = 'reports.all';

	/** Обработчик rest:OnRestServiceBuildDescription. */
	public static function onRestServiceBuildDescription(): array
	{
		return [
			McpTools::SCOPE => [
				McpTools::METHOD_MANIFEST => [static::class, 'tools'],
				McpTools::METHOD_MANAGER_SCORES => [static::class, 'managerScores'],
			],
		];
	}

	/** Манифест инструментов для toolsai-mcp: только то, что открыто сотруднику из токена. */
	public static function tools(array $query, $nav = null, $server = null): array
	{
		return McpTools::manifest(static::canReportsAll());
	}

	/** Оценка работы менеджеров за период (инструмент toolsai_manager_scores). */
	public static function managerScores(array $query, $nav = null, $server = null): array
	{
		if(!static::canReportsAll())
		{
			throw new RestException('Нет права на этот отчёт: «Статистика по всему порталу» выдаётся на странице прав модуля shef.toolsai', 'ACCESS_DENIED', \CRestServer::STATUS_FORBIDDEN);
		}
		[$args, $error] = McpTools::parse($query, new \DateTimeImmutable());
		if($error !== null)
		{
			throw new RestException($error[1], $error[0], \CRestServer::STATUS_WRONG_REQUEST);
		}
		['scores' => $scores, 'truncated' => $truncated] = static::scores($args);

		return McpTools::managerScores($scores, $args, static::departmentUsers($args['departmentId']), $truncated, static::names(McpTools::userIds($scores)), McpTools::url(static::host(), $args));
	}

	/** Право сотрудника из токена: роли доступа shef.toolsai. */
	protected static function canReportsAll(): bool
	{
		$id = (int)CurrentUser::get()->getId();

		return $id > 0 && (new \Shef\ToolsAi\Public\Service\AccessService())->can(self::ACTION_REPORTS_ALL, $id);
	}

	/**
	 * Оценки менеджеров за период из истории анализа: тот же запрос, что у отчёта
	 * «Оценка работы менеджеров» — сделки, лиды, смарт-процессы.
	 *
	 * @param array{period: \Shef\ToolsAiCrm\Rest\Period, userId: int} $args
	 * @return array{scores: list<array{user: int, count: int, avg: float, min: int}>, truncated: bool}
	 */
	protected static function scores(array $args): array
	{
		if(!Loader::includeModule('crm'))
		{
			throw new RestException('Модуль CRM не установлен', 'CRM_NOT_INSTALLED', \CRestServer::STATUS_WRONG_REQUEST);
		}
		try
		{
			$connection = Application::getConnection();
			$table = HistoryTable::getTableName();
			if(!$connection->isTableExists($table))
			{
				return ['scores' => [], 'truncated' => false];
			}
			$helper = $connection->getSqlHelper();
			$toDb = static fn(\DateTimeImmutable $d): string => $helper->convertToDbDateTime(new \Bitrix\Main\Type\DateTime($d->format('Y-m-d H:i:s'), 'Y-m-d H:i:s'));
			$ownerTables = AnalysisOwners::tables(array_map(
				static fn(array $row): int => (int)$row['ENTITY_TYPE_ID'],
				$connection->query(AnalysisOwners::typesSql(
					$helper->quote(DealProfileTable::getTableName()),
					$helper->quote(EntityCheckTable::getTableName())
				))->fetchAll()
			));
			$sql = ManagerScores::historySql($ownerTables, $helper->quote($table), $toDb($args['period']->from), $toDb($args['period']->till), $args['userId'], self::HISTORY_LIMIT);
			$rows = $connection->query($sql)->fetchAll();
		}
		catch(\Throwable $throwable)
		{
			Container::getLogger(self::class)?->error($throwable);
			throw new RestException('Отчёт сейчас недоступен, повторите позже', 'REPORT_UNAVAILABLE', \CRestServer::STATUS_INTERNAL);
		}

		return ['scores' => ManagerScores::aggregate($rows), 'truncated' => count($rows) >= self::HISTORY_LIMIT];
	}

	/** Сотрудники отдела с подотделами (как фильтр «Отдел» отчёта); 0 — без фильтра. @return ?list<int> */
	protected static function departmentUsers(int $department): ?array
	{
		if($department <= 0)
		{
			return null;
		}
		if(!Loader::includeModule('intranet'))
		{
			throw new RestException('Фильтр по отделу недоступен: нет модуля intranet. Уберите departmentId', 'INVALID_ARGUMENT', \CRestServer::STATUS_WRONG_REQUEST);
		}
		$parents = [];
		foreach((array)(\CIntranetUtils::GetStructure()['DATA'] ?? []) as $id => $section)
		{
			$parents[(int)$id] = (int)($section['IBLOCK_SECTION_ID'] ?? 0);
		}
		$users = [];
		$list = \CUser::GetList('ID', 'ASC', ['UF_DEPARTMENT' => McpTools::expandDepartment($department, $parents)], ['FIELDS' => ['ID']]);
		while($row = $list->Fetch())
		{
			$users[] = (int)$row['ID'];
		}

		return $users;
	}

	/** @param list<int> $ids @return array<int, string> «Фамилия Имя», как в отчёте */
	protected static function names(array $ids): array
	{
		$names = [];
		if($ids !== [])
		{
			foreach(UserTable::getList(['select' => ['ID', 'NAME', 'LAST_NAME'], 'filter' => ['@ID' => $ids]])->fetchAll() as $row)
			{
				$name = trim($row['LAST_NAME'].' '.$row['NAME']);
				// Без имени — пусто: логин бывает почтой, модели его не отдаём (будет «#ID»)
				if($name !== '')
				{
					$names[(int)$row['ID']] = $name;
				}
			}
		}

		return $names;
	}

	/**
	 * Внешний адрес портала для ссылок: DEF_publicurl shef.toolsai, иначе ai::public_url;
	 * нет — ссылка от корня портала (не по заголовку Host запроса).
	 */
	protected static function host(): string
	{
		try
		{
			$url = rtrim(trim((string)Option::get(\Shef\ToolsAiCrm\Main\Constants::CORE_MODULE_ID, 'DEF_publicurl', '')), '/');
			// только адрес сайта: http(s) без пробелов, кавычек и скобок
			$url = preg_match('~^https?://[^\s"\'<>\\\\]+$~i', $url) ? $url : '';
		}
		catch(\Throwable)
		{
			$url = '';
		}

		return $url !== '' ? $url : rtrim((string)Option::get('ai', 'public_url', ''), '/');
	}
}
