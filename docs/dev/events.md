# События shef.toolsaicrm

> Последняя сверка: 2026-11-09

Событие шлёт модуль `shef.toolsaicrm` (класс `Shef\ToolsAiCrm\Analysis\CrmWrite`, `new \Bitrix\Main\Event('shef.toolsaicrm', 'OnAfterCrmWrite', …)`).
Подписка — в установщике своего модуля
(`EventManager::registerEventHandler('shef.toolsaicrm', 'OnAfterCrmWrite', 'my.module', Class::class, 'method')`),
снятие — при удалении. Обработчик принимает `\Bitrix\Main\Event`. Исключение обработчика модуль
ловит и пишет в журнал — запись в CRM не отменяется. Имя события и параметры не меняются в
пределах 1.x.

| событие | когда | параметры | ответ |
|---|---|---|---|
| `OnAfterCrmWrite` | модуль записал поля в сделку, лид или элемент смарт-процесса | `entityTypeId`, `id`, `fields`, `source`, `crmEvents` | не нужен |

## Событие после записи в CRM

`shef.toolsaicrm:OnAfterCrmWrite` — модуль записал поля в сделку, лид или элемент
смарт-процесса. Посылается после успешной записи, в обоих режимах настройки
«Запускать события Битрикс24, роботы и бизнес-процессы»
([Анализ сделок](../guide/01-deal-analysis.md#запись-в-crm-роботы-и-бизнес-процессы)).
Смена ответственного из вида «AI» его не посылает: это штатная запись CRM.

| параметр | что в нём |
|---|---|
| `entityTypeId` | тип записи CRM (2 — сделка, 1 — лид, ID типа смарт-процесса) |
| `id` | ID записи |
| `fields` | записанные поля — только изменённые: код => значение |
| `source` | `analysis` — итоги анализа, `flag` — кнопка отметки, `conversion` — сброс полей после конвертации лида |
| `crmEvents` | `true` — запись шла с роботами, бизнес-процессами и историей |

```php
\Bitrix\Main\EventManager::getInstance()->addEventHandler('shef.toolsaicrm', 'OnAfterCrmWrite', static function(\Bitrix\Main\Event $event): void
{
	['entityTypeId' => $type, 'id' => $id, 'fields' => $fields, 'source' => $source] = $event->getParameters();
	// …
});
```

Исключение в обработчике запись не отменяет: модуль пишет его в журнал и продолжает.
Новые коды `source` могут добавляться — неизвестный код обработчик пропускает. Формат значений
полей контрактом не является.

В `fields` бывают личные данные (значения полей «AI: …» записи). Не пишите их в
журналы и не отправляйте наружу как есть.

## Что модуль слушает

Это внутренние обработчики модуля; соседям их вызывать не нужно.

| событие | кто шлёт | что делает модуль |
|---|---|---|
| `shef.toolsai:OnRocketCardsCollect` | shef.toolsai | карточки «Авто-анализ CRM», «Промпты анализа», «Оценка работы менеджеров» (по правам) |
| `shef.toolsai:OnViewAnalyzeRecord` | shef.toolsai | анализ записи по кнопке «Оценить запись» вида «AI» |
| `shef.toolsai:OnViewAnalysisState` | shef.toolsai | включён ли автоанализ типа записи и адрес настроек |
| `shef.toolsaikb:OnKbSourcesCollect` | shef.toolsaikb | пакет справки `shef.toolsaicrm:guide` |
| `rest:OnRestServiceBuildDescription` | ядро | REST-методы области `shef_toolsaicrm` |
| `main:OnUserTypeBuildList`, `main:onGetPublicEdit`, `crm:OnBeforeCrmDealUpdate`, `crm:OnBeforeCrmLeadUpdate`, `crm:OnBeforeCrmDealAdd`, `crm:onCrmDynamicItemAdd` | ядро | тип поля и защита полей «AI: …», очистка при конвертации лида |
| `crm:OnBeforeCrmActivityAdd` | ядро | закрепление скрипта оценки звонка по правилам |
