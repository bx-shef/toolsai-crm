# Навыки ИИ-агента в этом репозитории

**Навыки линейки shef.\*** — копия из [bx-shef/skills](https://github.com/bx-shef/skills)
(каталог `skills/`), на коммите из файла `SOURCE`. Файлы копии не правим:
замечания к навыку — отзывом (`shef-feedback`).

* обновить до свежего `main` — `.claude/skills/update.sh` (PR с новым `SOURCE`);
* сверить копию с источником — `.claude/skills/update.sh --verify` (так делает CI);
* целостность — `.claude/skills/sync.sh --check`.

До 2026-10 источником был `bx-shef/options/.claude/skills/` — его оттуда убрали.

**Свои навыки репозитория** — `merge-pr`, `review-panel` — и
служебные файлы перечислены в `LOCAL.MANIFEST`; правятся здесь, пересборка —
`sync.sh --local`.
