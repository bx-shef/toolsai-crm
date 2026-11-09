# Памятка агенту: shef.toolsaicrm

> Последняя сверка: 2026-11-09

Модуль коробочного Битрикс24 «Tools AI: автооценка CRM»: AI оценивает сделки, лиды и
смарт-процессы — профили анализа, риск и вердикт, поля «AI: …», отметки, дела и
комментарии менеджерам и старшим, история риска, скрипты оценки звонков, оценка работы
менеджеров, статистика, робот БП «AI: оценить сейчас». Вынесен из `shef.toolsai`
(bx-shef/toolsai#639): код, таблицы, агенты и страницы анализа живут здесь. Опирается на
`shef.options`, `shef.problems` и движок `shef.toolsai` 1.96.0+ (модель, расход, квота,
лицензия, права — только через его публичный API). Чего здесь не хватает — смотрите
[CLAUDE.md shef.toolsai](https://github.com/bx-shef/toolsai/blob/main/CLAUDE.md)
и [shef.options](https://github.com/bx-shef/options/blob/main/CLAUDE.md).

Проверка перед сдачей — всегда `./build.sh --check`, ровно это же гоняет CI.

**Лента «Что нового»** — PR с изменением для пользователя несёт `changelog/<дата>-<PR>.md`
([changelog/README.md](changelog/README.md), `product: crm`), проверяет `build.sh --check`.

**Для разработчиков соседних модулей** — [docs/dev/](docs/dev/README.md): событие
`OnAfterCrmWrite`, REST и инструменты MCP.

**Процесс** — [docs/private/agent-rules.md](docs/private/agent-rules.md): только через PR,
ревью по уровню PR (`tools/review-tier.sh --action`, навык `review-panel`; уровень 0 — без
ревью, только `./build.sh --check`), мерж — `merge-pr`. Документация правится **в том же
PR**, что и код.

## Опорные точки

| что | где |
|---|---|
| прогон анализа типа: кандидаты, профиль, модель, шкала, запись, проверка | `lib/analysis/runner.php`, `lib/analysis/candidates.php`, агент типа — `lib/agent/analysisagent.php` |
| тип сущности (сделка, лид, смарт-процесс), список типов из CRM | `lib/analysis/entitytype.php`, `lib/analysis/entitytypes.php` |
| поля «AI: …»: создать, записать, положить в карточку | `lib/analysis/entityfields.php`, раздел карточки — `lib/deal/field/cardlayout.php` |
| запись модуля в CRM (режим `DEAL_crmevents`, событие `OnAfterCrmWrite`) | `lib/analysis/crmwrite.php` |
| проверки и история риска | `lib/analysis/checkstore.php`, `lib/analysis/history.php`, `lib/analysis/lastchecks.php`; таблицы — `lib/*/model/*table.php` |
| сводка для модели, лента касаний, переписка | `lib/deal/contextbuilder.php`, `lib/deal/dealfacts.php`, `lib/analysis/timeline.php`, `lib/analysis/timelinesource.php`, `lib/deal/conversation.php` |
| анализатор, вердикт, шкала, эскалация | `lib/deal/healthanalyzer.php`, `lib/deal/verdict.php`, `lib/deal/riskscale.php`, `lib/deal/escalation.php` |
| профили и подбор профиля, тип клиента | `lib/deal/profile.php`, `lib/deal/profilepicker.php`, `lib/deal/clienttype.php`, `lib/deal/leadclienttype.php` |
| кнопки «отработано» / «под контролем», тип поля `shef_toolsai_ack`, защита полей | `lib/deal/field/acktype.php`, `lib/deal/field/ackvalue.php`, `lib/deal/field/handlers.php`, контроллер — `lib/controller/dealflag.php` |
| промпты анализа по уровням | `lib/analysis/prompt/*`, таблица — `lib/analysis/model/prompttable.php`, страница — `settings/prompts.php` |
| скрипты оценки звонков | `lib/callscript/*` (правило, подбор, событие `crm:OnBeforeCrmActivityAdd`), страница — `settings/callscripts.php` |
| шов к shef.toolsai: модель, расход, квота, лицензия | `lib/container.php`, `lib/core/model.php` (через `LlmService`), права — `lib/settings/sectionaccess.php` (через `AccessService`) |
| настройки модуля | `lib/config.php`, `default_option.php`, `options_conf.php`, коды — `lib/main/constants.php` |
| перенос данных из shef.toolsai, «Проверить и включить», доведение | `lib/main/migration.php` (+ `migrationport.php`, `coremigrationport.php`), `lib/main/setup.php`, `lib/main/maintenance.php`, агент — `lib/agent/maintenance.php` |
| установщик и раскладка из git | `install/index.php`, `install/layout.php`, `install/version.php` |
| заглушки страницы, компонента и робота БП | `lib/main/publicpage.php` |
| страница модуля `/shef/toolsaicrm/`: разделы, права, форма, «?» | `settings/*` (`page.php`, `component.php`, `ajax.php`, разделы), `lib/settings/*`, `lib/ui/settingsform.*`, ответы — `settings/faqdata.php` |
| статистика: анализ записей, оценка менеджеров | `settings/stats.php`, `lib/stats/*` |
| робот БП «AI: оценить сейчас» | `activities/analyze/shtoolsaianalyzeactivity.php`, запуск — `lib/robot/analyzenow.php` |
| REST и MCP (область `shef_toolsaicrm`) | `lib/integration/rest/service.php`, манифест и счёт — `lib/rest/mcptools.php` |
| события shef.toolsai: карточки «ракеты», вид «AI» | `lib/internals/integration/toolsai/eventhandler/*` |
| справка в «Базе знаний» | пакет `kb/guide` (собирает `tools/kb-build.php` из `docs/guide` и `settings/faqdata.php`), событие — `lib/internals/integration/shef/toolsaikb/*` |

## Решения, которые не «упрощать»

* **Шов к shef.toolsai — только публичный API и разрешённые таблицы архива.** Модель и расход —
  `LlmService` (`completeJson`, `allow`, `model`), права — `AccessService::can`. Классов shef.toolsai
  в `lib/` нет; границу держит `tests/seam_test.php`. Сырые имена таблиц shef.toolsai встречаются
  только в переносе (`Main\Migration`).
* **Коды опций, тип поля и робот не меняются.** `DEAL_*`, `ANALYSIS_*`, `CALLSCRIPT_*`, поля
  `UF_SHEF_AI_*`, тип поля `shef_toolsai_ack`, каталог робота
  `/bitrix/activities/custom/shtoolsaianalyzeactivity` и класс `CBPShToolsAiAnalyzeActivity` — на них
  лежат данные порталов и схемы БП. Поля «AI: …» установка не пересоздаёт.
* **Перенос данных — сверка перед отметкой.** `Main\Migration`: остановить старое, копия
  `INSERT IGNORE ... SELECT` с теми же ID, сверка по каждой таблице, и только после неё отметки
  `SYS_migrated_from_toolsai` (здесь) и `SYS_crm_moved` (в shef.toolsai). Не сошлось — отметок нет,
  старое остаётся. Только по `SYS_crm_moved` shef.toolsai убирает свои таблицы. Повтор безопасен:
  ничего не затирает. Тяжёлый перенос в доведение не входит (только установка и «Проверить и включить»).
* **Агенты — ровно по одному на тип** (`\Shef\ToolsAiCrm\Agent\AnalysisAgent::run(<entityTypeId>);`);
  старые агенты и подписки shef.toolsai гасит установщик (прямыми вызовами ядра, без классов
  shef.toolsai). Профили не прочитались — агентов не трогаем (пустой список снял бы все).
* **Расход ведёт `LlmService`** (категория `crm_analysis`): журнал, квоту и лицензию модуль не
  дублирует. Лимиты действуют и на администраторов.
* **Поля «AI: …» меняет только модуль**: `EDIT_IN_LIST = N`, `onGetPublicEdit`, откат правки
  извне. Запись модуля — через `CrmWrite`, без проверки прав, но с событием `OnAfterCrmWrite`.
* **Дешёвый фильтр до модели** (`DealFacts::isWorthAnalyzing()`) — главная экономия квоты; не
  ослаблять без замера.
* **Файлы вне `bitrix/modules` — только заглушки в одну строку**, которые модуль пишет сам
  (`Main\PublicPage`) и восстанавливает `Main\Maintenance`: на портале модуль обновляют
  копированием, установщик не запускается. Отметка `SYS_eventsversion` — «версия|хеш
  `.settings.php`», файлы читаются мимо opcache; сбой — повтор не чаще раза в 10 минут.
* **Контроллер — без своего `run()`** (в ядре он final): фильтры — `getDefaultPreFilters()`, право —
  в каждом действии.
* **Без «Вернуть значения по умолчанию»**, без цен в интерфейсе, без названия модели в текстах
  (решения владельца).
* **Справка — `docs/guide` → `kb/guide`**: пакет пересобирает `php tools/kb-build.php`; устаревший
  пакет краснеет в `tests/kb_test.php`. Якоря в `settings/faqdata.php` (`doc`) обязаны совпадать с
  заголовками глав — это держит тот же тест; заголовок со знаками версии — не писать.

## Тесты

`tests/` гоняются как обычные скрипты: `php tests/<имя>_test.php` (локально, PHP 8.4), обвязка
`tests/assert.php` — общая с линейкой. Ядро — заглушки `tests/stub/`, классы модуля — настоящие.
Проверка целиком — `./build.sh --check`; на Windows и в контейнере — через
`../stand/tools/pw-run.sh` (образ с php, git и node), дерево копируется в `/tmp` контейнера и
коммитится там: `.git` worktree в контейнере недоступен. Прямой `docker run` не использовать.

| тест | что держит |
|---|---|
| `runner_test.php`, `deal_test.php`, `profile_test.php`, `profilestage_test.php` | прогон, фильтр до модели, вердикт, профили и подбор, стадии и итог |
| `entityanalysis_test.php`, `analysismatrix_test.php`, `leadclient_test.php` | типы сущностей, матрица «тип × источник × выход», лиды и конвертация |
| `conversation_test.php`, `chat_test.php`, `email_test.php`, `timeline_test.php` | переписка и лента касаний в сводке |
| `dealfields_test.php`, `entityfieldswrite_test.php`, `cardlayout_test.php`, `crmwrite_test.php` | поля «AI: …», отметки, карточка, запись в CRM и событие |
| `callscript_test.php` | правила скриптов оценки звонков |
| `model_test.php`, `seam_test.php` | шов к shef.toolsai: модель через `LlmService`, нет классов движка в коде |
| `migration_test.php`, `setup_test.php`, `install_test.php`, `settings_agents_test.php` | перенос данных и сверка, «Проверить и включить», установщик, агенты по типам |
| `publicpage_test.php`, `settings_sections_test.php`, `settings_faq_test.php`, `settings_optionsconf_test.php`, `settings_periodfilter_test.php`, `uiform_test.php`, `buildinfo_test.php` | страница модуля: разделы, права, «?», настройки, период отчётов |
| `stats_test.php`, `lastchecks_test.php` | статистика, последние проверки |
| `mcp_test.php`, `robot_test.php`, `rocketcards_test.php`, `viewevents_test.php`, `kbsources_test.php` | REST и MCP, робот БП, карточки «ракеты», события вида «AI», справка в «Базе знаний» |
| `docs_test.php`, `docs_public_test.php`, `kb_test.php` | ссылки и классы в документации, публичное не смешано с внутренним, пакет `kb/guide` собран и совпадает с faqdata |
| `changelog_feed.sh` | проверка ленты «Что нового» на мутациях |

## Тексты для людей

Тексты для людей — о том, что есть сейчас; прошлое и наше развитие не упоминаем (правило владельца, 2026-10-28). В интерфейсе, языковых файлах, подсказках, сообщениях об ошибках, справке для клиентов (docs/guide) нет «больше нет», «теперь», «раньше», «перенесено», «с версии X», «как и прежде», «вместо старого…»: пишем текущее состояние и что нажать. «AI», не «ИИ»; без названия модели, без сравнения с Битрикс24, без цен. История — в CHANGELOG, комментариях кода и docs/private.

## Документация и практики Битрикс

### Источники
Документацию Битрикс берём из локальных клонов официальных репозиториев, а не из памяти модели и не со сторонних сайтов:

- `.bitrix-docs/framework-docs/` — Bitrix Framework (D7, ORM, модули, компоненты, контроллеры, события ядра). Исходник: https://github.com/bitrix-tools/framework-docs
- `.bitrix-docs/b24-rest-docs/` — REST API Битрикс24 (методы, события, вебхуки, приложения). Исходник: https://github.com/bitrix-tools/b24-rest-docs

Скиллы с правилами написания кода: https://github.com/bitrix-tools/best-practice
- `bitrix-best-practice-core` — контроллеры, роутинг, конфигурация, базовые сущности
- `bitrix-best-practice-sql` — работа с БД и ORM

### Первичная настройка (если папки `.bitrix-docs/` нет)
1. Склонировать документацию:
   ```bash
   mkdir -p .bitrix-docs
   git clone --depth 1 https://github.com/bitrix-tools/framework-docs.git .bitrix-docs/framework-docs
   git clone --depth 1 https://github.com/bitrix-tools/b24-rest-docs.git .bitrix-docs/b24-rest-docs
   ```
   Общий клон линейки уже лежит уровнем выше — `../.bitrix-docs/` (каталог `tools-ai`); можно пользоваться им.
2. `.bitrix-docs/` — в `.gitignore` проекта (уже добавлено).
3. Установить скиллы:
   ```bash
   npx skills add bitrix-tools/best-practice --skill bitrix-best-practice-core
   npx skills add bitrix-tools/best-practice --skill bitrix-best-practice-sql
   ```

### Правила работы
- Перед тем как писать код с API Битрикса, найди нужную статью поиском (grep/rg) по соответствующему клону и опирайся на неё. Не выдумывай классы, методы, параметры и поля.
- Задачи по D7, ORM, модулям и компонентам решай по `framework-docs` вместе с исходниками ядра проекта в `/bitrix/modules/`. Если документация и код ядра расходятся, прав код ядра (он соответствует установленной версии). Расхождение отметь в ответе.
- Задачи по REST-методам, событиям и приложениям Битрикс24 решай по `b24-rest-docs`. Если подключён MCP `b24-dev-mcp`, им тоже можно пользоваться.
- Для решений «как правильно писать» (структура, контроллеры, запросы к БД, ORM) применяй скиллы `bitrix-best-practice-core` и `bitrix-best-practice-sql`.
- Не используй устаревшие API (старое ядро `C*`-классов), если в D7 есть замена. Исключение: проект явно требует старое API.
- В ответе указывай путь к файлу документации, на который опирался.
- Обновление коробки из консоли — `php bitrix.php update:modules` (`framework-docs/pages/framework/console-commands.md`).
