# shef.toolsaicrm — для разработчиков и их AI-агентов

> Последняя сверка: 2026-11-09

Карта всей линейки модулей — в документации shef.toolsai (`docs/dev/llms.txt`).
Модуль `shef.toolsaicrm` зависит от shef.toolsai (1.96.0 и выше): модель, расход, квоту,
лицензию и права он берёт через публичный API движка (`LlmService`, `AccessService`).

## Что модуль даёт соседям

| Что | Где | Документ |
|---|---|---|
| Событие `shef.toolsaicrm:OnAfterCrmWrite` — модуль записал поля в сделку, лид или элемент смарт-процесса | `Analysis\CrmWrite` | [events.md](events.md) |
| REST: область `shef_toolsaicrm`, метод `shef.toolsaicrm.report.managerscores` и манифест `shef.toolsaicrm.mcp.tools` (их же берёт MCP-сервер shef.cowork) | `Integration\Rest\Service`, `Rest\McpTools` | [rest.md](rest.md) |

Модуль сам слушает события shef.toolsai и shef.toolsaikb — это его внутренние обработчики
(`OnRocketCardsCollect`, `OnViewAnalyzeRecord`, `OnViewAnalysisState`, `OnKbSourcesCollect`),
соседям их вызывать не нужно ([events.md](events.md#что-модуль-слушает)).

## Публичное и внутреннее

* Публичное: имя события `OnAfterCrmWrite` и его параметры, REST-методы `shef.toolsaicrm.*`.
* **Не использовать** из других модулей: классы `Shef\ToolsAiCrm\*`, опции модуля
  (`DEAL_*`, `ANALYSIS_*`, `CALLSCRIPT_*`), таблицы `shef_toolsaicrm_*`. Это внутренности,
  они меняются без предупреждения.
* Модуля может не быть: в обработчиках типизируйте аргумент как `\Bitrix\Main\Event`, классы
  модуля не импортируйте.

## Файлы

* [events.md](events.md) — события: контракт, параметры, пример PHP.
* [rest.md](rest.md) — REST-методы, права, коды ошибок.
* Описание для людей — [../guide/](../guide/README.md).
