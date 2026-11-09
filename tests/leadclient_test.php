<?php declare(strict_types=1);

/**
 * 1.11.0: тип клиента лида и очистка полей «AI» у записи из лида.
 *
 * Что держит:
 *
 * * тип клиента лида — все сочетания компании, контакта, множественных
 *   контактов и «Названия компании»: новый/повторный и юр/физ;
 * * совпадение с профилем: «любой», точное, счёт заданных признаков;
 * * профиль лида: разбор, форма (неизвестное значение — ошибка; у
 *   не-лида признаки пусты; CLIENT_TYPES у лида не хранится);
 * * подбор профиля лида: точнее — главнее, «любой» последний, без
 *   признаков — только «любой»; сделки — как раньше;
 * * строка в сводке модели;
 * * очистка при конвертации: когда запись «из лида», какие поля чистить,
 *   что сбросить у созданного элемента; события зарегистрированы и
 *   снимаются установщиком.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

// Базовый тип поля ядра — только то, что нужно AckType (как в dealfields_test).
if(!class_exists(\Bitrix\Main\UserField\Types\StringType::class))
{
	eval(<<<'PHP'
	namespace Bitrix\Main\UserField\Types
	{
		abstract class BaseType {}
		class StringType extends BaseType {}
	}
	PHP);
}

use Shef\ToolsAiCrm\Analysis\ConversionCleaner;
use Shef\ToolsAiCrm\Analysis\EntityFields;
use Shef\ToolsAiCrm\Deal\DealFacts;
use Shef\ToolsAiCrm\Deal\LeadClientType;
use Shef\ToolsAiCrm\Deal\Profile;
use Shef\ToolsAiCrm\Deal\ProfilePicker;

Check::group('тип клиента лида: все сочетания');

foreach([0, 5] as $company)
{
	foreach([0, 7] as $contact)
	{
		foreach([[], [8, 9]] as $contacts)
		{
			foreach(['', 'ООО Ромашка'] as $title)
			{
				$type = LeadClientType::resolve($company, $contact, $contacts, $title);
				$repeat = $company > 0 || $contact > 0 || $contacts !== [];
				$legal = $company > 0 || $title !== '';
				Check::same(
					sprintf('компания %d, контакт %d, контакты [%s], название «%s»', $company, $contact, implode(',', $contacts), $title),
					[$type->repeat, $type->legal, $type->repeatCode(), $type->legalCode()],
					[$repeat, $legal, $repeat ? 'repeat' : 'new', $legal ? 'legal' : 'person'],
				);
			}
		}
	}
}
Check::same('название из пробелов — физлицо', LeadClientType::resolve(0, 0, [], "  \t")->legal, false);
Check::same('мусор в контактах не считается', LeadClientType::resolve(0, 0, [0, '', 'x', null], '')->repeat, false);
Check::same('контакт строкой считается', LeadClientType::resolve(0, 0, ['12'], '')->repeat, true);

Check::group('совпадение с профилем');

$newPerson = new LeadClientType(false, false);
$repeatLegal = new LeadClientType(true, true);
Check::same('любой/любой — 0', $newPerson->match('', ''), 0);
Check::same('новый/любой — 1', $newPerson->match('new', ''), 1);
Check::same('новый/физ — 2', $newPerson->match('new', 'person'), 2);
Check::same('повторный — не подходит новому', $newPerson->match('repeat', ''), null);
Check::same('юрлицо — не подходит физлицу', $newPerson->match('', 'legal'), null);
Check::same('повторный юрлицо — 2', $repeatLegal->match('repeat', 'legal'), 2);
Check::same('разбор: незнакомое — любой', [LeadClientType::parseRepeat('x'), LeadClientType::parseLegal(null), LeadClientType::parseRepeat('repeat'), LeadClientType::parseLegal('person')], ['', '', 'repeat', 'person']);

Check::group('профиль лида');

$lead = Profile::fromRow(['ENTITY_TYPE_ID' => 1, 'LEAD_REPEAT' => 'repeat', 'LEAD_LEGAL' => 'legal']);
Check::same('разбор', [$lead->leadRepeat, $lead->leadLegal, $lead->isAnyClient()], ['repeat', 'legal', false]);
$deal = Profile::fromRow(['ENTITY_TYPE_ID' => 2, 'LEAD_REPEAT' => 'repeat']);
Check::same('у сделки признаков лида нет', [$deal->leadRepeat, $deal->leadLegal, $deal->isAnyClient()], ['', '', true]);
Check::same('лид без признаков — любой, даже с CLIENT_TYPES', Profile::fromRow(['ENTITY_TYPE_ID' => 1, 'CLIENT_TYPES' => '2'])->isAnyClient(), true);
[$fields, $errors] = Profile::fromInput(['TITLE' => 'x', 'ENTITY_KEY' => '1:0', 'LEAD_REPEAT' => 'new', 'LEAD_LEGAL' => 'person', 'CLIENT_TYPES' => ['2']]);
Check::same('форма лида', [$fields['LEAD_REPEAT'], $fields['LEAD_LEGAL'], $fields['CLIENT_TYPES'], $errors], ['new', 'person', '', []]);
[$fields, $errors] = Profile::fromInput(['TITLE' => 'x', 'ENTITY_KEY' => '1:0', 'LEAD_REPEAT' => 'old']);
Check::same('форма: незнакомое значение — ошибка, а не молча «любой»', [$fields['LEAD_REPEAT'], $errors], ['', ['LEAD_REPEAT']]);
[$fields] = Profile::fromInput(['TITLE' => 'x', 'ENTITY_KEY' => '2:0', 'LEAD_REPEAT' => 'new', 'CLIENT_TYPES' => ['2']]);
Check::same('форма сделки: признаки лида пусты, тип клиента сделки — как был', [$fields['LEAD_REPEAT'], $fields['LEAD_LEGAL'], $fields['CLIENT_TYPES']], ['', '', '2']);

Check::group('подбор профиля лида');

$p = static fn(int $id, string $repeat, string $legal, int $sort = 100, bool $enabled = true): Profile => Profile::fromRow(['ID' => $id, 'IS_ENABLED' => $enabled ? 'Y' : 'N', 'SORT' => $sort, 'ENTITY_TYPE_ID' => 1, 'CATEGORY_ID' => 0, 'LEAD_REPEAT' => $repeat, 'LEAD_LEGAL' => $legal]);
$profiles = [$p(1, '', '', 1), $p(2, 'new', '', 50), $p(3, 'new', 'person', 500), $p(4, 'repeat', 'legal'), $p(5, '', 'legal', 10), $p(6, 'repeat', '', 1, false)];
$pick = static fn(?LeadClientType $type): ?int => ProfilePicker::pick($profiles, 0, null, $type)?->id;
Check::same('новый физлицо — два признака главнее одного и сортировки', $pick(new LeadClientType(false, false)), 3);
Check::same('новый юрлицо — юр (сорт 10) и новый (сорт 50) по одному признаку: меньшая сортировка', $pick(new LeadClientType(false, true)), 5);
Check::same('повторный юрлицо — точный', $pick(new LeadClientType(true, true)), 4);
Check::same('повторный физлицо — только «любой» (выключенный не берётся)', $pick(new LeadClientType(true, false)), 1);
Check::same('признаков нет — только «любой»', $pick(null), 1);
Check::same('без «любого» и не совпало — null', ProfilePicker::pick([$p(4, 'repeat', 'legal')], 0, null, new LeadClientType(false, false)), null);
$dealProfiles = [Profile::fromRow(['ID' => 7, 'IS_ENABLED' => 'Y', 'CLIENT_TYPES' => '2']), Profile::fromRow(['ID' => 8, 'IS_ENABLED' => 'Y'])];
Check::same('сделка — как раньше, признаки лида не мешают', [ProfilePicker::pick($dealProfiles, 0, 2, new LeadClientType(true, true))?->id, ProfilePicker::pick($dealProfiles, 0, null)?->id], [7, 8]);

Check::group('сводка модели');

$facts = static fn(?LeadClientType $type): DealFacts => new DealFacts(1, 'Лид', 'Новый', 0.0, '', 1, 1, 0, 0, 0, 0, [], noun: 'Лид', leadClient: $type);
Check::same('лид: строка о клиенте', str_contains($facts(new LeadClientType(true, false))->toPromptText(), 'Клиент лида: повторный (к лиду привязан контакт или компания), физлицо'), true);
Check::same('лид новый юрлицо', str_contains($facts(new LeadClientType(false, true))->toPromptText(), 'Клиент лида: новый (контакт и компания не привязаны), юрлицо'), true);
Check::same('не лид — строки нет', str_contains($facts(null)->toPromptText(), 'Клиент лида'), false);

Check::group('конвертация: когда запись из лида');

Check::same('LEAD_ID', ConversionCleaner::isFromLead(['LEAD_ID' => 5]), true);
Check::same('LEAD_ID строкой', ConversionCleaner::isFromLead(['LEAD_ID' => '5']), true);
Check::same('PARENT_ID_1', ConversionCleaner::isFromLead(['PARENT_ID_1' => 3]), true);
Check::same('LEAD_ID 0, пусто, false, null', [ConversionCleaner::isFromLead(['LEAD_ID' => 0]), ConversionCleaner::isFromLead(['LEAD_ID' => '']), ConversionCleaner::isFromLead(['LEAD_ID' => false]), ConversionCleaner::isFromLead(['LEAD_ID' => null])], [false, false, false, false]);
Check::same('родитель — не лид (PARENT_ID_2)', ConversionCleaner::isFromLead(['PARENT_ID_2' => 3]), false);
Check::same('нет полей', ConversionCleaner::isFromLead([]), false);

Check::group('конвертация: какие поля чистить');

$incoming = ['TITLE' => 'Сделка', 'LEAD_ID' => 5, EntityFields::RISK => 80, EntityFields::WHY => 'лид молчит', EntityFields::HISTORY => '…', EntityFields::DONE_SENIOR => 1, EntityFields::ACK_MANAGER => '{"at":1}', 'UF_OTHER' => 'x'];
Check::same('только наши и только те, что пришли', ConversionCleaner::fieldsToClear($incoming), [EntityFields::RISK, EntityFields::WHY, EntityFields::ACK_MANAGER, EntityFields::DONE_SENIOR, EntityFields::HISTORY]);
$cleared = ConversionCleaner::clear($incoming);
Check::same('из лида — наши null, чужие и остальное на месте', [$cleared[EntityFields::RISK], $cleared[EntityFields::WHY], $cleared[EntityFields::HISTORY], $cleared[EntityFields::DONE_SENIOR], $cleared[EntityFields::ACK_MANAGER], $cleared['UF_OTHER'], $cleared['TITLE'], $cleared['LEAD_ID']], [null, null, null, null, null, 'x', 'Сделка', 5]);
Check::same('не добавляет полей, которых не было', array_key_exists(EntityFields::DATE, $cleared), false);
$own = $incoming;
unset($own['LEAD_ID']);
Check::same('не из лида — без изменений (запись модулем, ручное создание)', ConversionCleaner::clear($own), $own);
Check::same('элемент: сбросить только непустые', ConversionCleaner::valuesToReset([EntityFields::RISK => 80, EntityFields::WHY => '', EntityFields::DONE_SENIOR => 0, EntityFields::DONE_MANAGER => true, EntityFields::DATE => null, 'UF_OTHER' => 'x']), [EntityFields::RISK => null, EntityFields::DONE_MANAGER => null]);
Check::same('элемент: все пустые — нечего писать', ConversionCleaner::valuesToReset([EntityFields::RISK => null]), []);

Check::group('конвертация: события');

$events = array_map(static fn(array $e): string => $e[0].':'.$e[1].':'.$e[3], EntityFields::EVENTS);
Check::same('сделка до добавления', in_array('crm:OnBeforeCrmDealAdd:onBeforeDealAdd', $events, true), true);
Check::same('элемент смарт-процесса после добавления', in_array('crm:onCrmDynamicItemAdd:onDynamicItemAdd', $events, true), true);
foreach(EntityFields::EVENTS as [, , $class, $method])
{
	if($class === \Shef\ToolsAiCrm\Deal\Field\Handlers::class)
	{
		Check::same('метод есть: '.$method, method_exists($class, $method), true);
	}
}
$fields = ['LEAD_ID' => 5, EntityFields::RISK => 80];
Check::same('обработчик сделки чистит и не отменяет добавление', [\Shef\ToolsAiCrm\Deal\Field\Handlers::onBeforeDealAdd($fields), $fields[EntityFields::RISK]], [true, null]);

Check::finish();
