<?php declare(strict_types=1);

/**
 * «Последние проверки агента» по типам (1.24.7, Analysis\LastChecks):
 * порядок вкладок, выбранная вкладка, адрес карточки по типу; блок ушёл из
 * «Расхода и включения» в «Авто-анализ CRM».
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiCrm\Analysis\LastChecks;

Check::group('вкладки');

Check::same('сделки, лиды, смарт-процессы по номеру; без повторов', LastChecks::order([1040, 1, 2, 128, 2]), [2, 1, 128, 1040]);
Check::same('неподдерживаемый тип отброшен', LastChecks::order([0, 3, 7, 1]), [1]);
Check::same('нет типов — пусто', LastChecks::order([]), []);
Check::same('выбран тип из запроса', LastChecks::active('128', [2, 1, 128]), 128);
Check::same('чужой тип — первая вкладка', LastChecks::active('999', [2, 1]), 2);
Check::same('мусор — первая вкладка', LastChecks::active(['x'], [1]), 1);
Check::same('вкладок нет — null', LastChecks::active('2', []), null);

Check::group('адрес карточки');

Check::same('сделка', LastChecks::fallbackUrl(2, 5), '/crm/deal/details/5/');
Check::same('лид', LastChecks::fallbackUrl(1, 7), '/crm/lead/details/7/');
Check::same('смарт-процесс', LastChecks::fallbackUrl(1032, 15), '/crm/type/1032/details/15/');
Check::same('роутер ядра главнее', LastChecks::url(2, 5, static fn(int $t, int $i): string => '/crm/deal/details/'.$i.'/?x'), '/crm/deal/details/5/?x');
Check::same('роутер вернул null — запасной', LastChecks::url(1, 7, static fn(): ?string => null), '/crm/lead/details/7/');
Check::same('роутер упал — запасной', LastChecks::url(128, 3, static function(): string { throw new \RuntimeException('x'); }), '/crm/type/128/details/3/');

Check::group('где блок');


Check::group('«Что сделано» без повтора «Почему» (1.24.8)');

$why = 'Пропуск: сделка в работе — последняя активность менее суток назад (06.10), порог тишины в профиле — 3 дн.';
Check::same('ничего + та же причина — просто «ничего»', LastChecks::action('ничего: '.$why, $why), 'ничего');
Check::same('ничего + пропуск — «ничего»', LastChecks::action('ничего: пропуск: сделка в работе', $why), 'ничего');
Check::same('ничего + своя причина — остаётся', LastChecks::action('ничего: дело уже стоит', 'Риск 40%'), 'ничего: дело уже стоит');
Check::same('сделанное — как есть', LastChecks::action('дело менеджеру до 08.10', $why), 'дело менеджеру до 08.10');

Check::finish();
