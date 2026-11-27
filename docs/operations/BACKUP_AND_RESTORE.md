# Backup and restore

## Backups (`scripts/backup.sh`)
- Uses `pg_dump --format=custom` piped through `openssl enc -aes-256-cbc -pbkdf2 -iter 200000`.
- Produces `patient-queue-<UTC>.dump.enc`, a `.sha256` checksum, and a `.manifest.json` of row counts per table.
- **Client tools must match the server's major version.** A newer `pg_dump` writes settings an older server rejects (for example, `pg_dump` 17 emits `SET transaction_timeout`, which PostgreSQL 16 cannot restore). Both scripts (`scripts/pg-tools.sh`) read the server version and use `PG_BIN_DIR`, then `/usr/lib/postgresql/<major>/bin`, then `PATH`, and refuse to run if the major versions differ. Install `postgresql-client-16` on the backup host (from the PostgreSQL apt repository if the OS no longer packages 16). CI found this: the runner's default client was newer than the database.
- Needs `BACKUP_DIR`, `BACKUP_PASSPHRASE_FILE` (mode 600) and the `PG*` connection variables.
- Deletes local files older than `BACKUP_RETENTION_DAYS` (default 30, to be set from the approved retention policy).
- Prints no data and never the passphrase.

**Proposed schedule** (clinic to approve recovery objectives):
- Nightly backups, plus one before each deploy.
- Copy them off-site (a different provider or region) with versioning or object lock.
- Proposed RPO: 24 h, or less with PostgreSQL WAL archiving or point-in-time recovery from the managed database provider.
- Proposed RTO: 4 h.

**Key custody:** keep the passphrase in a secrets manager, separate from the backups, with at least two named custodians, and rotate it yearly. Rotation does not re-encrypt old backups, so keep old passphrases for as long as those backups are retained.

## Restore drill (`scripts/restore-drill.sh <file.dump.enc>`)
1. Verifies the SHA-256 checksum.
2. Decrypts the file. A wrong passphrase or a corrupt file fails here.
3. Restores into a scratch database (`DRILL_DATABASE`, default `patient_queue_restore_drill`).
4. Verifies the safeguards:
   - the 5 immutability triggers;
   - the overlap exclusion constraints and the one-open-visit and one-ticket-per-counter indexes;
   - `btree_gist` and `patient_number_seq`;
   - that `audit_logs` still rejects an UPDATE.
5. Checks that every table has at least the row count recorded in the manifest.
6. Drops the scratch database, unless `KEEP_DRILL_DATABASE=1`.

The drill runs in **CI on every push**: a backup of the freshly migrated and seeded test database is drilled. Run it **monthly on production backups**, on a separate host, and record each run:

| Date | Backup file | Operator | Result | Duration | Notes |
|---|---|---|---|---|---|
| 2026-11-26 | local dev backup (synthetic data) | engineering | PASSED — 34 tables, triggers verified | < 1 min | Development environment only; repeat on production infrastructure before go-live |

## Full restore (disaster recovery)
1. Declare the incident (RUNBOOKS.md) and put the application in maintenance mode.
2. Run the restore drill on the chosen backup first, to prove it is good.
3. Restore into the production database with `pg_restore --clean --if-exists --no-owner`, using the migration role.
4. Run `php artisan migrate --force` (no-op if the backup is current), then `php artisan clinic:preflight` and `php artisan clinic:health`.
5. Record the data-loss window, and tell the clinic which period must be re-entered from paper.
