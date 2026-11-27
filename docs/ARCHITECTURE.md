# Architecture

## Overview
The system is a modular Laravel monolith. PostgreSQL is the single source of truth. Redis provides cache, sessions and the job queue. Laravel Reverb pushes committed queue changes to waiting-room displays. There is no separate front-end application: staff screens are Livewire components rendered by Blade.

```
 Staff browser ──HTTP/Livewire──▶ Laravel (web) ──▶ Actions ──▶ PostgreSQL
                                      │                │
                                      │        after commit: QueueTicketChanged
                                      │                ▼
                                      │        Listener → queued DisplayUpdated ──▶ Redis queue
                                      │                                               │
                                      │                                        queue worker
                                      │                                               ▼
 Display browser ◀──WebSocket (private channel)──────────────────────────── Laravel Reverb
        └──HTTP: pair, snapshot, channel auth──▶ DisplayController
```

## Technology
| Concern | Choice |
|---|---|
| Runtime | PHP 8.3, Laravel 13 |
| UI | Livewire 4 (class-based components), Blade, Flux (free), Tailwind CSS 4, Alpine |
| Authentication | Laravel Fortify: login, password reset, TOTP two-factor, password confirmation. No registration. |
| Authorisation | Spatie Laravel Permission, plus Laravel policies |
| Database | PostgreSQL 16 with `btree_gist` |
| Cache, sessions, jobs | Redis 7 |
| Real-time | Laravel Reverb (Pusher protocol), Laravel Echo and `pusher-js`, loaded only on display pages |
| Exports | OpenSpout (XLSX), native CSV |
| Quality | Pest 4, Larastan level 7, Pint, Node's built-in test runner |

## Code layout
| Path | Contents |
|---|---|
| `app/Actions/{Domain}` | Transactional use cases, one class per operation (`CheckInAppointment`, `CallNextTicket`, `SignClinicalNote`…) |
| `app/Livewire/{Domain}` | Screens. They only bind form fields and call actions. |
| `app/Policies` | Authorisation for each model, including department and care-relationship scope |
| `app/Services/Queue` | Ticket issuing, sequence allocation, call ordering, triage routing, event recording |
| `app/Services/Clinical` | `ClinicalAccess`: the care-relationship and encounter rules |
| `app/Services/Display` | Display sessions, the sanitised payload and the reconnect snapshot |
| `app/Services/Reporting` | Report queries, filters and export tables |
| `app/Services/ClinicSettings.php` | Database-backed settings that override `config/clinic.php` |
| `app/Support` | `ClinicTime` (operational date), `DailyNumber` (daily numbers), `ConstraintViolation` (maps database errors to validation errors), readiness checks |
| `app/Enums` | Statuses, roles, permissions, priorities, note types |
| `app/Events`, `app/Listeners` | `QueueTicketChanged` (after commit) → `BroadcastQueueChangeToDisplays` → `DisplayUpdated` |
| `app/Http/Middleware` | `EnsureUserIsActive`, `RequireTwoFactor`, `SecurityHeaders` |
| `app/Console/Commands` | `staff:create`, `appointments:mark-no-shows`, `queue:close-day`, `clinic:preflight`, `clinic:health` |
| `resources/js/display.js` | Display client: state, deduplication and the speech announcer |
| `scripts/` | Backup and restore drill |
| `tests/{Feature,Unit,Concurrency,js}` | Test suites |

## Request flow
1. **Route.** The route applies authentication, `EnsureUserIsActive`, `RequireTwoFactor`, `SecurityHeaders` and a `can:` permission check.
2. **Livewire component.** The component validates the form shape and calls a single action.
3. **Action.** The action authorises the acting user against the policy (`Gate::forUser`), opens a database transaction, takes the row locks it needs, enforces the domain rules and writes the audit or queue event rows.
4. **PostgreSQL.** Constraints and triggers act as the final guard. A unique or exclusion violation caused by a concurrent request is mapped back to a field error, and the transaction rolls back.
5. **After commit.** Domain events that implement `ShouldDispatchAfterCommit` fire. Display broadcasts are queued, so a WebSocket outage never fails a staff action.

## Design rules
- **Authorise twice.** Routes and components check permissions, and every action checks again for the acting user. An action called from anywhere, including the console, is safe.
- **Database-enforced invariants.** Anything that must never happen is a constraint or a trigger, not just application code: double bookings, duplicate open visits, two active tickets at one counter, edits to signed notes, changes to audit or queue history.
- **No deletes of history.** Master data is deactivated, never deleted. Foreign keys use `RESTRICT`, and clinical and audit history never cascades away.
- **Time.** Timestamps are stored in UTC as `timestamptz`. Operational dates (ticket resets, daily numbers, reports) are derived from the clinic timezone through `ClinicTime`.
- **Sanitised display model.** Displays read only `DisplayPayload` and `DisplaySnapshot`: a ticket number, a room and timestamps. They never use the domain models.
- **No implicit superuser.** Administrators manage configuration. They have no clinical or reception access unless they are given those roles too.

## Runtime processes
| Process | Command | Required for |
|---|---|---|
| Web | PHP-FPM or `php artisan serve` | All staff screens |
| Queue worker | `php artisan queue:work` | Display broadcasts and other queued jobs |
| WebSocket server | `php artisan reverb:start` | Live display updates |
| Scheduler | `php artisan schedule:work` (or cron) | Hourly no-show marking and end-of-day ticket closing |

Locally, `composer dev` runs all of these except the scheduler. Docker Compose defines `app`, `queue`, `reverb`, `node`, `pgsql` and `redis`.

## Deployment
Deployment is manual; there is no automatic deploy. CI (GitHub Actions) runs formatting, static analysis, the JavaScript tests, migrations, the backup and restore drill, and the Pest suite against PostgreSQL 16 and Redis 7. See [operations/DEPLOYMENT.md](operations/DEPLOYMENT.md).
