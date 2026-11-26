# Deployment runbook

There is **no automatic deployment** (AGENTS.md). Deploys are manual, reviewed, and performed by an authorized operator after CI is green on `main`.

## Target architecture
- **Application:** PHP 8.3 (FPM behind nginx or Caddy) serving Laravel over HTTPS only.
- **Database:** PostgreSQL 16 with the `btree_gist` extension available, TLS required (`DB_SSLMODE=require` or stricter). The application role should not be a superuser; the extension and triggers are created by the migration role.
- **Redis 7** for cache, sessions and queues.
- **Long-running processes**, supervised (systemd or supervisord):
  - `php artisan queue:work --tries=3` — queued display broadcasts and mail;
  - `php artisan reverb:start` — WebSocket server, behind the TLS proxy as `wss://`;
  - `php artisan schedule:work`, or cron running `php artisan schedule:run` every minute — no-show sweep and end-of-day queue close.
- **Outbound mail (SMTP)** for staff invitations and password resets.

## Environment (production `.env`, never committed)
- `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`, `APP_KEY` generated once.
- `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, `SESSION_LIFETIME=30` (≤ 60).
- `DB_*` with `DB_SSLMODE=require`; `REDIS_*`; `QUEUE_CONNECTION=redis`; `CACHE_STORE=redis`; `SESSION_DRIVER=redis`.
- `BROADCAST_CONNECTION=reverb` and `REVERB_*` keys generated for production (`php artisan reverb:install` on the server), with `REVERB_SCHEME=https`.
- `MAIL_*` with a real mailer.
- `CLINIC_TIMEZONE`, and `CLINIC_REQUIRE_TWO_FACTOR=true` (also settable in Clinic settings).

## First deployment
1. Provision PostgreSQL, Redis, TLS certificates and the supervisor configuration above.
2. Deploy the tagged commit:
   1. `composer install --no-dev --optimize-autoloader`
   2. `npm ci && npm run build`
3. Run `php artisan migrate --force`, then `php artisan db:seed --class=RolesAndPermissionsSeeder --force`. **Do not** run the full `DatabaseSeeder`: its demo data only loads under `APP_ENV=local`.
4. Create the first administrator with `php artisan staff:create --role=admin` (password prompted), then sign in and enable 2FA.
5. Run `php artisan config:cache route:cache view:cache`.
6. Run `php artisan clinic:preflight`. It must report no FAIL. Complete the MANUAL items with the clinic.
7. Configure backups and perform a restore drill on the production backup target (BACKUP_AND_RESTORE.md).
8. Set up monitoring (MONITORING.md).

## Routine deployment
1. Confirm CI is green on the commit and read the migration diff.
2. Take an on-demand backup (`scripts/backup.sh`).
3. Run `php artisan down --render="errors::503"`. Schedule deploys outside clinic hours, because a deploy interrupts the queue.
4. Update the code; run `composer install --no-dev -o`, `npm ci && npm run build`, `php artisan migrate --force`, and `php artisan optimize`.
5. Run `php artisan queue:restart` and restart Reverb.
6. Run `php artisan up`, `php artisan clinic:health` and `php artisan clinic:preflight`.

## Rollback
- If no migration ran, redeploy the previous tag.
- If migrations ran, prefer a forward fix. `migrate:rollback` is available for the latest batch, but **never** roll back a migration that dropped data. Restore from the pre-deploy backup only with the clinic's agreement: it discards everything recorded since.
