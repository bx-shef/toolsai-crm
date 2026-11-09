<?php declare(strict_types=1);

/**
 * Чаты открытых линий в анализе сделки: чистая логика без сети.
 *
 * Что держит:
 *
 * * текст переписки (Chat\Transcript): роль автора (клиент — коннектор, бот,
 *   автоответ по классу сообщения — даже от имени оператора, остальные —
 *   менеджер), BB-коды и HTML сняты, неизвестные квадратные скобки — нет,
 *   имя клиента не уходит, файлы и вложения отмечены, пустое — прочь; нечего
 *   оценивать без реплик менеджера или клиента (автоответ — не менеджер);
 *   длинный чат — начало и конец, середина пропущена с отметкой;
 * * оценка (Chat\ChatScore): критерии из «сути» скрипта, процент как у CRM
 *   (null не в счёт, ни одного оценённого — нет процента), невыполненные,
 *   текст комментария, запись критериев читается Stats\ScoreResult;
 * * сделка важнее лида (Chat\DialogSource::pickOwner).
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiCrm\Chat\ChatScore;
use Shef\ToolsAiCrm\Chat\DialogSource;
use Shef\ToolsAiCrm\Chat\Transcript;
use Shef\ToolsAiCrm\Stats\ScoreResult;

Check::group('роль автора');

Check::same('пользователь коннектора — клиент', Transcript::role('imconnector'), Transcript::ROLE_CLIENT);
Check::same('чат-бот — бот', Transcript::role('bot'), Transcript::ROLE_BOT);
Check::same('сотрудник — менеджер', Transcript::role(''), Transcript::ROLE_EMPLOYEE);
Check::same(
	'автоответ при закрытии подписан оператором, но по классу — автоответ',
	Transcript::role('', 'bx-messenger-content-item-ol-output bx-messenger-content-item-vote'),
	Transcript::ROLE_AUTO
);
Check::same('«диалог закрыт» (ol-end) — тоже автоответ', Transcript::role('', 'bx-messenger-content-item-ol-end'), Transcript::ROLE_AUTO);
Check::same('скрытое сообщение оператора (тихий режим) — скрытое', Transcript::role('', 'bx-messenger-content-item-system'), Transcript::ROLE_HIDDEN);
Check::same('другой класс сообщения — не автоответ', Transcript::role('', 'bx-messenger-content-item-vote'), Transcript::ROLE_EMPLOYEE);

Check::group('текст сообщения');

Check::same(
	'BB-коды мессенджера сняты, текст внутри остался',
	Transcript::cleanText('[USER=15]Иван Петров[/USER], [b]цена[/b] на [URL=https://shop.by/x]сапун[/URL][br]ок'),
	'Иван Петров, цена на сапун ок'
);
Check::same('неизвестные скобки — не разметка', Transcript::cleanText('Заказ [№ 15] готов'), 'Заказ [№ 15] готов');
Check::same('HTML-сущности раскрыты, теги и лишние пробелы прочь', Transcript::cleanText("&quot;Да&quot;  <i>конечно</i>\n\n  &amp; сразу"), '"Да" конечно & сразу');

Check::group('переписка для модели');

$messages = [
	['role' => Transcript::ROLE_BOT, 'author' => 'Бот линии', 'time' => '03.10 12:00', 'text' => 'Здравствуйте! Оператор скоро ответит.'],
	['role' => Transcript::ROLE_CLIENT, 'author' => 'Анна Клиентова', 'time' => '03.10 12:01', 'text' => 'Нужен сапун на [b]Husqvarna 135[/b]'],
	['role' => Transcript::ROLE_EMPLOYEE, 'author' => 'Иван Петров', 'time' => '03.10 12:02', 'text' => 'Добрый день, есть в наличии', 'files' => 2],
	['role' => Transcript::ROLE_CLIENT, 'author' => 'Анна Клиентова', 'time' => '', 'text' => '', 'files' => 1],
	['role' => Transcript::ROLE_CLIENT, 'author' => 'Анна Клиентова', 'time' => '03.10 12:05', 'text' => '   '],
	['role' => Transcript::ROLE_EMPLOYEE, 'author' => 'Иван Петров', 'time' => '03.10 12:06', 'text' => 'Карточка товара', 'attach' => true],
	['role' => Transcript::ROLE_HIDDEN, 'author' => 'Иван Петров', 'time' => '03.10 12:07', 'text' => 'клиент мутный, цену не давать'],
	['role' => Transcript::ROLE_AUTO, 'author' => 'Иван Петров', 'time' => '03.10 13:00', 'text' => 'Диалог закрыт. Оцените работу оператора.'],
];
$transcript = Transcript::build($messages);
Check::same('строки: время, роль, имя сотрудника; имени клиента нет; файлы отмечены; пустое и скрытое — прочь', $transcript->text, implode("\n", [
	'[03.10 12:00] Бот (Бот линии): Здравствуйте! Оператор скоро ответит.',
	'[03.10 12:01] Клиент: Нужен сапун на Husqvarna 135',
	'[03.10 12:02] Менеджер (Иван Петров): Добрый день, есть в наличии [файлов: 2]',
	'Клиент: [файл]',
	'[03.10 12:06] Менеджер (Иван Петров): Карточка товара [вложение]',
	'[03.10 13:00] Автоответ: Диалог закрыт. Оцените работу оператора.',
]));
Check::same('счёт реплик: клиент, менеджер, бот и автоответ', [$transcript->clientCount, $transcript->employeeCount, $transcript->botCount], [2, 2, 2]);
Check::same('есть что оценивать', $transcript->getSkipReason(), null);
Check::same('имя клиента в текст не попало', str_contains($transcript->text, 'Анна'), false);

$onlyAuto = Transcript::build([
	['role' => Transcript::ROLE_CLIENT, 'text' => 'Есть кто?'],
	['role' => Transcript::ROLE_AUTO, 'author' => 'Иван Петров', 'text' => 'Диалог закрыт'],
	['role' => Transcript::ROLE_BOT, 'text' => 'Нерабочее время'],
]);
Check::same('ответили только автоответ и бот — менеджера нет', $onlyAuto->getSkipReason(), 'нет реплик менеджера');
Check::same('клиент молчал', Transcript::build([['role' => Transcript::ROLE_EMPLOYEE, 'text' => 'Добрый день']])->getSkipReason(), 'нет реплик клиента');
Check::same('неизвестная роль — менеджер, а не потеря реплики', Transcript::build([['role' => 'x', 'text' => 'т']])->employeeCount, 1);

$long = [];
for($i = 1; $i <= 300; $i++)
{
	$long[] = ['role' => $i % 2 ? Transcript::ROLE_CLIENT : Transcript::ROLE_EMPLOYEE, 'text' => 'реплика '.$i.' '.str_repeat('ж', 40)];
}
$cut = Transcript::build($long, 2000);
$lines = explode("\n", $cut->text);
Check::same('длинный чат: начало и конец на месте', [str_contains($lines[0], 'реплика 1 '), str_contains(end($lines), 'реплика 300 ')], [true, true]);
Check::same('длинный чат: середина пропущена с отметкой', [$cut->skipped > 0, str_contains($cut->text, '… пропущено реплик: '.$cut->skipped.' …')], [true, true]);
Check::same('длинный чат: в потолке', mb_strlen($cut->text) <= 2000 + 50, true);
Check::same('потолок не меньше 1000: «0» не обнуляет текст', Transcript::build([['role' => Transcript::ROLE_CLIENT, 'text' => 'коротко']], 0)->text, 'Клиент: коротко');
$huge = Transcript::build([['role' => Transcript::ROLE_CLIENT, 'text' => str_repeat('ы', 5000)], ['role' => Transcript::ROLE_EMPLOYEE, 'text' => 'ok']], 1000);
Check::same('одна огромная реплика — её начало, а не пусто', [str_starts_with($huge->text, 'Клиент: ыы'), mb_strlen($huge->text) < 1200], [true, true]);

Check::group('оценка: критерии, процент, комментарий');

Check::same('критерии из сути скрипта: перевод строки любого вида, пустые и повторы — прочь', ChatScore::criteriaFromGist("Поздоровался\r\n\r\n  Выяснил   потребность \nПоздоровался\rНазвал цену"), ['Поздоровался', 'Выяснил потребность', 'Назвал цену']);
Check::same('суть не строка — пусто', ChatScore::criteriaFromGist(null), []);

$criteria = [
	['criterion' => 'Поздоровался', 'status' => true, 'explanation' => ''],
	['criterion' => 'Назвал цену', 'status' => false, 'explanation' => ''],
	['criterion' => 'Интонация', 'status' => null, 'explanation' => ''],
	['criterion' => 'Предложил доставку', 'status' => true, 'explanation' => ''],
];
Check::same('процент: выполненные от оценённых, null не в счёт', ChatScore::percent($criteria), 67);
Check::same('ни одного оценённого — процента нет, а не деление на ноль', ChatScore::percent([['criterion' => 'а', 'status' => null]]), null);
Check::same('всё провалено — 0, а не null', ChatScore::percent([['criterion' => 'а', 'status' => false]]), 0);
Check::same('невыполненные — по порядку, без повторов', ChatScore::failed([...$criteria, ['criterion' => 'Назвал цену', 'status' => false]]), ['Назвал цену']);

$scoring = ['call_review' => ['criteria' => $criteria], 'overall_summary' => 'Ответил быстро.', 'recommendations' => 'Называть цену сразу.'];
Check::same('комментарий в таймлайн', ChatScore::formatComment('Продажа запчастей', $scoring), implode("\n", [
	'Оценка переписки по скрипту «Продажа запчастей»: 67%.',
	'Не выполнено: Назвал цену.',
	'Итог: Ответил быстро.',
	'Что сделать иначе: Называть цену сразу.',
]));
Check::same(
	'всё выполнено и без итога',
	ChatScore::formatComment('', ['call_review' => ['criteria' => [['criterion' => 'а', 'status' => true, 'explanation' => '']]], 'overall_summary' => '', 'recommendations' => '']),
	"Оценка переписки по скрипту без названия: 100%.\nВсе оценённые пункты выполнены."
);
Check::same(
	'не оценена — так и сказано, без списка',
	ChatScore::formatComment('С', ['call_review' => ['criteria' => [['criterion' => 'а', 'status' => null, 'explanation' => '']]], 'overall_summary' => '', 'recommendations' => '']),
	'Оценка переписки по скрипту «С»: не оценена — ни один пункт нельзя проверить по переписке.'
);
Check::same('длинный комментарий обрезан', mb_strlen(ChatScore::formatComment('С', ['call_review' => ['criteria' => $criteria], 'overall_summary' => str_repeat('я', 5000), 'recommendations' => ''])), ChatScore::MAX_COMMENT_LENGTH);

$stored = ChatScore::toStored($scoring);
Check::same('запись критериев читает страница статистики: только bool', ScoreResult::parseCriteria($stored), [
	['criterion' => 'Поздоровался', 'status' => true],
	['criterion' => 'Назвал цену', 'status' => false],
	['criterion' => 'Предложил доставку', 'status' => true],
]);


Check::group('сделка или лид');

Check::same('сделка важнее лида', DialogSource::pickOwner([
	['OWNER_TYPE_ID' => '1', 'OWNER_ID' => '900'],
	['OWNER_TYPE_ID' => '2', 'OWNER_ID' => '15'],
	['OWNER_TYPE_ID' => '3', 'OWNER_ID' => '77'],
]), [DialogSource::OWNER_DEAL, 15]);
Check::same('из двух сделок — новее', DialogSource::pickOwner([['OWNER_TYPE_ID' => 2, 'OWNER_ID' => 15], ['OWNER_TYPE_ID' => 2, 'OWNER_ID' => 16]]), [DialogSource::OWNER_DEAL, 16]);
Check::same('только лид', DialogSource::pickOwner([['OWNER_TYPE_ID' => 1, 'OWNER_ID' => 5]]), [DialogSource::OWNER_LEAD, 5]);
Check::same('только контакт — некуда писать', DialogSource::pickOwner([['OWNER_TYPE_ID' => 3, 'OWNER_ID' => 5], ['OWNER_TYPE_ID' => 2, 'OWNER_ID' => 0]]), null);


Check::finish();
