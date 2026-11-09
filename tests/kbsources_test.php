<?php declare(strict_types=1);

/**
 * Справка модуля в «Базе знаний»: ответ на shef.toolsaikb:OnKbSourcesCollect.
 * Нет каталога kb/guide — пусто, не ошибка; есть — один пакет только на чтение.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Shef\ToolsAiCrm\Internals\Integration\Shef\ToolsAiKb\EventHandler\OnKbSourcesCollectEventHandler as Handler;
use Shef\ToolsAiCrm\Internals\Integration\Shef\ToolsAiKb\KbImport;

$tmp = sys_get_temp_dir().'/toolsaicrm-kb-'.bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);

Check::group('пакет справки');
Check::same('код и название', [Handler::CODE, Handler::TITLE], ['shef.toolsaicrm:guide', 'AI: Автооценка CRM']);
Check::same('нет каталога — пусто', Handler::sources($tmp), []);
mkdir($tmp.'/kb/guide', 0777, true);
$sources = Handler::sources($tmp);
Check::same('есть каталог — один пакет', [count($sources), $sources[0]['code'], $sources[0]['readOnly']], [1, 'shef.toolsaicrm:guide', true]);
Check::same('каталог — kb/guide модуля', $sources[0]['dir'], realpath($tmp.'/kb/guide'));
Check::same('каталог — не файл', (function() use($tmp): array
{
	rmdir($tmp.'/kb/guide');
	file_put_contents($tmp.'/kb/guide', 'x');
	$r = Handler::sources($tmp);
	unlink($tmp.'/kb/guide');

	return $r;
})(), []);

Check::group('обработчик событий');
$result = Handler::handle(new Event('shef.toolsaikb', 'OnKbSourcesCollect'));
Check::same('SUCCESS от имени модуля, ключ sources', [$result->getType(), $result->getModuleId(), array_keys($result->getParameters())], [EventResult::SUCCESS, 'shef.toolsaicrm', ['sources']]);
Check::same('каталог внутри этого модуля: корень — на 7 уровней выше файла обработчика', is_dir(dirname((new ReflectionClass(Handler::class))->getFileName(), 7).'/lib'), true);

Check::group('импорт');
Check::same('нет shef.toolsaikb — ничего, не исключение', KbImport::run(), null);

rmdir($tmp.'/kb');
rmdir($tmp);
Check::finish();
