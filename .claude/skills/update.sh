#!/usr/bin/env bash
#
# Навыки линейки shef.* из bx-shef/skills (с 2026-10: туда их перенесли из
# bx-shef/options, см. README bx-shef/options).
#
#   .claude/skills/update.sh            взять свежий main, разложить, записать SOURCE
#   .claude/skills/update.sh <коммит>   то же для конкретного коммита
#   .claude/skills/update.sh --verify   копия сходится с коммитом из SOURCE (CI)
#
# Почему не `npx skills add`: утилита ставит не все навыки набора и не
# обновляет уже лежащие — копия молча отстаёт. Здесь источник зафиксирован
# коммитом в SOURCE: CI не краснеет от чужого пуша, а обновление — осознанный
# PR с новым коммитом.
#
# Копия по-прежнему сверяется с MANIFEST (sync.sh --check); локальные навыки
# и файлы этого репозитория — в LOCAL.MANIFEST, их скрипт не трогает.

set -euo pipefail

SKILLS_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO='https://github.com/bx-shef/skills'
SOURCE_FILE="$SKILLS_DIR/SOURCE"

fetch()
{
	local ref="$1" dir
	dir="$(mktemp -d)"
	git clone -q "$REPO" "$dir/src"
	git -C "$dir/src" checkout -q "$ref"
	# Манифест источника — тем же sync.sh, что сверяет копию
	cp "$SKILLS_DIR/sync.sh" "$dir/src/skills/sync.sh"
	rm -f "$dir/src/skills/LOCAL.MANIFEST"
	"$dir/src/skills/sync.sh" --manifest >/dev/null
	rm "$dir/src/skills/sync.sh"
	echo "$dir/src"
}

case "${1-}" in
	--verify)
		sha="$(grep -oE '^[0-9a-f]{40}' "$SOURCE_FILE")"
		src="$(fetch "$sha")"
		"$SKILLS_DIR/sync.sh" --check "$src/skills/MANIFEST"
		;;
	*)
		src="$(fetch "${1:-main}")"
		sha="$(git -C "$src" rev-parse HEAD)"
		for dir in "$src"/skills/*/
		do
			name="$(basename "$dir")"
			rm -rf "${SKILLS_DIR:?}/$name"
			cp -r "$dir" "$SKILLS_DIR/$name"
		done
		cp "$src/skills/MANIFEST" "$SKILLS_DIR/MANIFEST"
		printf '%s  %s\n' "$sha" "$REPO" > "$SOURCE_FILE"
		"$SKILLS_DIR/sync.sh" --local
		"$SKILLS_DIR/sync.sh" --check
		echo "навыки из $REPO @ $sha"
		;;
esac
