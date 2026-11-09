<?php declare(strict_types=1);

/**
 * Письма в анализе сделки (читатели и тексты): чистая логика без сети.
 *
 * Что держит:
 *
 * * тело письма -> текст (Email\EmailText): HTML без разметки, стилей и
 *   сущностей; цитата прошлой переписки режется (blockquote, в том числе
 *   вложенный и незакрытый, блоки цитат Gmail/Outlook, «-----Original
 *   Message-----», «On … wrote:», «… пишет:», шапка «От кого:/Кому:» как у
 *   Битрикса, адрес с двоеточием как у Яндекса, строки «>»), но письмо,
 *   которое начинается с заголовка цитаты, не обнуляется; подпись («-- »,
 *   «Отправлено с iPhone», «С уважением» после текста); потолок длины; текст
 *   и BB-код;
 * * служебные письма без модели: робот почты, no-reply, автоответ,
 *   недоставка; обычное письмо — нет;
 * * скорость ответа (Email\ReplyClock): первый ответ не раньше письма,
 *   решение по группе сделки (пороги, отметки гасят повтор, срок от самого
 *   старого), кто ждёт ответа в сводке сделки (входящий звонок — не ответ),
 *   длительность;
 * * тексты в CRM (Email\EmailComment): резюме, оценка (процент как у звонка),
 *   дела менеджеру и старшему, строка сводки; критерии оценки письма читает
 *   статистика;
 * * сводка анализа сделки с письмами (Deal\DealFacts).
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiCrm\Deal\DealFacts;
use Shef\ToolsAiCrm\Email\EmailComment;
use Shef\ToolsAiCrm\Email\EmailText;
use Shef\ToolsAiCrm\Email\ReplyClock;
use Shef\ToolsAiCrm\Stats\ScoreResult;

date_default_timezone_set('Europe/Minsk');

$now = mktime(12, 0, 0, 10, 3, 2026);

Check::group('HTML -> текст');

Check::same(
	'теги, стили, комментарии и сущности сняты; абзацы и <br> — строки',
	EmailText::prepare('<html><head><style>p{color:red}</style><title>t</title></head><body><!-- x --><p>Добрый&nbsp;день!</p><div>Нужно <b>20</b> стульев &amp; 2&nbsp;стола.<br>Срок &laquo;до пятницы&raquo;</div></body></html>', EmailText::TYPE_HTML),
	"Добрый день!\nНужно 20 стульев & 2 стола.\nСрок «до пятницы»"
);
Check::same(
	'таблица — ячейки через пробел',
	EmailText::prepare('<table><tr><td>Стул</td><td>20 шт</td></tr><tr><td>Стол</td><td>2 шт</td></tr></table>', EmailText::TYPE_HTML),
	"Стул 20 шт\nСтол 2 шт"
);

Check::group('цитата прошлой переписки');

Check::same(
	'blockquote (так цитирует Битрикс) — прочь вместе с шапкой «От кого / Кому»',
	EmailText::prepare('<p>Да, пришлите счёт.</p></br></br>От кого: Иван &lt;ivan@shop.by&gt;</br>Кому: anna@client.by</br>Дата: 03.10.2026</br>Тема: КП</br><blockquote style="margin:0">Высылаем КП на стулья</blockquote>', EmailText::TYPE_HTML),
	'Да, пришлите счёт.'
);
Check::same(
	'вложенный blockquote — тоже',
	EmailText::prepare('Ответ<blockquote>старое<blockquote>совсем старое</blockquote>ещё</blockquote>после', EmailText::TYPE_HTML),
	"Ответ\nпосле"
);
Check::same('незакрытый blockquote — всё после него цитата', EmailText::prepare('Ответ<blockquote>старое без конца', EmailText::TYPE_HTML), 'Ответ');
Check::same(
	'блок цитаты Gmail — всё после него прочь',
	EmailText::prepare('<div dir="ltr">Подойдёт, берём.</div><br><div class="gmail_quote"><div class="gmail_attr">пн, 3 окт. 2026 г. в 12:00, Иван:</div>Цена 100</div>', EmailText::TYPE_HTML),
	'Подойдёт, берём.'
);
Check::same(
	'Outlook: divRplyFwdMsg',
	EmailText::prepare('<p>Спасибо</p><hr><div id="divRplyFwdMsg"><b>From:</b> Ivan<br><b>Sent:</b> Monday</div><p>old</p>', EmailText::TYPE_HTML),
	'Спасибо'
);
Check::same(
	'«-----Original Message-----» в тексте',
	EmailText::prepare("Хорошо, ждём.\n\n-----Original Message-----\nFrom: ivan@shop.by\nЦена 100", EmailText::TYPE_PLAIN),
	'Хорошо, ждём.'
);
Check::same(
	'«On … wrote:»',
	EmailText::prepare("Ok, thanks\n\nOn Mon, 3 Oct 2026 at 12:00, Ivan <ivan@shop.by> wrote:\n> price 100", EmailText::TYPE_PLAIN),
	'Ok, thanks'
);
Check::same(
	'«… пишет:» с датой',
	EmailText::prepare("Беру два.\n\n3 окт. 2026 г., в 12:00, Иван Петров <ivan@shop.by> пишет:\nЕсть в наличии", EmailText::TYPE_PLAIN),
	'Беру два.'
);
Check::same(
	'Яндекс: «03.10.2026, 12:00, "Иван" <ivan@shop.by>:»',
	EmailText::prepare("Согласен.\n\n03.10.2026, 12:00, \"Иван\" <ivan@shop.by>:\nЦена 100", EmailText::TYPE_PLAIN),
	'Согласен.'
);
Check::same(
	'«От: / Отправлено:» — шапка Outlook на русском',
	EmailText::prepare("Принято\nОт: Иван Петров\nОтправлено: 3 октября 2026 г. 12:00\nКому: Анна\nТема: КП", EmailText::TYPE_PLAIN),
	'Принято'
);
Check::same('строки «>» — прочь, остальное на месте', EmailText::prepare("> старое\nНовое\n>> ещё старее\nи ещё", EmailText::TYPE_PLAIN), "Новое\nи ещё");
Check::same('«От:» без шапки следом — обычная строка', EmailText::prepare("Привет\nОт: склада ответа нет, жду\nСпасибо", EmailText::TYPE_PLAIN), "Привет\nОт: склада ответа нет, жду\nСпасибо");
Check::same('«пишет:» без даты и адреса — не заголовок', EmailText::cutQuote("Коллега пишет:\nнужно 5 штук"), "Коллега пишет:\nнужно 5 штук");
Check::same(
	'письмо целиком из цитаты (заголовок в первой строке) — не обнуляется',
	EmailText::prepare("-----Original Message-----\nЦена 100", EmailText::TYPE_PLAIN),
	"-----Original Message-----\nЦена 100"
);

Check::group('подпись и длина');

Check::same('«-- » — дальше подпись', EmailText::prepare("Пришлите счёт\n-- \nИван, +375 29 000", EmailText::TYPE_PLAIN), 'Пришлите счёт');
Check::same('«Отправлено с iPhone»', EmailText::prepare("Ок\n\nОтправлено с iPhone", EmailText::TYPE_PLAIN), 'Ок');
Check::same('«С уважением» после текста — дальше подпись', EmailText::prepare("Жду КП до пятницы.\nС уважением,\nАнна, ООО Ромашка", EmailText::TYPE_PLAIN), 'Жду КП до пятницы.');
Check::same('«С уважением» первой строкой — не режем в пустоту', EmailText::cutSignature("С уважением к вашему времени, коротко: нужен счёт"), 'С уважением к вашему времени, коротко: нужен счёт');
$long = EmailText::prepare(str_repeat('слово ', 3000), EmailText::TYPE_PLAIN, 1000);
Check::same('потолок длины: обрезано с «…»', [mb_strlen($long), str_ends_with($long, '…')], [1000, true]);
Check::same('потолок не меньше 100', mb_strlen(EmailText::limit(str_repeat('я', 500), 0)), 100);
Check::same('BB-код: теги прочь, цитата прочь', EmailText::prepare('[b]Нужно[/b] 5 шт[br][quote]старое[/quote]Спасибо', EmailText::TYPE_BBCODE), "Нужно 5 шт\n\nСпасибо");
Check::same('текст: переводы строк и пробелы нормализованы', EmailText::prepare("  Раз  \r\n\r\n\r\n\r\nДва\u{A0}три ", EmailText::TYPE_PLAIN), "Раз\n\nДва три");

Check::group('служебные письма без модели');

Check::same('MAILER-DAEMON', EmailText::serviceReason('Mail Delivery System <MAILER-DAEMON@mx.shop.by>', 'Undelivered Mail'), 'служебный адрес отправителя');
Check::same('no-reply', EmailText::serviceReason('noreply@service.by', 'Ваш заказ'), 'служебный адрес отправителя');
Check::same('автоответ по теме', EmailText::serviceReason('anna@client.by', 'Автоответ: Re: КП'), 'автоответ или уведомление почты');
Check::same('Out of Office', EmailText::serviceReason('Anna <anna@client.by>', 'Out of Office: КП'), 'автоответ или уведомление почты');
Check::same('«в отпуске» в теме', EmailText::serviceReason('anna@client.by', 'В отпуске до 10.10'), 'автоответ или уведомление почты');
Check::same('обычное письмо клиента — не служебное', EmailText::serviceReason('Анна <anna@client.by>', 'Re: КП на стулья'), null);
Check::same('reply в имени ящика — не no-reply', EmailText::serviceReason('replymanager@client.by', 'Заказ'), null);


Check::group('скорость ответа');

Check::same('первый ответ не раньше письма', ReplyClock::firstReplyAfter(100, [50, 300, 200, 100]), 100);
Check::same('ответов после письма нет', ReplyClock::firstReplyAfter(100, [50, 99]), null);

$at = $now - 5 * 3600;
Check::same('ждёт 5 ч, порог 4 — дело менеджеру, старшему ещё нет', ReplyClock::decide([['at' => $at, 'managerNotified' => false, 'seniorNotified' => false]], $now, 4, 24), ['oldestAt' => $at, 'hours' => 5, 'manager' => true, 'senior' => false]);
Check::same('ровно порог — уже пора', ReplyClock::decide([['at' => $now - 4 * 3600, 'managerNotified' => false, 'seniorNotified' => false]], $now, 4, 24)['manager'], true);
Check::same('3 ч 59 мин — рано', ReplyClock::decide([['at' => $now - 4 * 3600 + 60, 'managerNotified' => false, 'seniorNotified' => false]], $now, 4, 24)['manager'], false);
Check::same(
	'группа сделки: срок от самого старого, отметка у любого письма гасит повтор',
	ReplyClock::decide([
		['at' => $now - 3600, 'managerNotified' => false, 'seniorNotified' => false],
		['at' => $now - 30 * 3600, 'managerNotified' => true, 'seniorNotified' => false],
	], $now, 4, 24),
	['oldestAt' => $now - 30 * 3600, 'hours' => 30, 'manager' => false, 'senior' => true]
);
Check::same('старшему уже ставили — не повторяем', ReplyClock::decide([['at' => $now - 50 * 3600, 'managerNotified' => true, 'seniorNotified' => true]], $now, 4, 24), ['oldestAt' => $now - 50 * 3600, 'hours' => 50, 'manager' => false, 'senior' => false]);
Check::same('ждущих нет — null', ReplyClock::decide([], $now, 4, 24), null);

$in = ReplyClock::DIRECTION_INCOMING;
$out = ReplyClock::DIRECTION_OUTGOING;
$email = ReplyClock::TYPE_EMAIL;
$callType = ReplyClock::TYPE_CALL;
Check::same('ответили после письма — не ждёт', ReplyClock::waitingSince([['type' => $email, 'direction' => $in, 'at' => 100], ['type' => $email, 'direction' => $out, 'at' => 200]]), null);
Check::same(
	'после последнего ответа два письма — ждёт с первого из них',
	ReplyClock::waitingSince([
		['type' => $email, 'direction' => $in, 'at' => 100],
		['type' => $callType, 'direction' => $out, 'at' => 200],
		['type' => $email, 'direction' => $in, 'at' => 400],
		['type' => $email, 'direction' => $in, 'at' => 300],
	]),
	300
);
Check::same('входящий звонок — не ответ', ReplyClock::waitingSince([['type' => $email, 'direction' => $in, 'at' => 100], ['type' => $callType, 'direction' => $in, 'at' => 200]]), 100);
Check::same('исходящий звонок — ответ', ReplyClock::isReply($callType, $out), true);
Check::same('дело «Сделать» (тип 6) — не ответ', ReplyClock::isReply(6, $out), false);
Check::same('писем клиента нет — не ждёт', ReplyClock::waitingSince([['type' => $callType, 'direction' => $in, 'at' => 100]]), null);
Check::same('часы — полные', ReplyClock::hours($now - 3 * 3600 - 3599, $now), 3);
Check::same('длительность', [ReplyClock::formatDuration(45 * 60), ReplyClock::formatDuration(2 * 3600 + 5 * 60), ReplyClock::formatDuration(76 * 3600), ReplyClock::formatDuration(-5)], ['45 мин', '2 ч 05 мин', '3 дн 4 ч', '0 мин']);

Check::group('тексты в CRM');

$normalized = ['summary' => 'Просит КП', 'wants' => 'КП', 'terms' => 'до пятницы'];

Check::same('резюме входящего в ленту', EmailComment::incoming('Re: КП', mktime(12, 30, 0, 10, 3, 2026), $normalized, ['Выслать КП', 'Уточнить срок']), implode("\n", [
	'AI: письмо клиента от 03.10.2026 12:30 «Re: КП».',
	'Что пишет: Просит КП',
	'Чего хочет: КП',
	'Сроки: до пятницы',
	'Дела менеджеру: «Выслать КП»; «Уточнить срок».',
]));
Check::same('без темы, без хотелок и сроков, без дел', EmailComment::incoming('', mktime(9, 0, 0, 10, 3, 2026), ['summary' => 'Спасибо, получили', 'wants' => '', 'terms' => '']), "AI: письмо клиента от 03.10.2026 09:00.\nЧто пишет: Спасибо, получили");

$scoring = [
	'call_review' => ['criteria' => [
		['criterion' => 'Ответил на вопрос', 'status' => true, 'explanation' => 'да'],
		['criterion' => 'Назвал цену', 'status' => false, 'explanation' => 'нет цены'],
		['criterion' => 'Вежливо', 'status' => null, 'explanation' => ''],
	]],
	'overall_summary' => 'Ответил, но без цены.',
	'recommendations' => 'Называть цену.',
];
Check::same('оценка письма в ленту: процент как у звонка, null не в счёт', EmailComment::review('КП', $scoring), implode("\n", [
	'AI-оценка письма менеджера «КП»: 50%.',
	'Не выполнено: Назвал цену.',
	'Итог: Ответил, но без цены.',
	'Что сделать иначе: Называть цену.',
]));
Check::same('ни одного оценённого — «не оценено»', EmailComment::review('', ['call_review' => ['criteria' => [['criterion' => 'а', 'status' => null, 'explanation' => '']]], 'overall_summary' => '', 'recommendations' => '']), 'AI-оценка письма менеджера: не оценено — ни один пункт нельзя проверить по письму.');
Check::same('длинный комментарий обрезан', mb_strlen(EmailComment::incoming('т', $now, ['summary' => str_repeat('я', 5000), 'wants' => '', 'terms' => ''])), EmailComment::MAX_LENGTH);

$stored = EmailComment::toStoredReview($scoring);
Check::same('критерии письма читает статистика (только bool)', ScoreResult::parseCriteria($stored), [['criterion' => 'Ответил на вопрос', 'status' => true], ['criterion' => 'Назвал цену', 'status' => false]]);

$mailAt = mktime(9, 15, 0, 10, 3, 2026);
Check::same('дело менеджеру', EmailComment::managerTodo('КП', $mailAt, $mailAt + 4 * 3600 + 20 * 60), [
	'subject' => 'Ответить клиенту на письмо от 03.10.2026 09:15',
	'description' => "Клиент ждёт ответа 4 ч 20 мин на письмо «КП». Ответьте письмом или позвоните.\nДело поставил AI-контроль скорости ответа: исходящего письма или звонка по сделке после письма клиента нет.",
]);
Check::same('дело старшему', EmailComment::seniorTodo('Иван Петров', 'КП', $mailAt, $mailAt + 26 * 3600, 'сделка «Стулья»'), [
	'subject' => 'Клиент без ответа 26 ч 00 мин: письмо от 03.10.2026 09:15',
	'description' => 'Менеджер Иван Петров не ответил на письмо клиента от 03.10.2026 09:15 «КП» (сделка «Стулья»). Проконтролировать ответ.',
]);
Check::same('строка сводки сделки', EmailComment::dealNote($mailAt, "Просит\n  КП"), '03.10.2026, письмо клиента: Просит КП');

// Запросы к модели по письмам (EmailAnalyzer) убраны в 1.67.0 вместе с агентом «Письма»:
// тексты писем теперь идут в сводку анализа записи (conversation_test.php).


Check::group('сводка анализа сделки');

$facts = new DealFacts(
	dealId: 15, title: 'Стулья', stage: 'КП', opportunity: 0.0, currency: 'BYN',
	daysSinceCreated: 10, daysSinceLastActivity: 5, stageRollbacks: 0,
	callsTotal: 0, callsIncoming: 0, outgoingWithoutAnswer: 0, recentNotes: [],
	emailNotes: ['03.10.2026, письмо клиента: Просит КП', '01.10.2026, письмо клиента: Спрашивает цену'],
	emailWaitingHours: 6,
);
$text = $facts->toPromptText();
Check::same('в сводке: ждёт ответа и резюме писем', [
	str_contains($text, 'Клиент ждёт ответа на письмо: 6 ч'),
	str_contains($text, "Последние письма клиента — резюме AI (от свежего к старому):\n1) 03.10.2026, письмо клиента: Просит КП\n2) 01.10.2026"),
], [true, true]);
Check::same('только письма — есть о чём рассуждать', $facts->isWorthAnalyzing(3), true);
$quiet = new DealFacts(dealId: 1, title: 'x', stage: 'y', opportunity: 0.0, currency: 'BYN', daysSinceCreated: 10, daysSinceLastActivity: 5, stageRollbacks: 0, callsTotal: 0, callsIncoming: 0, outgoingWithoutAnswer: 0, recentNotes: []);
Check::same('без писем — строк о письмах нет', [str_contains($quiet->toPromptText(), 'письм'), $quiet->isWorthAnalyzing(3)], [false, false]);

Check::finish();
