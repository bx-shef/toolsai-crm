<?php declare(strict_types=1);

/**
 * Единая история записи (1.45.0, #34): касания -> лента по времени с потолком.
 */

$root = dirname(__DIR__);
require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiCrm\Analysis\Timeline as T;

date_default_timezone_set('Europe/Minsk');
$at = static fn(string $d): int => (int)strtotime($d);

Check::group('касание');
Check::same('без времени или неизвестный вид — мимо', [T::touch(['kind' => 'call']), T::touch(['kind' => 'fax', 'at' => 5])], [null, null]);
Check::same('оценка в пределах 0–100, мусорное направление — пусто', [T::touch(['kind' => 'call', 'at' => 1, 'score' => 140])['score'], T::touch(['kind' => 'call', 'at' => 1, 'direction' => 'x'])['direction']], [100, '']);
Check::same('теги и пробелы вычищены', T::touch(['kind' => 'comment', 'at' => 1, 'note' => "<b>Клиент</b>\n  перезвонит"])['note'], 'Клиент перезвонит');

Check::group('строки');
Check::same('длительность', [T::duration(45), T::duration(200), T::duration(3600)], ['45 с', '3 мин 20 с', '60 мин']);
$call = T::touch(['kind' => 'call', 'at' => $at('2026-10-12 14:05'), 'direction' => 'in', 'duration' => 200, 'summary' => 'Клиент просит КП на 20 стульев', 'score' => 70, 'failed' => ['Выявил бюджет', 'Назначил следующий шаг', 'Резюмировал', 'Попрощался']]);
Check::same('звонок подробно', T::line($call, 2), '12.10.2026 14:05 · звонок входящий, 3 мин 20 с. Резюме: Клиент просит КП на 20 стульев. Оценка по скрипту: 70%, не выполнено: Выявил бюджет; Назначил следующий шаг; Резюмировал.');
Check::same('звонок коротко — без пунктов', T::line($call, 1), '12.10.2026 14:05 · звонок входящий, 3 мин 20 с. Резюме: Клиент просит КП на 20 стульев. Оценка по скрипту: 70%.');
Check::same('звонок заголовком', T::line($call, 0), '12.10.2026 14:05 · звонок входящий, 3 мин 20 с.');
Check::same('нет резюме — так и пишем', T::line(T::touch(['kind' => 'call', 'at' => $at('2026-10-12 10:00'), 'direction' => 'out']), 2), '12.10.2026 10:00 · звонок исходящий. Резюме нет.');
Check::same('нет резюме, есть расшифровка — её начало (#271)', T::line(T::touch(['kind' => 'call', 'at' => $at('2026-10-12 10:00'), 'direction' => 'in', 'transcript' => 'Менеджер: Да, слушаю. Клиент: Нужны водонагреватели']), 2), '12.10.2026 10:00 · звонок входящий. Резюме нет, начало расшифровки: Менеджер: Да, слушаю. Клиент: Нужны водонагреватели.');
Check::same('расшифровка из RESULT задания TYPE_ID 1', [\Shef\ToolsAiCrm\Deal\CallInsights::parseTranscript('{"transcription":"Алло"}'), \Shef\ToolsAiCrm\Deal\CallInsights::parseTranscript('мусор')], ['Алло', '']);
Check::same('письмо без разбора', T::line(T::touch(['kind' => 'email', 'at' => $at('2026-10-12 10:00'), 'direction' => 'in', 'subject' => 'Заказ']), 2), '12.10.2026 10:00 · письмо от клиента «Заказ». Сути нет.');
Check::same('чат с оценкой', T::line(T::touch(['kind' => 'chat', 'at' => $at('2026-10-12 10:00'), 'summary' => 'Спросил о доставке', 'score' => 90]), 2), '12.10.2026 10:00 · чат. Суть: Спросил о доставке. Оценка по скрипту: 90%.');
Check::same('стадия', T::line(T::touch(['kind' => 'stage', 'at' => $at('2026-10-12 10:00'), 'subject' => 'Счёт выставлен']), 2), '12.10.2026 10:00 · стадия → «Счёт выставлен».');
Check::same('длинное содержание обрезано', mb_strlen(T::line(T::touch(['kind' => 'comment', 'at' => 1, 'note' => str_repeat('а', 1000)]), 1)) < 200, true);

Check::group('лента');
Check::same('пусто — пусто', T::render([]), '');
$touches = [
	['kind' => 'email', 'at' => $at('2026-10-10 09:00'), 'direction' => 'in', 'subject' => 'Запрос', 'summary' => 'Нужны стулья'],
	['kind' => 'call', 'at' => $at('2026-10-12 14:05'), 'direction' => 'out', 'summary' => 'Обсудили КП'],
	['kind' => 'stage', 'at' => $at('2026-10-11 12:00'), 'subject' => 'КП'],
];
$lines = explode("\n", T::render($touches));
Check::same('по времени: от старого к новому', array_map(static fn(string $l): string => substr($l, 0, 16), $lines), ['10.10.2026 09:00', '11.10.2026 12:00', '12.10.2026 14:05']);

$many = [];
for($i = 0; $i < 60; $i++)
{
	$many[] = ['kind' => $i % 2 ? 'call' : 'email', 'at' => $at('2026-01-01') + $i * 3600, 'direction' => 'in', 'summary' => str_repeat('содержание ', 30)];
}
$text = T::render($many, 3000);
Check::same('потолок по символам соблюдён', mb_strlen($text) <= 3000 + 200, true);
Check::same('не вошедшее — счётом в начале', str_starts_with($text, 'Ранее ещё '), true);
$rendered = explode("\n", $text);
$last = end($rendered);
Check::same('свежее — подробно (с содержанием)', str_contains($last, 'Резюме: содержание') || str_contains($last, 'Суть: содержание'), true);
Check::same('ничего не выдумано: строк не больше, чем касаний', count($rendered) - 1 <= 60, true);

Check::group('в сводке анализа');
use Shef\ToolsAiCrm\Deal\DealFacts;
use Shef\ToolsAiCrm\Analysis\Prompt\PromptBuilder;
use Shef\ToolsAiCrm\Analysis\Prompt\PromptTemplate;
use Shef\ToolsAiCrm\Analysis\RecordHistory;
$facts = static fn(string $timeline): DealFacts => new DealFacts(dealId: 15, title: 'Стулья', stage: 'КП', opportunity: 0.0, currency: 'BYN', daysSinceCreated: 3, daysSinceLastActivity: 1, stageRollbacks: 0, callsTotal: 1, callsIncoming: 0, outgoingWithoutAnswer: 1, recentNotes: ['01.10.2026, Звонок: старое'], callNotes: ['старый звонок'], timeline: $timeline);
$withTimeline = $facts('12.10.2026 14:05 · звонок исходящий. Резюме нет.')->toPromptText();
Check::same('есть лента — она вместо блоков дел и звонков, счётчики на месте', [str_contains($withTimeline, 'История записи'), str_contains($withTimeline, 'Последние дела'), str_contains($withTimeline, 'Последние звонки'), str_contains($withTimeline, 'Звонков всего: 1')], [true, false, false, true]);
Check::same('ленты нет — сводка прежняя', [str_contains($facts('')->toPromptText(), 'Последние дела'), str_contains($facts('')->toPromptText(), 'История записи')], [true, false]);
$own = new PromptTemplate('deal', 'Система', 'Лента: {лента}', [], false, 2);
Check::same('{лента} в своём промпте', str_starts_with(PromptBuilder::build($own, null, 'СВОДКА', '', 'сделка', 'ЛЕНТА')['user'], 'Лента: ЛЕНТА'), true);
Check::same('история наружу: касаний нет — так и пишем', RecordHistory::text('Лид', 7, 'Окна', ''), "Лид #7: Окна\nКасаний пока нет.");

Check::finish();
