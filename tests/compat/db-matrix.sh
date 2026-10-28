#!/bin/bash
# Cross-version check for Helpers::normalizeDumpCollations().
#
# Starts one Docker container per MySQL/MariaDB version, loads fixture.sql into each,
# dumps it with that server's own dump tool (same flags as `wp db export` in Pull/Push),
# then for every source -> target pair imports the raw dump and the normalized dump
# and compares a checksum of the row data. Containers are removed on exit.
#
# Usage: tests/compat/db-matrix.sh [name ...]   e.g. tests/compat/db-matrix.sh mysql80 maria118
set -u
cd "$(dirname "$0")"

# name image platform. mysql:5.7 has no arm64 image, so it runs under emulation.
ALL="mysql57:mysql:5.7:linux/amd64 mysql80:mysql:8.0: mysql84:mysql:8.4: mysql9:mysql:9:
maria106:mariadb:10.6: maria1011:mariadb:10.11: maria114:mariadb:11.4: maria118:mariadb:11.8:"

WORK=$(mktemp -d)
NAMES=""
for entry in $ALL; do
    name=${entry%%:*}
    if [ $# -eq 0 ] || [[ " $* " == *" $name "* ]]; then NAMES="$NAMES $name"; fi
done

cleanup() {
    for n in $NAMES; do docker rm -f wpsync-compat-$n >/dev/null 2>&1; done
    rm -rf "$WORK"
}
trap cleanup EXIT

client() { case $1 in maria*) echo "mariadb -uroot -ppw";; mysql57) echo "mysql -uroot";; *) echo "mysql -uroot -ppw";; esac; }
# --set-gtid-purged=OFF: MySQL images with GTID on add a GTID_PURGED line that breaks
# imports for reasons unrelated to collations (see .plans/issues.md).
dumper() { case $1 in maria*) echo "mariadb-dump -uroot -ppw";; mysql57) echo "mysqldump -uroot";; *) echo "mysqldump -uroot -ppw --set-gtid-purged=OFF";; esac; }
run() { local n=$1; shift; docker exec -i wpsync-compat-$n "$@"; }

echo "Starting containers..."
for entry in $ALL; do
    IFS=: read -r name repo tag platform <<< "$entry"
    [[ " $NAMES " == *" $name "* ]] || continue
    # mysql:5.7's entrypoint fails under emulation with a root password, so use an empty one.
    if [ $name = mysql57 ]; then env="-e MYSQL_ALLOW_EMPTY_PASSWORD=yes"; else env="-e MYSQL_ROOT_PASSWORD=pw -e MARIADB_ROOT_PASSWORD=pw"; fi
    docker run -d --name wpsync-compat-$name ${platform:+--platform $platform} $env $repo:$tag >/dev/null || exit 1
done
for n in $NAMES; do
    for _ in $(seq 90); do run $n $(client $n) -e 'select 1' >/dev/null 2>&1 && break; sleep 2; done
done

checksum() {
    run $1 $(client $1) -N $2 -e "SELECT SHA2(GROUP_CONCAT(CONCAT_WS('|',id,option_name,option_value,IFNULL(note,'NULL')) ORDER BY id SEPARATOR '#'),256) FROM wp_options" 2>/dev/null
}

echo; echo "== Sources"
for n in $NAMES; do
    run $n $(client $n) < fixture.sql 2>/dev/null
    run $n $(dumper $n) --single-transaction --quick --lock-tables=false src 2>/dev/null > "$WORK/dump-$n.sql"
    run $n $(client $n) -N -e 'SHOW COLLATION' 2>/dev/null > "$WORK/collations-$n.txt"
    version=$(run $n $(client $n) -N -e 'select version()' 2>/dev/null)
    row_per_line=$(grep -qE '^\(' "$WORK/dump-$n.sql" && echo yes || echo no)
    leaks=$(grep -vE '^(INSERT |REPLACE |\()' "$WORK/dump-$n.sql" | grep -c ZZDATA)
    echo "$n ($version): one row per line: $row_per_line | row data on schema lines: $leaks"
    checksum $n src > "$WORK/sum-$n.txt"
done

echo; echo "== Imports (source -> target)"
failures=0
for s in $NAMES; do for t in $NAMES; do
    [ $s = $t ] && continue
    run $t $(client $t) -e 'DROP DATABASE IF EXISTS dst; CREATE DATABASE dst' 2>/dev/null
    raw=$(run $t $(client $t) dst < "$WORK/dump-$s.sql" 2>&1 | grep -vE 'Warning|^-+$' | head -1)

    cp "$WORK/dump-$s.sql" "$WORK/norm.sql"
    remap=$(SUPPORTED="$WORK/collations-$t.txt" php normalize.php "$WORK/norm.sql" 2>&1 | sed 's/.*collation: //' | tr '\n' ' ')
    run $t $(client $t) -e 'DROP DATABASE IF EXISTS dst; CREATE DATABASE dst' 2>/dev/null
    err=$(run $t $(client $t) dst < "$WORK/norm.sql" 2>&1 | grep -vE 'Warning|^-+$' | head -1)

    if [ -n "$err" ]; then result="FAIL: $err"; failures=$((failures + 1))
    elif [ "$(checksum $t dst)" != "$(cat "$WORK/sum-$s.txt")" ]; then result="DATA DIFFERS"; failures=$((failures + 1))
    else result="ok"; fi
    echo "$s -> $t: raw $([ -z "$raw" ] && echo ok || echo fails) | normalized $result${remap:+ | remapped $remap}"
done; done

echo; echo "$failures failure(s)"
[ $failures -eq 0 ]
