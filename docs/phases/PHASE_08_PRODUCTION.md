# Phase 08 — Production readiness
Status: Engineering work implemented and verified locally; release gate open (clinic and jurisdiction sign-off, production restore drill, CI on `main`)
Dependencies: 01–07
## Objective and scope
Review privacy, threat model, access logs, backups, restore, monitoring, deployment and operational runbooks.
## Implementation checklist
- [x] Review privacy, threat model, access logs, backups, restore, monitoring, deployment and operational runbooks (engineering review in `docs/operations/`; clinic decisions listed there)
- [x] Implement applicable migrations/models: audit_logs, settings; review all constraints. (`audit_logs` came from phase 03 and now also records patient reads and changes, settings and exports; `settings` added; constraint inventory test added)
- [x] Implement applicable Livewire screens/routes: /settings; deployment and recovery documentation. (Clinic settings at `/settings/clinic`, because `/settings/*` already holds each user's own profile and security pages; audit log at `/audit-log`)
- [x] Enforce policies, validation and domain rules in linked architecture documents.
- [x] Add feature/unit tests and PostgreSQL integration tests where applicable.
- [x] Run formatting, static analysis and test suite; document results.
## Acceptance criteria
CI green, concurrency and authorization tests pass, restore drill succeeds, jurisdiction-specific compliance and clinic policy approved before real data.
## What was built
- **Clinic settings** (`settings` table, `App\Services\ClinicSettings`, `/settings/clinic`, admin `settings.manage`).
  - Settings: timezone, late grace, early eligibility, booking horizon, walk-in safeguard, maximum recalls, require two-factor.
  - Stored values override `config/clinic.php` at boot (cached, invalidated on save). Values are validated.
  - Each change is audited with from/to values; unchanged values are not logged.
- **Enforced 2FA** (`RequireTwoFactor`). When enabled, staff without confirmed TOTP can only reach security settings, password confirmation, the 2FA endpoints and logout.
  - It is also Livewire persistent middleware, so component updates are re-checked against the page they came from. A `/livewire*` exemption was considered and rejected because it would have been a bypass.
- **Security headers** (`SecurityHeaders`): `nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`, a restrictive `Permissions-Policy`, HSTS over HTTPS, and `Cache-Control: no-store, private` on every authenticated page. Content-Security-Policy is a recommended follow-up (see the threat model).
- **Access logs.**
  - Patient demographics opened on the record or edit page are audited (`patient.viewed`). Registration and changes are audited too, recording field names only, never values.
  - Administrators review the append-only log at `/audit-log`, filtered by date, action prefix and outcome (new `audit.view` permission).
- **`php artisan clinic:preflight`**: 20 automated checks, exiting non-zero on any FAIL.
  - Configuration: production environment, debug off, HTTPS, secure and encrypted sessions, idle timeout ≤ 60, PostgreSQL (TLS as a warning), asynchronous queue, persistent cache, Reverb keys, real mailer, 2FA required, valid timezone.
  - Data: no `@example.test` demo accounts, an active administrator who uses 2FA.
  - Database: the immutability triggers are installed and all migrations are applied.
  - It also lists 5 MANUAL sign-offs that code cannot verify.
- **`php artisan clinic:health`**: JSON health of the database, Redis, queue backlog, failed jobs and WebSocket server, for monitoring.
- **Backups and restore drill** (`scripts/backup.sh`, `scripts/restore-drill.sh`): an encrypted custom-format dump with checksum and row-count manifest. The drill verifies the checksum, decrypts, restores to a scratch database, checks the triggers, key constraints, extension and sequence, confirms the append-only trigger still rejects updates, and compares row counts. **The drill now runs in CI on every push.**
- **Operations documentation** (`docs/operations/`): THREAT_MODEL, PRIVACY_REVIEW (data inventory and the decisions required), DEPLOYMENT (manual, no automatic deploy), BACKUP_AND_RESTORE (with a drill log), MONITORING, RUNBOOKS.
## Verification results (2026-11-26)
- `composer test`: Pint passed, PHPStan level 7 passed, Pest passed — 414 tests, 1,501 assertions, on PostgreSQL 16, including 26 new phase-08 tests. `npm test`: 18 passed.
- Phase-08 tests cover:
  - settings apply, persist, override at boot, validation, audit diff and admin-only access;
  - 2FA redirect, allowed routes, enrolled users and the persistent-middleware registration;
  - headers and `no-store`;
  - patient read and change audits with no values in metadata;
  - audit-log access and filters;
  - preflight pass on a production-like configuration, failures on unsafe configuration and demo or no-2FA admins, failure on a dropped trigger, and JSON output;
  - health JSON;
  - an inventory test that every relied-upon trigger, constraint and extension exists.
- **Restore drill (local, synthetic data):**
  - backup 136 KB; checksum, decryption and restore OK; 5 triggers and 6 key constraints/indexes present;
  - `audit_logs` rejected an UPDATE after restore; 34 tables matched or exceeded the manifest → **PASSED**.
  - Negative cases: a wrong passphrase, a tampered file (checksum mismatch) and a backup of a database whose audit trigger had been dropped all **FAILED** as intended. The encrypted file contains no readable text.
- `clinic:preflight` on the development environment correctly reports 8 blocking issues: local env, debug, HTTP, insecure cookie, log mailer, 2FA not required, demo accounts, admin without 2FA.
## Release gate — open items (not engineering-completable)
- [ ] CI green on `main` with phases 05–08 merged (the drill now runs in CI)
- [ ] Restore drill on the production backup target, recorded in BACKUP_AND_RESTORE.md
- [ ] Jurisdiction, data residency and applicable health-privacy law confirmed
- [ ] Retention, deletion, patient-rights and breach-response policies approved
- [ ] Clinical access and emergency access policy approved
- [ ] Queue, grace and no-show parameters approved
- [ ] Independent security review or penetration test (recommended)
- [ ] `clinic:preflight` shows no FAIL on production
## Completion
- [ ] Acceptance criteria verified and implementation plan updated. (Blocked on the release gate above; no real patient data before then.)
