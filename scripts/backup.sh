#!/usr/bin/env bash
# Encrypted PostgreSQL backup (docs/operations/BACKUP_AND_RESTORE.md).
#
# Writes <dir>/patient-queue-<UTC timestamp>.dump.enc (pg_dump custom format,
# AES-256-CBC with PBKDF2), a SHA-256 checksum and a manifest of row counts
# taken just before the dump. Never prints data or the passphrase.
#
# Required environment:
#   BACKUP_DIR              directory for backups (outside the web root)
#   BACKUP_PASSPHRASE_FILE  file holding the encryption passphrase (chmod 600)
#   PGHOST PGPORT PGUSER PGDATABASE (+ PGPASSWORD or ~/.pgpass)
# Optional:
#   PG_BIN_DIR              client tools matching the server's major version
#                           (auto-detected; see pg-tools.sh)
#   BACKUP_RETENTION_DAYS   delete local backups older than this (default 30;
#                           set per the clinic's approved retention policy)
set -euo pipefail
umask 077

: "${BACKUP_DIR:?BACKUP_DIR is required}"
: "${BACKUP_PASSPHRASE_FILE:?BACKUP_PASSPHRASE_FILE is required}"
: "${PGDATABASE:?PGDATABASE is required}"
[[ -r "$BACKUP_PASSPHRASE_FILE" ]] || { echo "Passphrase file is not readable." >&2; exit 1; }

# shellcheck source=pg-tools.sh
source "$(dirname "$0")/pg-tools.sh"
select_pg_tools

mkdir -p "$BACKUP_DIR"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
base="$BACKUP_DIR/patient-queue-$stamp"

# Row counts of every application table, recorded before the snapshot.
"${PG_BIN}psql" --no-psqlrc --quiet --tuples-only --no-align --set ON_ERROR_STOP=1 --command "
  select coalesce(json_object_agg(table_name, row_count order by table_name), '{}'::json)
  from (
    select c.relname as table_name,
           (xpath('/row/n/text()', query_to_xml(format('select count(*) as n from %I.%I', n.nspname, c.relname), false, true, '')))[1]::text::bigint as row_count
    from pg_class c join pg_namespace n on n.oid = c.relnamespace
    where n.nspname = 'public' and c.relkind = 'r'
  ) counts" > "$base.manifest.json"

"${PG_BIN}pg_dump" --format=custom --no-owner --no-privileges \
  | openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -pass "file:$BACKUP_PASSPHRASE_FILE" -out "$base.dump.enc"

sha256sum "$base.dump.enc" | awk '{print $1}' > "$base.dump.enc.sha256"

retention="${BACKUP_RETENTION_DAYS:-30}"
find "$BACKUP_DIR" -maxdepth 1 -name 'patient-queue-*' -type f -mtime "+$retention" -delete

echo "Backup written: $base.dump.enc ($(du -h "$base.dump.enc" | cut -f1))"
