#!/usr/bin/env bash
# Restore drill (docs/operations/BACKUP_AND_RESTORE.md): proves a backup can
# be decrypted and restored, and that the restored database keeps its
# safeguards. Restores into a scratch database, verifies, then drops it.
#
# Usage: scripts/restore-drill.sh <backup.dump.enc>
# Required environment: BACKUP_PASSPHRASE_FILE, PGHOST PGPORT PGUSER (+ PGPASSWORD)
# Optional: DRILL_DATABASE (default patient_queue_restore_drill), KEEP_DRILL_DATABASE=1,
#           PG_BIN_DIR (client tools matching the server; auto-detected)
set -euo pipefail
umask 077

backup="${1:?Usage: restore-drill.sh <backup.dump.enc>}"
: "${BACKUP_PASSPHRASE_FILE:?BACKUP_PASSPHRASE_FILE is required}"
drill_db="${DRILL_DATABASE:-patient_queue_restore_drill}"
manifest="${backup%.dump.enc}.manifest.json"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

fail() { echo "DRILL FAILED: $*" >&2; exit 1; }
# shellcheck source=pg-tools.sh
source "$(dirname "$0")/pg-tools.sh"
select_pg_tools --dbname postgres || fail "no PostgreSQL client tools match the server version"
sql() { "${PG_BIN}psql" --no-psqlrc --quiet --tuples-only --no-align --set ON_ERROR_STOP=1 --dbname "$drill_db" --command "$1"; }

echo "1/5 Verifying checksum"
if [[ -f "$backup.sha256" ]]; then
  [[ "$(sha256sum "$backup" | awk '{print $1}')" == "$(cat "$backup.sha256")" ]] || fail "checksum mismatch"
else
  fail "missing checksum file $backup.sha256"
fi

echo "2/5 Decrypting"
openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass "file:$BACKUP_PASSPHRASE_FILE" -in "$backup" -out "$work/backup.dump" \
  || fail "cannot decrypt (wrong passphrase or corrupt file)"

echo "3/5 Restoring into scratch database $drill_db"
"${PG_BIN}dropdb" --if-exists "$drill_db"
"${PG_BIN}createdb" "$drill_db"
"${PG_BIN}pg_restore" --no-owner --no-privileges --exit-on-error --dbname "$drill_db" "$work/backup.dump" || fail "pg_restore failed"

echo "4/5 Verifying safeguards"
for trigger in audit_logs_no_update_or_delete queue_events_no_update_or_delete clinical_notes_signed_immutable clinical_note_amendments_append_only vital_signs_no_update_or_delete; do
  [[ "$(sql "select count(*) from pg_trigger where tgname = '$trigger'")" == "1" ]] || fail "trigger $trigger missing"
done
for constraint in appointments_provider_no_overlap appointments_patient_no_overlap provider_schedules_no_overlap visits_one_open_per_patient queue_tickets_one_active_per_counter queue_tickets_one_unresolved_per_visit_service; do
  found="$(sql "select (select count(*) from pg_constraint where conname = '$constraint') + (select count(*) from pg_indexes where indexname = '$constraint')")"
  [[ "$found" -ge 1 ]] || fail "constraint/index $constraint missing"
done
[[ "$(sql "select count(*) from pg_extension where extname = 'btree_gist'")" == "1" ]] || fail "btree_gist extension missing"
# The append-only trigger must still reject changes after restore (row triggers need a row).
if [[ "$(sql "select count(*) from audit_logs")" != "0" ]]; then
  if "${PG_BIN}psql" --no-psqlrc --quiet --set ON_ERROR_STOP=1 --dbname "$drill_db" \
       --command "begin; update audit_logs set action = action; rollback;" >/dev/null 2>&1; then
    fail "audit_logs accepted an UPDATE after restore"
  fi
  echo "   audit_logs rejected an UPDATE (append-only trigger active)"
fi
pending_sequence="$(sql "select count(*) from pg_class where relname = 'patient_number_seq' and relkind = 'S'")"
[[ "$pending_sequence" == "1" ]] || fail "patient_number_seq missing"

echo "5/5 Comparing row counts with the backup manifest"
if [[ -f "$manifest" ]]; then
  restored="$(sql "
    select coalesce(json_object_agg(table_name, row_count order by table_name), '{}'::json)
    from (
      select c.relname as table_name,
             (xpath('/row/n/text()', query_to_xml(format('select count(*) as n from %I.%I', n.nspname, c.relname), false, true, '')))[1]::text::bigint as row_count
      from pg_class c join pg_namespace n on n.oid = c.relnamespace
      where n.nspname = 'public' and c.relkind = 'r'
    ) counts")"
  # Restored counts may be higher (writes between counting and dumping), never lower.
  short="$(sql "select string_agg(m.key || ': ' || m.value || ' -> ' || coalesce(r.value, 'missing'), ', ')
                from json_each_text('$(cat "$manifest")'::json) m
                left join json_each_text('$restored'::json) r on r.key = m.key
                where r.value is null or r.value::bigint < m.value::bigint")"
  [[ -z "$short" ]] || fail "rows missing after restore: $short"
  echo "   $(sql "select count(*) from json_each_text('$restored'::json)") tables match or exceed the manifest"
else
  echo "   (no manifest found; skipped)"
fi

if [[ "${KEEP_DRILL_DATABASE:-0}" != "1" ]]; then
  "${PG_BIN}dropdb" "$drill_db"
fi

echo "RESTORE DRILL PASSED for $(basename "$backup")"
