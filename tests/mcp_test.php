<?php declare(strict_types=1);

/**
 * REST и MCP: манифест инструмента toolsai_manager_scores по праву reports.all,
 * разбор аргументов, рейтинг менеджеров, право в самом методе (не только в
 * манифесте), регистрация методов в области shef_toolsaicrm.
 */

namespace Bitrix\Rest
{
	if(!class_exists(RestException::class))
	{
		class RestException extends \Exception
		{
			public function __construct(string $message = '', public readonly string $restCode = '', public readonly string $status = '')
			{
				parent::__construct($message);
			}
		}
	}
}

namespace
{
if(!class_exists('CRestServer'))
{
	class CRestServer
	{
		public const STATUS_WRONG_REQUEST = '400 Bad Request';
		public const STATUS_FORBIDDEN = '403 Forbidden';
		public const STATUS_NOT_FOUND = '404 Not Found';
		public const STATUS_INTERNAL = '500 Internal Server Error';
	}
}

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Bitrix\Rest\RestException;
use Shef\ToolsAiCrm\Integration\Rest\Service;
use Shef\ToolsAiCrm\Rest\McpTools;
use Shef\ToolsAiCrm\Rest\Period;

/** Service с подставными правом и данными: портала у теста нет. */
class RestAs extends Service
{
	public static bool $all = true;
	/** @var list<array{user: int, count: int, avg: float, min: int}> */
	public static array $scores = [];
	public static bool $truncated = false;
	public static ?array $department = null;
	public static int $reads = 0;

	protected static function canReportsAll(): bool
	{
		return static::$all;
	}

	protected static function scores(array $args): array
	{
		static::$reads++;

		return ['scores' => static::$scores, 'truncated' => static::$truncated];
	}

	protected static function departmentUsers(int $department): ?array
	{
		return $department > 0 ? static::$department : null;
	}

	protected static function names(array $ids): array
	{
		return array_intersect_key([1 => 'Иванов Пётр', 2 => 'Сидоров Олег'], array_flip($ids));
	}

	protected static function host(): string
	{
		return 'https://portal.example';
	}
}

$call = static function(string $method, array $query = []): array
{
	try
	{
		return ['ok' => RestAs::$method($query)];
	}
	catch(RestException $e)
	{
		return ['error' => $e->restCode, 'message' => $e->getMessage(), 'status' => $e->status];
	}
};
$now = new DateTimeImmutable('2026-11-10 12:00:00');

Check::group('манифест');
Check::same('без права — пусто', McpTools::manifest(false), []);
$tools = McpTools::manifest(true);
Check::same('с правом — один инструмент, прежнее имя', array_column($tools, 'name'), ['toolsai_manager_scores']);
$t = $tools[0];
Check::same('метод нового модуля, вид read', [$t['method'], $t['kind']], ['shef.toolsaicrm.report.managerscores', 'read']);
Check::same('метод — вендор shef', explode('.', $t['method'])[0], 'shef');
Check::same('имя по правилам сервера', (bool)preg_match('/^[a-z0-9_]{2,48}$/', $t['name']), true);
Check::same('описание до 1000', mb_strlen($t['description']) <= 1000, true);
Check::same('схема до 8000', strlen((string)json_encode($t['input'], JSON_UNESCAPED_UNICODE)) <= 8000, true);
Check::same('поля схемы', array_keys($t['input']['properties']), ['from', 'to', 'userId', 'departmentId', 'limit']);
Check::same('в описании нет цен, названия модели и сравнений', preg_match('/DeepSeek|₽|руб|BitrixGPT/iu', json_encode($t, JSON_UNESCAPED_UNICODE)), 0);
Check::same('форма манифеста: name, title, description, method, kind, input', array_keys($t), ['name', 'title', 'description', 'method', 'kind', 'input']);

Check::group('регистрация');
$registered = Service::onRestServiceBuildDescription();
Check::same('область', array_keys($registered), ['shef_toolsaicrm']);
Check::same('методы', array_keys($registered['shef_toolsaicrm']), [McpTools::METHOD_MANIFEST, McpTools::METHOD_MANAGER_SCORES]);
Check::same('имена методов', [McpTools::METHOD_MANIFEST, McpTools::METHOD_MANAGER_SCORES], ['shef.toolsaicrm.mcp.tools', 'shef.toolsaicrm.report.managerscores']);
foreach($registered['shef_toolsaicrm'] as $name => $cb)
{
	Check::same($name.' вызываем', is_callable($cb), true);
}

Check::group('аргументы');
[$args, $error] = McpTools::parse([], $now);
Check::same('по умолчанию: с 1-го по сегодня, лимит 30', [$args['period']->getFromValue(), $args['period']->getToValue(), $args['limit'], $args['userId'], $args['departmentId'], $error], ['2026-11-01', '2026-11-10', 30, 0, 0, null]);
[$args] = McpTools::parse(['from' => '2026-11-05', 'to' => '2026-11-03', 'userId' => '7', 'limit' => 999], $now);
Check::same('«с» позже «по» — меняем; id строкой; лимит до 50', [$args['period']->getFromValue(), $args['period']->getToValue(), $args['userId'], $args['limit']], ['2026-11-03', '2026-11-05', 7, 50]);
Check::same('плохая дата — ошибка, а не умолчание', McpTools::parse(['from' => '2026-02-30'], $now)[1][0], 'INVALID_ARGUMENT');
Check::same('мусор вместо даты', McpTools::parse(['to' => 'вчера'], $now)[1][0], 'INVALID_ARGUMENT');
Check::same('userId 0 — ошибка', McpTools::parse(['userId' => 0], $now)[1][0], 'INVALID_ARGUMENT');
Check::same('limit «abc» — ошибка', McpTools::parse(['limit' => 'abc'], $now)[1][0], 'INVALID_ARGUMENT');
Check::same('departmentId -1 — ошибка', McpTools::parse(['departmentId' => -1], $now)[1][0], 'INVALID_ARGUMENT');
Check::same('Period: один день', (static function() use($now): array
{
	$p = Period::fromRequest('2026-11-03', '2026-11-03', $now);

	return [$p->getFromValue(), $p->getToValue(), $p->till->format('Y-m-d')];
})(), ['2026-11-03', '2026-11-03', '2026-11-04']);

Check::group('рейтинг');
$scores = [
	['user' => 1, 'count' => 4, 'avg' => 70.5, 'min' => 40],
	['user' => 2, 'count' => 9, 'avg' => 88.0, 'min' => 60],
	['user' => 3, 'count' => 2, 'avg' => 70.5, 'min' => 55],
];
[$args] = McpTools::parse(['from' => '2026-11-01', 'to' => '2026-11-10'], $now);
$r = McpTools::managerScores($scores, $args, null, false, [1 => 'Иванов Пётр', 2 => 'Сидоров Олег'], '/u');
Check::same('лучшие первыми; при равном среднем — больше оценок', array_column($r['managers'], 'userId'), [2, 1, 3]);
Check::same('места', array_column($r['managers'], 'rank'), [1, 2, 3]);
Check::same('строка менеджера', $r['managers'][0], ['rank' => 1, 'userId' => 2, 'name' => 'Сидоров Олег', 'scores' => 9, 'avg' => 88.0, 'min' => 60]);
Check::same('имени нет — «#ID»', $r['managers'][2]['name'], '#3');
Check::same('период и ссылка', [$r['period'], $r['url']], [['from' => '2026-11-01', 'to' => '2026-11-10'], '/u']);
Check::same('полный список — без пометок', [isset($r['more']), isset($r['note']), isset($r['truncated'])], [false, false, false]);
$r = McpTools::managerScores($scores, ['limit' => 2] + $args, null, false, [], '/u');
Check::same('лимит — «more» с остатком', [count($r['managers']), $r['more']], [2, 1]);
$r = McpTools::managerScores($scores, $args, [1, 3], false, [], '/u');
Check::same('фильтр по отделу', array_column($r['managers'], 'userId'), [1, 3]);
$r = McpTools::managerScores($scores, ['userId' => 3] + $args, null, false, [], '/u');
Check::same('фильтр по сотруднику', array_column($r['managers'], 'userId'), [3]);
$r = McpTools::managerScores([], $args, null, false, [], '/u');
Check::same('пусто — пояснение', [$r['managers'], isset($r['note'])], [[], true]);
$r = McpTools::managerScores($scores, $args, null, true, [], '/u');
Check::same('усечено — пометка', [$r['truncated'], isset($r['note'])], [true, true]);
Check::same('имя в ответ модели: одна строка, без управляющих', McpTools::text("Иван\n\u{202E}Иванов\t"), 'Иван Иванов');
Check::same('отделы с подотделами', McpTools::expandDepartment(5, [5 => 1, 6 => 5, 7 => 6, 8 => 2]), [5, 6, 7]);
Check::same('отдел без детей', McpTools::expandDepartment(8, [5 => 1, 8 => 2]), [8]);
Check::same('ссылка на раздел отчёта с периодом', McpTools::url('https://p', $args), 'https://p/shef/toolsaicrm/?section=stats_managers&from=2026-11-01&to=2026-11-10');
Check::same('ссылка без внешнего адреса — от корня портала', str_starts_with(McpTools::url('', $args), '/shef/toolsaicrm/'), true);

Check::group('REST-методы');
RestAs::$all = true;
RestAs::$scores = $scores;
Check::same('манифест с правом', array_column(RestAs::tools([]), 'name'), ['toolsai_manager_scores']);
$r = $call('managerScores', ['from' => '2026-11-01', 'to' => '2026-11-10', 'limit' => 2]);
Check::same('ответ: места, имена, ссылка', [array_column($r['ok']['managers'], 'name'), $r['ok']['url'], $r['ok']['more']], [['Сидоров Олег', 'Иванов Пётр'], 'https://portal.example/shef/toolsaicrm/?section=stats_managers&from=2026-11-01&to=2026-11-10', 1]);
RestAs::$department = [3];
Check::same('отдел — фильтр', array_column($call('managerScores', ['departmentId' => 4])['ok']['managers'], 'userId'), [3]);
RestAs::$department = null;
$reads = RestAs::$reads;
Check::same('плохой аргумент — INVALID_ARGUMENT, БД не читается', [$call('managerScores', ['from' => 'x'])['error'] ?? '', RestAs::$reads], ['INVALID_ARGUMENT', $reads]);
Check::same('статус плохого аргумента — 400', $call('managerScores', ['limit' => 0])['status'] ?? '', CRestServer::STATUS_WRONG_REQUEST);

RestAs::$all = false;
Check::same('манифест без права — пусто', RestAs::tools([]), []);
$reads = RestAs::$reads;
$r = $call('managerScores', []);
Check::same('право — в самом методе, не только в манифесте', [$r['error'] ?? '', $r['status'] ?? '', RestAs::$reads], ['ACCESS_DENIED', CRestServer::STATUS_FORBIDDEN, $reads]);
// настоящее право: без сотрудника в токене AccessService не зовётся, ответ — нет права
\Bitrix\Main\Engine\CurrentUser::$id = null;
Check::same('токен без сотрудника — нет права', (new class extends Service { public static function ask(): bool { return static::canReportsAll(); } })::ask(), false);

Check::finish();
}
