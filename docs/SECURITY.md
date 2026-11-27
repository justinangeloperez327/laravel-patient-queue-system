# Security and privacy

This document lists the security controls in the system and the principles behind them. The [threat model](operations/THREAT_MODEL.md) analyses threats and residual risks. The [privacy review](operations/PRIVACY_REVIEW.md) lists the data held and the decisions the clinic must make.

> The project makes no claim of regulatory compliance. Hosting jurisdiction, data residency, retention, deletion, patient rights and breach response must be reviewed with the clinic and the applicable health-privacy law before production use.

## Repository hygiene
- This is a public repository: fixtures and seeders use synthetic data only.
- Never commit real patient records, credentials, database dumps or production configuration. `.env` files are ignored, and `.env.example` holds placeholders only.

## Authentication
- Staff-only accounts are provisioned by administrators or with `php artisan staff:create`. There is no public registration.
- TOTP two-factor authentication is available to every user. It becomes mandatory when **Require two-factor** is enabled, which `clinic:preflight` requires in production. Staff without confirmed 2FA can reach only the security settings, the 2FA endpoints and logout. The check is also Livewire persistent middleware, so component requests cannot bypass it.
- Login is rate-limited (5 attempts per minute per account and IP). Inactive accounts are refused with a generic error and signed out on their next request.
- Sessions use secure, encrypted cookies in production, with an idle timeout of 60 minutes or less (checked by preflight). Opening the account security settings (password and two-factor) requires password confirmation.

## Authorisation
- Role-based permissions, enforced in routes, Livewire components and again inside every action. See [Roles and permissions](ROLES_AND_PERMISSIONS.md).
- Access is limited to assigned departments, clinical history is limited to current care relationships, and the default is deny.

## Clinical record integrity
- PostgreSQL triggers make signed clinical notes immutable. Updates, deletes and un-signing are all rejected.
- Amendments are append-only, signed and attributed to their author.
- Vital signs, queue events and audit logs are append-only.

## Audit logging
- The append-only `audit_logs` table records the following:
  - clinical reads and writes;
  - patient record views and changes;
  - refused access attempts;
  - appointment lifecycle changes;
  - settings changes, with old and new values;
  - display device management;
  - report exports.
- Metadata contains IDs and field names only, never clinical text, patient identifiers or demographic values.
- Administrators review the log at `/audit-log`.

## Web hardening
- Every response sends `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`, a restrictive `Permissions-Policy`, and HSTS over HTTPS. Authenticated pages also send `Cache-Control: no-store, private`.
- Laravel provides CSRF protection on all state-changing requests.
- Exports and display pairing are rate-limited.
- Exported cells that start with `= + - @` or a control character are prefixed, which neutralises spreadsheet formula injection.
- A Content-Security-Policy is a recommended follow-up.

## Public displays
- Payloads and announcements contain a ticket number and a room only: no names, numbers, dates of birth or clinical data.
- Devices pair with a random one-time key. Only its SHA-256 hash is stored, and the key is never placed in a URL or a log.
- Each device listens on its own private channel. Deactivating the device or rotating its key revokes every paired screen immediately.

## Data protection
- TLS in transit to clients and to PostgreSQL (preflight warns when database TLS is not configured).
- Backups are encrypted with AES-256 (PBKDF2, 200,000 iterations) and checksummed, and they are restore-tested by `scripts/restore-drill.sh`, including in CI.
- Encryption at rest for the database and storage volumes is the hosting provider's responsibility and should be confirmed.
- Secrets live only in the environment or a secrets manager, and should be rotated on a schedule. See the [runbooks](operations/RUNBOOKS.md).

## Production verification
`php artisan clinic:preflight` checks the following and fails on any unsafe value:
- configuration: environment, debug mode, HTTPS, session security, database, queue, cache, Reverb, mailer, 2FA and timezone;
- data: no demo accounts, and an active administrator who uses 2FA;
- database: the immutability triggers and the migrations.

It also lists the manual sign-offs that code cannot verify.
