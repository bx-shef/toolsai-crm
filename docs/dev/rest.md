# REST shef.toolsaicrm

> Последняя сверка: 2026-11-09

Класс: `Shef\ToolsAiCrm\Integration\Rest\Service`, подписка `rest:OnRestServiceBuildDescription`.
Область прав — `shef_toolsaicrm`. Главный потребитель — MCP-сервер shef.cowork. Приложению на
портале нужно право `shef_toolsaicrm` (карточка приложения → «Права», затем переустановка).

**Сотрудник — только из токена вызова**: параметра «от чьего имени» нет. Ошибки —
`RestException` с кодом: `INVALID_ARGUMENT`, `ACCESS_DENIED` (вызов без сотрудника или нет
права), `CRM_NOT_INSTALLED`, `REPORT_UNAVAILABLE`.

| метод | параметры | ответ | право |
|---|---|---|---|
| `shef.toolsaicrm.mcp.tools` | — | манифест инструментов для сотрудника | любое: манифест строится по праву |
| `shef.toolsaicrm.report.managerscores` | `from?`, `to?`, `userId?`, `departmentId?`, `limit?` | оценка работы менеджеров из анализа записей: ответственные, оценок, средний балл, минимум, место | «Статистика по всему порталу» (`reports.all` ролей shef.toolsai) |

* Инструмент MCP — `toolsai_manager_scores` («Оценка работы менеджеров по сделкам»). Имя
  инструмента то же, что у отчёта в shef.toolsai: клиенты MCP его знают.
* Даты — строго `ГГГГ-ММ-ДД`, включительно; нет — с 1-го числа месяца по сегодня. Негодная дата
  или число — `INVALID_ARGUMENT` с подсказкой.
* `limit` — по умолчанию 30, до 50; `more` — сколько не показано; `truncated` — оценок больше,
  чем читает отчёт. Имена — «Фамилия Имя» (без имени — `#ID`), тексты портала — одной строкой.
* Нет права — инструмента нет в манифесте, прямой вызов — `ACCESS_DENIED`. `userId`,
  `departmentId` только сужают выборку. Нет CRM — `CRM_NOT_INSTALLED`, сбой базы — `REPORT_UNAVAILABLE`; данных
  нет — `note` с причиной.
* Подключение в shef.cowork: `shef.toolsaicrm.mcp.tools` — в «Модули портала» на `/admin` сервера
  MCP (или `MODULE_MANIFESTS`).

Пример (вебхук приложения с правом `shef_toolsaicrm`):

```
POST https://portal.example/rest/<user>/<token>/shef.toolsaicrm.report.managerscores
{"from": "2026-11-01", "to": "2026-11-08", "limit": 10}
→ {"result": {"period": {"from": "2026-11-01", "to": "2026-11-08"}, "url": "…", "managers": […]}}
```
