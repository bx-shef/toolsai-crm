#!/usr/bin/env bash
# Проверка записей ленты «Что нового» (bx-shef/toolsai#332).
#
# Запись — changelog/<YYYY-MM-DD>-<PR>.md: шапка между строками «---»
# (product, date, pr, title обязательны; version, shots — по желанию) и текст
# для руководителя 20–400 знаков, без «файл:строка», имён классов, цен и «теперь/больше нет».
#
# Второе правило: строка, добавленная в раздел «## Не выпущено» CHANGELOG.md
# относительно базы ветки, требует нового файла ленты. Технический PR помечает
# строку «(лента: нет)». База — FEED_BASE или merge-base с origin/main; нет git,
# нет базы или ветка совпадает с базой — правило пропускается с пометкой.
#
# Номер PR: FEED_PR или `gh pr view` ветки (нет сети или PR — без сверки); тогда среди новых
# записей есть запись с этим pr:.
#
# Эталон — bx-shef/toolsai; в остальных репозиториях семейства — побайтная копия, правки только
# в эталоне (копии сверяет tools/changelog-feed-sync.sh). Отличие — продукт:
#   tools/changelog-feed-check.sh <product> [каталог репозитория]
# Только bash, awk, grep, git: php и node не нужны. Кириллица — не в [скобках] grep:
# в локали C скобка совпадает с байтом, а не с буквой.

set -u

PRODUCTS='toolsai assistant mcp announce kb eval memory light embed'
product="${1:-}"
root="${2:-.}"
bad=0

err() { echo "  ✗ лента: $*" >&2; bad=1; }

case " $PRODUCTS " in
	*" $product "*) ;;
	*) echo "  ✗ лента: продукт репозитория «$product» не из списка: $PRODUCTS" >&2; exit 2 ;;
esac

cd "$root" || exit 2

# Знаки UTF-8 без локали: считаем байты, кроме продолжений 10xxxxxx.
chars() { LC_ALL=C tr -d '\200-\277' | wc -c | tr -d ' '; }

check_file()
{
	local f="$1" name head body val n
	name="$(basename "$f" .md)"

	if [ "$(head -c 3 "$f")" = $'\xef\xbb\xbf' ]
	then
		err "$f: файл с BOM — сохраните в UTF-8 без BOM"; return
	fi
	if [ "$(head -n 1 "$f" | tr -d '\r')" != '---' ]
	then
		err "$f: файл начинается не с «---»"; return
	fi
	if [ "$(grep -c $'^---\r\\?$' "$f")" -lt 2 ]
	then
		err "$f: нет закрывающей «---» шапки"; return
	fi

	head="$(tr -d '\r' < "$f" | awk 'NR==1{next} /^---$/{exit} {print}')"
	body="$(tr -d '\r' < "$f" | awk 'c>=2{print} /^---$/{c++}')"

	# Как parse() в site/landing/changelog-build.mjs: комментарий — « #» после пробела, кавычки снимаются.
	field() { printf '%s\n' "$head" | sed -n "s/^$1:[[:space:]]*//p" | sed 's/[[:space:]][[:space:]]*#.*$//; s/^#.*$//; s/[[:space:]]*$//; s/^"\(.*\)"$/\1/' | head -n 1; }

	for key in product date pr title
	do
		if [ -z "$(field "$key")" ]
		then
			err "$f: нет поля $key"
		fi
	done

	val="$(field product)"
	if [ -n "$val" ] && [ "$val" != "$product" ]
	then
		err "$f: product «$val», а репозиторий — «$product»"
	fi

	val="$(field date)"
	if [ -n "$val" ] && ! printf '%s' "$val" | grep -qE '^20[0-9]{2}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$'
	then
		err "$f: date «$val» не в формате ГГГГ-ММ-ДД"
	elif [ -n "$val" ] && ! printf '%s' "$val" | awk -F- '{y=$1+0; m=$2+0; d=$3+0; n=substr("312831303130313130313031", m*2-1, 2)+0; if (m==2 && (y%4==0 && (y%100!=0 || y%400==0))) n=29; exit !(d<=n)}'
	then
		err "$f: date «$val» — такой даты нет"
	fi

	val="$(field pr)"
	if [ -n "$val" ] && ! printf '%s' "$val" | grep -qE '^[0-9]+$'
	then
		err "$f: pr «$val» — не номер"
	fi

	if ! printf '%s' "$name" | grep -qE '^[0-9]{4}-[0-9]{2}-[0-9]{2}-[0-9]+$'
	then
		err "$f: имя файла не <ГГГГ-ММ-ДД>-<PR>.md"
	elif [ "$name" != "$(field date)-$(field pr)" ]
	then
		err "$f: имя файла не совпадает с date и pr шапки"
	fi

	val="$(field version)"
	if [ -n "$val" ] && ! printf '%s' "$val" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$'
	then
		err "$f: version «$val» не X.Y.Z"
	fi

	# «Что нового» (#332): kind — new | improved | fixed, weight — 1..3; пусто — new и 1.
	val="$(field kind)"
	if [ -n "$val" ] && ! printf '%s' "$val" | grep -qxE 'new|improved|fixed'
	then
		err "$f: kind «$val» не new, improved или fixed"
	fi
	val="$(field weight)"
	if [ -n "$val" ] && ! printf '%s' "$val" | grep -qxE '[123]'
	then
		err "$f: weight «$val» не 1, 2 или 3"
	fi

	# shots: каждый пункт «- name:» несёт url.
	n="$(printf '%s\n' "$head" | awk '
		/^shots:/ {s=1; next}
		s && /^[^ \t-]/ {s=0}
		s && /^[ \t]*-[ \t]*name:[ \t]*[^ \t]/ {if (open && !url) bad++; open=1; url=0; next}
		s && /^[ \t]*-/ {if (open && !url) bad++; open=1; url=0; bad++; next}
		s && /^[ \t]+url:[ \t]*[^ \t]/ {url=1}
		END {if (open && !url) bad++; print bad+0}')"
	if [ "$n" != 0 ]
	then
		err "$f: в shots у пункта нет name или url"
	fi
	if printf '%s\n' "$head" | grep -E '^[[:space:]]*-[[:space:]]*name:' | sed 's/^[^:]*:[[:space:]]*//; s/[[:space:]]*$//' | grep -qvE '^[a-z0-9-]+$'
	then
		err "$f: name кадра — строчная латиница, цифры, дефис"
	fi

	n="$(printf '%s' "$body" | tr '\n' ' ' | sed 's/^[[:space:]]*//; s/[[:space:]]*$//' | chars)"
	if [ "$n" -lt 20 ] || [ "$n" -gt 400 ]
	then
		err "$f: текст $n знаков, нужно 20–400"
	fi
	if printf '%s' "$body" | grep -q '::\|\\'
	then
		err "$f: в тексте имя класса («::» или «\\»)"
	fi
	if printf '%s' "$body" | grep -qiE '[A-Za-z0-9_./-]+\.(php|phtml|js|mjs|ts|vue|sh|json|css|html|py|md):[[:space:]]?[0-9]+'
	then
		err "$f: в тексте ссылка «файл:строка»"
	fi
	if printf '%s\n%s' "$(field title)" "$body" | grep -qE '<[A-Za-z/!]'
	then
		err "$f: в тексте HTML — только простой текст"
	fi
	# Решения владельца: цен в текстах для людей нет; тексты — о том, что есть.
	if printf '%s\n%s' "$(field title)" "$body" | grep -qE '[0-9][0-9  ,.]*(([Bb][Yy][Nn]|[Uu][Ss][Dd])([^A-Za-z]|$)|(Р|р)уб(л|[ .,;:)/-]|$)|₽|\$|¢|(К|к)оп(е|[ .,;:)/-]|$))|\$[0-9]'
	then
		err "$f: в тексте цена"
	fi
	if printf '%s\n%s' "$(field title)" "$body" | grep -qE '(^|[ "(-]|«|—)((Т|т)еперь|(Р|р)аньше|(Б|б)ольше не|(С|с) версии|(П|п)еренес(е|ё)н|(К|к)ак и прежде)'
	then
		err "$f: в тексте сравнение с прошлым («теперь», «раньше», «больше нет»…) — пишите, что есть"
	fi
	if printf '%s
%s' "$(field title)" "$body" | grep -qiE 'deepseek|qwen|chatgpt|gpt-?[0-9o]|claude|gemini|llama|yandexgpt|gigachat|mistral'
	then
		err "$f: в тексте название модели — пишите «модель»"
	fi
	if printf '%s
%s' "$(field title)" "$body" | grep -qE 'BitrixGPT|(С|с)воим движком|(В|в)место облака|(Л|л)учше( |, )чем|(В|в) отличие от'
	then
		err "$f: в тексте сравнение с Битрикс24/BitrixGPT — пишите, что есть у нас"
	fi
}

count=0
for f in changelog/*.md
do
	[ -e "$f" ] || continue
	[ "$(basename "$f")" = README.md ] && continue
	count=$((count + 1))
	check_file "$f"
done

# Строки раздела «## Не выпущено» без пустых, комментариев и «(лента: нет)».
# Пункт, перенесённый на несколько строк, склеивается: пометка — в любой его строке.
unreleased() { tr -d '\r' | awk '/^## /{s=($0 ~ /^## Не выпущено/); next} s' \
	| awk '/^[ \t]+[^ \t]/ && n {l = l " " $0; next} {if (n) print l; l = $0; n = 1} END {if (n) print l}' \
	| grep -v '^[[:space:]]*$' | grep -v '^[[:space:]]*<!--' | grep -v '(лента: нет)' || true; }

note=''
if ! command -v git >/dev/null 2>&1 || ! git rev-parse --git-dir >/dev/null 2>&1
then
	note='нет git — правило «Не выпущено» пропущено'
else
	base="${FEED_BASE:-$(git merge-base HEAD origin/main 2>/dev/null || true)}"
	if [ -z "$base" ]
	then
		note='нет origin/main — правило «Не выпущено» пропущено'
	elif [ "$(git rev-parse HEAD)" = "$(git rev-parse "$base")" ] && git diff --quiet "$base" -- CHANGELOG.md
	then
		note='ветка совпадает с базой — правило «Не выпущено» пропущено'
	elif [ -f CHANGELOG.md ]
	then
		# Разница раздела как мультимножеств: дубль и переписанная строка — новые,
		# перестановка строк — нет.
		added="$(LC_ALL=C comm -13 <(git show "$base:CHANGELOG.md" 2>/dev/null | unreleased | LC_ALL=C sort) \
			<(unreleased < CHANGELOG.md | LC_ALL=C sort))"
		new="$( { git diff --name-only --diff-filter=A "$base" -- changelog/; \
			git ls-files --others --exclude-standard -- changelog/; } | grep -E '\.md$' | grep -v README.md || true)"
		if [ -n "$added" ] && [ -z "$new" ]
		then
			err "в «Не выпущено» CHANGELOG.md новая строка, а файла changelog/<дата>-<PR>.md нет (технический PR — пометка «(лента: нет)» в строке)"
		fi
		# Номер PR — при любых новых записях. Записи за чужие PR (перенос задним числом)
		# идут вместе с записью своего PR или с FEED_PR= (пусто — без сверки).
		if [ -n "$new" ]
		then
			if [ -z "${FEED_PR+x}" ] && command -v gh >/dev/null 2>&1
			then
				pr="$(${TIMEOUT_CMD:-$(command -v timeout >/dev/null 2>&1 && echo 'timeout 15')} gh pr view --json number -q .number 2>/dev/null || true)"
			else
				pr="${FEED_PR:-}"
			fi
			if [ -z "$pr" ]
			then
				note="${note:+$note; }номер PR не определён — сверка номера пропущена"
			elif ! printf '%s' "$pr" | grep -qE '^[0-9]+$'
			then
				err "FEED_PR «$pr» — не номер"
			elif ! printf '%s\n' "$new" | grep -qE -- "-$pr\.md$"
			then
				err "новые записи ленты не от этого PR: нужна changelog/<дата>-$pr.md (pr: $pr)"
			fi
		fi
	fi
fi

if [ $bad -eq 0 ]
then
	echo "  ✓ лента: записей $count${note:+; $note}"
fi
exit $bad
