# Monitoring

| Signal | How | Alert when |
|---|---|---|
| Liveness | `GET /up` (Laravel health route) from an external uptime monitor | Not 200 for 2 minutes during clinic hours |
| Components | `php artisan clinic:health` every minute (cron) → JSON: database, Redis, queue backlog, failed jobs, WebSocket | Exit code ≠ 0; `websocket` is `down` (displays fall back to periodic snapshots); `failed_jobs` rising |
| Production configuration | `php artisan clinic:preflight` after every deploy and weekly | Any FAIL |
| Queue workers | Supervisor process status; `queue_backlog` | Worker down; backlog over 500 (`--max-backlog`) |
| Scheduler | `appointments:mark-no-shows` and `queue:close-day` run hourly | No recent run (check the scheduler log) |
| Errors | Laravel log (`LOG_CHANNEL=stack`, daily files) shipped to central logging | Error-rate spike |
| Security | Audit log: refused clinical access (`clinical.history.view` with outcome `denied`), `report.exported`, `settings.updated`, `display.key_rotated` | Unusual volume or out-of-hours activity, reviewed weekly by the clinic's privacy lead |
| Backups | Backup job exit status and file age; monthly restore drill | No new backup in 26 h; any failed drill |

Logs must not contain patient data. The application logs no request bodies. Keep `APP_DEBUG=false` so error pages never show data.
