<?php declare(strict_types=1);

/**
 * Карточки панели «ракеты» shef.toolsai (OnRocketCardsCollect): состав по правам,
 * формат карточки по контракту панели, сбой — пустой список.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Shef\ToolsAiCrm\Internals\Integration\ToolsAi\EventHandler\OnRocketCardsCollectEventHandler as Handler;

/** Событие с getParameter() ядра (в заглушке его нет). */
class CardsEvent extends Event
{
	public function getParameter(string $key): mixed
	{
		return $this->parameters[$key] ?? null;
	}
}

$codes = static fn(array $cards): array => array_column($cards, 'code');
$with = static fn(string ...$rights): array => Handler::cards(static fn(string $action): bool => in_array($action, $rights, true));

Check::group('состав по правам');
Check::same('без прав — пусто', $with(), []);
Check::same('администратор — профили', $codes($with(Handler::ADMIN)), ['analysis_profiles']);
Check::same('prompts.read — промпты', $codes($with('prompts.read')), ['analysis_prompts']);
Check::same('reports.all — менеджеры', $codes($with('reports.all')), ['manager_scores']);
Check::same('всё сразу — по порядку sort', $codes($with(Handler::ADMIN, 'prompts.read', 'reports.all')), ['analysis_profiles', 'analysis_prompts', 'manager_scores']);
Check::same('reports.view без reports.all — ничего', $with('reports.view'), []);

Check::group('формат карточки (контракт панели shef.toolsai)');
$cards = $with(Handler::ADMIN, 'prompts.read', 'reports.all');
foreach($cards as $card)
{
	Check::same($card['code'].': код по правилам панели', (bool)preg_match('/^[a-z0-9_-]{1,40}$/', $card['code']), true);
	Check::same($card['code'].': вид из допустимых', in_array($card['kind'], ['status', 'action', 'settings', 'use', 'help'], true), true);
	Check::same($card['code'].': название до 60, текст до 160', mb_strlen($card['title']) <= 60 && mb_strlen($card['text']) <= 160, true);
	Check::same($card['code'].': ссылка относительная, на свой модуль', str_starts_with($card['url'], '/shef/toolsaicrm/?section='), true);
	Check::same($card['code'].': значок — латиница', (bool)preg_match('/^[a-z]{1,20}$/', $card['icon']), true);
	Check::same($card['code'].': sort — число', is_int($card['sort']), true);
}
Check::same('ссылки на разделы', array_column($cards, 'url', 'code'), [
	'analysis_profiles' => '/shef/toolsaicrm/?section=profiles',
	'analysis_prompts' => '/shef/toolsaicrm/?section=prompts',
	'manager_scores' => '/shef/toolsaicrm/?section=stats_managers',
]);
Check::same('не больше 6 карточек одного соседа', count($cards) <= 6, true);
Check::same('в текстах нет цен и названия модели', preg_match('/DeepSeek|₽|руб|\$|BitrixGPT/iu', json_encode($cards, JSON_UNESCAPED_UNICODE)), 0);

Check::group('обработчик');
$result = Handler::handle(new CardsEvent('shef.toolsai', 'OnRocketCardsCollect', ['userId' => 0]));
Check::same('нет сотрудника — SUCCESS и пусто', [$result->getType(), $result->getParameters(), $result->getModuleId()], [EventResult::SUCCESS, ['cards' => []], 'shef.toolsaicrm']);
$result = Handler::handle(new CardsEvent('shef.toolsai', 'OnRocketCardsCollect', ['userId' => 7]));
Check::same('сбой проверки прав (нет ядра) — пусто, не исключение', $result->getParameters(), ['cards' => []]);

Check::finish();
