#!/usr/bin/env bash
# Rehearsal of the v1 -> v2 (multi-organisation) migration on a DISPOSABLE COPY of a production
# database. Implements docs/ecopa-multi-org-migration.md §4a. Never point it at production:
# it puts the application in maintenance mode, runs `migrate --force` and links the organisation.
#
#   export DB_CONNECTION=pgsql DB_HOST=... DB_PORT=... DB_DATABASE=<copy> DB_USERNAME=<owner> DB_PASSWORD=...
#   export AKUNTA_REHEARSAL_CONFIRM=<copy>            # must equal DB_DATABASE (guards against the wrong database)
#   export REHEARSAL_ORG_ID=<ecopa organisation id> REHEARSAL_ORG_SLUG=<slug>
#   export LARAVEL_STORAGE_PATH=<copy of the production storage folder>   # attachments + framework dirs
#   REHEARSAL_V1_DIR=<v1 application folder>   (or REHEARSAL_REPORTS_BEFORE=<reports snapshot taken with v1>)
#   optional: PG_BIN=<postgres bin dir> REHEARSAL_DIR=<report folder> REHEARSAL_YEAR=2026 REHEARSAL_SKIP_SMOKE=1
#   bash scripts/migration-rehearsal.sh
#
# Output: REHEARSAL_DIR/rehearsal.log (commands, durations, exit codes) plus the snapshots. Exit code 0
# only when every step passed. Dump and restore use the database role of DB_USERNAME.
set -u

cd "$(dirname "$0")/.." || exit 2
: "${DB_DATABASE:?set DB_DATABASE to the disposable copy}"
: "${REHEARSAL_ORG_ID:?set REHEARSAL_ORG_ID}"
: "${REHEARSAL_ORG_SLUG:?set REHEARSAL_ORG_SLUG}"
if [ "${AKUNTA_REHEARSAL_CONFIRM:-}" != "$DB_DATABASE" ]; then
    echo "Refusing to run: AKUNTA_REHEARSAL_CONFIRM must equal DB_DATABASE ($DB_DATABASE)." >&2
    exit 2
fi

PGB="${PG_BIN:+$PG_BIN/}"
DIR="${REHEARSAL_DIR:-storage/app/migration-reports/rehearsal-$(date +%Y%m%d-%H%M%S)}"
YEAR="${REHEARSAL_YEAR:-$(date +%Y)}"
HOST="${DB_HOST:-127.0.0.1}"; PORT="${DB_PORT:-5432}"; USER_="${DB_USERNAME:-postgres}"
export PGPASSWORD="${DB_PASSWORD:-}"
mkdir -p "$DIR"
LOG="$DIR/rehearsal.log"
: > "$LOG"
FAILED=0
SUMMARY=()

now_ms() { date +%s%N | cut -b1-13; }

# step <label> <command...>: run, time and log; a failing step marks the rehearsal as failed.
step() {
    local label="$1"; shift
    local start; start=$(now_ms)
    echo "### $label" | tee -a "$LOG"
    echo "\$ $*" >> "$LOG"
    "$@" >> "$LOG" 2>&1
    local rc=$?
    local ms=$(( $(now_ms) - start ))
    if [ $rc -ne 0 ]; then FAILED=1; fi
    SUMMARY+=("$(printf '%-52s exit=%-3s %7s ms' "$label" "$rc" "$ms")")
    echo "    exit=$rc, ${ms} ms" | tee -a "$LOG"
    return $rc
}

psqlq() { "${PGB}psql" -h "$HOST" -p "$PORT" -U "$USER_" -At -d "$1" -c "$2"; }

restore_test() {
    local copy="${DB_DATABASE}_restore_test"
    psqlq postgres "drop database if exists \"$copy\"" && psqlq postgres "create database \"$copy\"" || return 1
    "${PGB}pg_restore" -h "$HOST" -p "$PORT" -U "$USER_" -d "$copy" --no-owner "$DIR/before.dump" || return 1
    local bad=0
    for t in tenants entities users roles user_app_assignments accounts periods journals journal_entries attachments fiscal_adjustments audit_log; do
        local a b
        a=$(psqlq "$DB_DATABASE" "select count(*) from $t" 2>/dev/null) || continue
        b=$(psqlq "$copy" "select count(*) from $t")
        echo "restore check $t: original=$a restored=$b"
        [ "$a" = "$b" ] || bad=1
    done
    psqlq postgres "drop database \"$copy\"" > /dev/null
    return $bad
}

step "backup (pg_dump -Fc)" "${PGB}pg_dump" -h "$HOST" -p "$PORT" -U "$USER_" -Fc -f "$DIR/before.dump" "$DB_DATABASE" || exit 1
sha256sum "$DIR/before.dump" | tee -a "$LOG"
step "restore test of the dump into a scratch database" restore_test || { echo "Backup does not restore: stop here." >&2; exit 1; }
step "snapshot before: ecopa:migration-report" php artisan ecopa:migration-report --snapshot="$DIR/migration-before.json"
# The "before" numbers must come from the v1 build (the new build needs tenant_id to read the ledger).
# REHEARSAL_V1_DIR = the v1 application folder (its vendor installed, same DB_* env); without it,
# supply REHEARSAL_REPORTS_BEFORE = a reports snapshot taken with v1.
if [ -n "${REHEARSAL_V1_DIR:-}" ]; then
    step "snapshot before: reports with the v1 build" php scripts/report-snapshot.php "$DIR/reports-before.json" --year="$YEAR" --root="$REHEARSAL_V1_DIR"
elif [ -n "${REHEARSAL_REPORTS_BEFORE:-}" ]; then
    cp "$REHEARSAL_REPORTS_BEFORE" "$DIR/reports-before.json"
else
    echo "Set REHEARSAL_V1_DIR or REHEARSAL_REPORTS_BEFORE to prove the reports are identical." >&2; exit 2
fi
step "maintenance mode on" php artisan down
step "migrate --force" php artisan migrate --force
step "ecopa:link-organization --dry-run" php artisan ecopa:link-organization "$REHEARSAL_ORG_ID" --slug="$REHEARSAL_ORG_SLUG" --dry-run
step "ecopa:link-organization" php artisan ecopa:link-organization "$REHEARSAL_ORG_ID" --slug="$REHEARSAL_ORG_SLUG"
step "verify: migration-report --compare --check-files" php artisan ecopa:migration-report --compare="$DIR/migration-before.json" --snapshot="$DIR/migration-after.json" --check-files
step "verify: reports identical to before" php scripts/report-snapshot.php "$DIR/reports-after.json" --year="$YEAR" --compare="$DIR/reports-before.json"
step "re-run: migrate --force (must change nothing)" php artisan migrate --force
step "re-run: ecopa:link-organization (must change nothing)" php artisan ecopa:link-organization "$REHEARSAL_ORG_ID" --slug="$REHEARSAL_ORG_SLUG"
step "re-run: migration-report --compare --check-files" php artisan ecopa:migration-report --compare="$DIR/migration-before.json" --check-files
if [ "${REHEARSAL_SKIP_SMOKE:-0}" != "1" ]; then
    step "smoke test per user kind (tests/Rehearsal)" env AKUNTA_REHEARSAL=1 APP_ENV=testing php vendor/bin/pest tests/Rehearsal
fi
step "maintenance mode off" php artisan up

echo
echo "=== Rehearsal summary ($DB_DATABASE, organisation $REHEARSAL_ORG_ID) ===" | tee -a "$LOG"
printf '%s\n' "${SUMMARY[@]}" | tee -a "$LOG"
if [ $FAILED -ne 0 ]; then
    echo "RESULT: FAILED - see $LOG. Roll back by restoring $DIR/before.dump." | tee -a "$LOG"
    exit 1
fi
echo "RESULT: PASSED. Artefacts in $DIR" | tee -a "$LOG"
