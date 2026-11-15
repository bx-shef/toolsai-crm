#!/usr/bin/env bash
# Тест tools/changelog-feed-check.sh на мутациях (bx-shef/toolsai#332):
# годная запись проходит, каждая поломка — краснеет. Нужны bash, git.
set -u
pr=""

here="$(cd "$(dirname "$0")/.." && pwd)"
check="$here/tools/changelog-feed-check.sh"
product="${1:-toolsai}"
fails=0
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

good()
{
	cat <<EOF
---
product: $product
date: 2026-10-22
pr: 313
version: 1.63.7
title: Групповые операции показывают прогресс
shots:
  - name: progress
    url: /shef/toolsai/view/?entity=deal
    steps: ["выделить 5 строк"]
---
Оценка на нескольких сделках показывает, сколько записей готово.
EOF
}

# Репозиторий: база с CHANGELOG, ветка поверх.
setup()
{
	rm -rf "$tmp/r"; mkdir -p "$tmp/r/changelog"; cd "$tmp/r" || exit 1
	git init -q; git config core.autocrlf false; git config user.email t@t; git config user.name t
	printf '# Изменения\n\n## Не выпущено\n\n## 1.0.0 — 2026-10-01\n\n* старое\n' > CHANGELOG.md
	git add -A; git commit -qm base; base="$(git rev-parse HEAD)"
}

# expect ok|fail <описание> [часть сообщения об ошибке — сработало именно это правило]
expect()
{
	local want="$1" what="$2" pat="${3:-}" rc out
	out="$(FEED_BASE="$base" FEED_PR="$pr" bash "$check" "$product" "$tmp/r" 2>&1)"; rc=$?
	if { [ "$want" = ok ] && [ $rc -ne 0 ]; } || { [ "$want" = fail ] && [ $rc -eq 0 ]; }
	then
		echo "  ✗ тест ленты: «$what» — ждали $want, код $rc" >&2; fails=$((fails + 1))
	elif [ "$want" = fail ] && [ -n "$pat" ] && ! printf '%s' "$out" | grep -qF -- "$pat"
	then
		echo "  ✗ тест ленты: «$what» — нет «$pat» в выводе: $out" >&2; fails=$((fails + 1))
	fi
}

mutate() # $1 — sed-выражение, $2 — описание, $3 — часть сообщения
{
	setup; good | sed "$1" > changelog/2026-10-22-313.md; expect fail "$2" "$3"
}

body() # запись с текстом $1
{
	setup; good | sed '$d' > changelog/2026-10-22-313.md; printf '%s\n' "$1" >> changelog/2026-10-22-313.md
}

setup; good > changelog/2026-10-22-313.md; expect ok 'годная запись'
setup; good | sed '/^shots:/,/^---$/{/^shots:/!{/^---$/!d}}; s/^shots:.*/shots:/' > changelog/2026-10-22-313.md; expect ok 'shots пусто'
setup; good | sed '/^version:/s/:.*/:/' > changelog/2026-10-22-313.md; expect ok 'version пусто'
setup; good | sed 's/^title:.*/title: Задача #332 и порт сервиса/; s/^date:.*/date: "2026-10-22"/' > changelog/2026-10-22-313.md; expect ok '«#» в заголовке, дата в кавычках'
body "$(printf 'Ж%.0s' $(seq 1 20))"; expect ok 'ровно 20 знаков'
body "$(printf 'Ж%.0s' $(seq 1 400))"; expect ok 'ровно 400 знаков'
body 'Сервис доступен на portal.example.com:8443 после включения.'; expect ok 'адрес с портом — не «файл:строка»'

mutate '/^product:/d' 'нет product' 'нет поля product'
mutate '/^title:/d' 'нет title' 'нет поля title'
mutate '/^date:/d' 'нет date' 'нет поля date'
mutate '/^pr:/d' 'нет pr' 'нет поля pr'
mutate 's/^title:.*/title: # комментарий/' 'title — только комментарий' 'нет поля title'
mutate 's/^product:.*/product: nosuch/' 'product не из списка' 'product «nosuch»'
mutate "s/^product:.*/product: $([ "$product" = assistant ] && echo kb || echo assistant)/" 'product другого репозитория' 'а репозиторий'
mutate 's/^date:.*/date: 22.10.2026/' 'date не Y-m-d' 'не в формате'
mutate 's/^date:.*/date: 2026-02-31/' 'несуществующая дата' 'такой даты нет'
mutate 's/^date:.*/date: 2026-02-29/' '29 февраля невисокосного' 'такой даты нет'
mutate 's/^pr:.*/pr: abc/' 'pr не номер' 'не номер'
mutate 's/^version:.*/version: 1.63/' 'version не X.Y.Z' 'не X.Y.Z'
setup; good | sed 's/^version:.*/&\nkind: improved\nweight: 3/' > changelog/2026-10-22-313.md; expect ok 'kind improved, weight 3'
setup; good | sed 's/^version:.*/&\nkind:\nweight:/' > changelog/2026-10-22-313.md; expect ok 'kind и weight пусто'
mutate 's/^version:.*/&\nkind: fix/' 'kind не из списка' 'kind «fix»'
mutate 's/^version:.*/&\nweight: 4/' 'weight 4' 'weight «4»'
mutate 's/^version:.*/&\nweight: главное/' 'weight словом' 'weight «главное»'
mutate '/^    url:/d' 'shots без url' 'нет name или url'
mutate 's/^  - name: progress/  - url: \/x/; /^    url:/d' 'shots без name' 'нет name или url'
mutate 's/^  - name: progress/  - name: Progress_1/' 'name кадра не латиница-дефис' 'name кадра'
mutate '1d' 'нет шапки' 'начинается не с'
mutate '1s/^/\xef\xbb\xbf/' 'BOM' 'BOM'
body "$(printf 'Ж%.0s' $(seq 1 19))"; expect fail '19 знаков' 'текст 19 знаков'
body "$(printf 'Ж%.0s' $(seq 1 401))"; expect fail '401 знак' 'текст 401 знаков'
body ''; expect fail 'пустой текст' 'текст 0 знаков'
body 'Отчёт строится в разделе View::run для отдела.'; expect fail 'имя класса ::' 'имя класса'
body 'Отчёт строится классом Shef\ToolsAi для отдела.'; expect fail 'имя класса \' 'имя класса'
body 'Подробности в lib/view/Batch.PHP: 120 для разработчика.'; expect fail 'файл:строка' 'файл:строка'
body 'Отчёт по звонкам стоит 0,4 ¢ за запуск модели.'; expect fail 'цена' 'цена'
body 'Отчёт по звонкам <img src=x onerror=alert(1)> строится.'; expect fail 'HTML' 'HTML'
body 'Отчёт по звонкам теперь строится за минуту.'; expect fail '«теперь»' 'сравнение с прошлым'
body 'Теперь отчёт по звонкам строится за минуту.'; expect fail '«Теперь» с заглавной' 'сравнение с прошлым'
body 'Отчёт стоит 5 руб за запуск модели.'; expect fail 'цена в рублях' 'цена'
body 'Отчёт собирает 3 копии и 5 рубрик за минуту.'; expect ok '«копии», «рубрик» — не цена'
body 'Отчёт по звонкам стоит 100 byn за месяц работы.'; expect fail 'byn строчными' 'цена'
body 'Отчёт по звонкам стоит 20 usd за месяц работы.'; expect fail 'usd строчными' 'цена'
body 'Отчёт по звонкам стоит 5 руб/мес за сотрудника.'; expect fail 'руб/мес' 'цена'
body 'Отчёт показывает 2 USDT-кошелька и сеть 10 BYNet.'; expect ok 'USDT, BYNet — не цена'
setup; good | sed 's/^date:.*/date: 2028-02-29/; s/^pr:.*/pr: 1/' > changelog/2028-02-29-1.md; expect ok '29 февраля високосного'
body 'Кнопки «Сброс» в настройках больше нет совсем.'; expect fail '«больше нет»' 'сравнение с прошлым'
body 'Баланс DeepSeek виден рядом с расходом модуля.'; expect fail 'название модели' 'название модели'
body 'Оценка звонка своим движком работает на портале.'; expect fail '«своим движком»' 'сравнение с Битрикс24'
body 'Оценка звонка работает так же, как у BitrixGPT.'; expect fail 'BitrixGPT' 'сравнение с Битрикс24'
setup; good > changelog/2026-10-22-314.md; expect fail 'имя файла не по date и pr' 'не совпадает'

# Правило «Не выпущено».
unrel() { sed -i "s/^## Не выпущено\$/&\n\n$1/" CHANGELOG.md; }
setup; unrel '* новое'; expect fail 'строка в «Не выпущено» без записи' 'Не выпущено'
setup; unrel '* новое (лента: нет)'; expect ok 'технический PR с пометкой'
setup; unrel '* новое'; good > changelog/2026-10-22-313.md; expect ok 'строка и запись (не в git)'
setup; unrel '* новое'; good > changelog/2026-10-22-313.md; git add -A; git commit -qm pr; expect ok 'строка и запись в коммите'
pr=313; setup; unrel '* новое'; good > changelog/2026-10-22-313.md; expect ok 'запись с номером PR ветки'
pr=999; setup; unrel '* новое'; good > changelog/2026-10-22-313.md; expect fail 'запись не от этого PR' 'не от этого PR'
pr=x1; setup; unrel '* новое'; good > changelog/2026-10-22-313.md; expect fail 'FEED_PR не номер' 'не номер'
pr=''
setup; unrel '* новое'; good | sed 's/^pr:.*/pr: 999/' > changelog/2026-10-22-999.md; mkdir -p "$tmp/bin"; printf '#!/bin/sh\necho 313\n' > "$tmp/bin/gh"; chmod +x "$tmp/bin/gh"
out="$(cd "$tmp/r" && PATH="$tmp/bin:$PATH" FEED_BASE="$base" env -u FEED_PR bash "$check" "$product" "$tmp/r" 2>&1)" && { echo "  ✗ тест ленты: номер PR из gh не сверен" >&2; fails=$((fails + 1)); }
setup; unrel '* первое\n* второе'; git add -A; git commit -qm o; base="$(git rev-parse HEAD)"; sed -i 's/^\* первое$/* второе-/; s/^\* второе$/* первое/; s/^\* второе-$/* второе/' CHANGELOG.md; expect ok 'перестановка строк — не новая строка'
setup; unrel '* дубль'; git add -A; git commit -qm b; base="$(git rev-parse HEAD)"; sed -i 's/^\* дубль$/&\n* дубль/' CHANGELOG.md; expect fail 'дубль строки в «Не выпущено»' 'Не выпущено'
setup; good > changelog/2026-10-22-313.md; git add -A; git commit -qm old; base="$(git rev-parse HEAD)"; unrel '* новое'; expect fail 'запись уже в базе' 'Не выпущено'
setup; unrel '* новое'; printf 'x\n' > changelog/README.md; expect fail 'только README' 'Не выпущено'
setup; sed -i 's/^\* старое$/* старое, правка/' CHANGELOG.md; expect ok 'правка выпущенного раздела'
setup; unrel '* новое,\n  перенос (лента: нет)'; expect ok 'пометка на второй строке пункта'
setup; unrel '* новое,\n  перенос'; expect fail 'пункт в две строки без пометки' 'Не выпущено'

if [ $fails -eq 0 ]
then
	echo "  ✓ тест ленты: мутации краснеют"
fi
exit $fails
