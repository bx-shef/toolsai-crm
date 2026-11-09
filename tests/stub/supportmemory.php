<?php declare(strict_types=1);

/**
 * Отдельный процесс для support_test.php: архив максимального размера из
 * несжимаемых данных собирается и уходит подставному транспорту под
 * memory_limit, заданным вызывающим (-d memory_limit=64M). Печатает «ok <пик МБ>».
 */

require_once __DIR__.'/autoload.php';
require_once __DIR__.'/fakes.php';

use Shef\ToolsAi\Http\Response;
use Shef\ToolsAi\Support\Archive;
use Shef\ToolsAi\Support\Sender;

$path = $argv[1];
$sources = [];
foreach(['a', 'b'] as $name)
{
	$sources['logs/'.$name.'.log'] = static fn(): string => random_bytes(Archive::MAX_FILE);
}
(new Archive($sources))->build($path, ['portal' => 'b24.test']);
gc_collect_cycles();

$transport = new FakeTransport();
$transport->responses = [new Response(200, '{"ok":true,"ticket":"S-261104-0009"}')];
$meta = ['product' => 'shef.toolsai', 'fp' => 'fp1', 'idempotency_key' => 'abcdef0123456789abcdef0123456789', 'key' => 'k'];
$answer = (new Sender($transport, 'https://license.test'))->attempt($meta, (string)file_get_contents($path));
@unlink($path);

echo ($answer['kind'] === 'ok' ? 'ok ' : 'fail ').round(memory_get_peak_usage(true) / 1048576), "\n";
