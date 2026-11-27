# Laravel Patient Queue System

[![tests](https://github.com/justinangeloperez327/laravel-patient-queue-system/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/justinangeloperez327/laravel-patient-queue-system/actions/workflows/tests.yml)

A patient flow and queue management system for a single outpatient clinic. It covers the visit from reception to discharge: registration, booked appointments and walk-ins, optional triage, numbered tickets for each service, live waiting-room displays with voice announcements, clinical documentation, and operational reporting.

> **Not approved for real patient data.** The software is feature-complete. Before a clinic uses it with real patients, the [production release gate](docs/phases/PHASE_08_PRODUCTION.md#release-gate--open-items-not-engineering-completable) still needs clinic, legal and privacy sign-off. Until then, use synthetic data only.

## Contents
- [Features](#features)
- [Technology](#technology)
- [Getting started](#getting-started)
- [Waiting-room displays](#waiting-room-displays)
- [Testing and quality](#testing-and-quality)
- [Operations](#operations)
- [Documentation](#documentation)
- [Project status](#project-status)

## Features

**Reception and scheduling**
- Patient registration with automatic patient numbers (`PT-######`) and a warning when the patient may already be registered.
- Appointments booked by reception against provider schedules. The database prevents double booking of a provider or a patient, even when two people book at the same moment.
- Check-in for appointments and walk-ins, with grace periods, no-show handling and protection against checking a patient in twice.

**Queue management**
- Each service numbers its tickets daily (`C-001`, `L-014`), restarting at the clinic's local midnight.
- Patients are called in a fixed, fair order: clinical urgency first, then appointments, then how long they have been eligible. Walk-ins who wait too long are moved up so they are not passed over indefinitely.
- Counters claim the next patient safely when several call at the same moment, and a double click never acts twice.
- Call, recall, skip, requeue, start, complete and transfer. A transfer issues the next service's ticket on the same visit in the same step.
- Every change to a ticket is recorded in an append-only event history.

**Clinical documentation**
- Optional triage, with vital signs checked against clinical ranges and units.
- SOAP and nursing notes, written as drafts and then signed. Signed notes cannot be changed; the database rejects any attempt. Corrections are added as signed, attributed amendments.
- Clinical history is visible only to clinicians who are currently caring for the patient. Every read is recorded in the audit log.

**Waiting-room displays**
- Live "now serving" screens over WebSockets (Laravel Reverb).
- Each screen is paired with a one-time key and can be revoked at once.
- Announcements are spoken in the browser, one at a time. Screens resynchronise after a dropped connection without repeating announcements.
- The public screens never show patient names or other identifiers.

**Reporting and administration**
- A live dashboard and operational reports: visits, distinct patients, wait and service times (mean, median and 90th percentile) and no-show rate, filtered by date, service and provider.
- CSV and Excel exports, restricted to authorised users, protected against spreadsheet formula injection, and audited.
- Management of departments, services, counters, staff, schedules, displays and clinic settings, plus an audit-log viewer.

**Security**
- Role-based access with no super-user. Access is denied unless a permission grants it, and work is limited to the departments a staff member is assigned to.
- Two-factor authentication can be made mandatory, alongside security headers, rate limits and session expiry.
- An append-only audit log, a production readiness check (`clinic:preflight`), and encrypted backups with an automated restore drill.

## Technology

| Area | Choice |
|---|---|
| Application | Laravel 13, PHP 8.3 |
| User interface | Livewire 4, Blade, Flux, Tailwind CSS 4 |
| Authentication and authorisation | Laravel Fortify (TOTP two-factor), Spatie Laravel Permission |
| Data | PostgreSQL 16 (exclusion constraints, partial unique indexes, triggers) |
| Cache, sessions and jobs | Redis 7 |
| Real-time | Laravel Reverb, Laravel Echo |
| Quality | Pest 4, Larastan (level 7), Laravel Pint, Node test runner |
| Delivery | Docker Compose, GitHub Actions |

## Getting started

### Prerequisites
- PHP 8.3 with the `pdo_pgsql` and `redis` extensions
- Composer
- Node.js 22
- PostgreSQL 16 and Redis 7, or Docker Compose

### Install

```bash
cp .env.example .env
```

Set the database credentials in `.env`, then:

```bash
composer install
```

```bash
php artisan key:generate
```

```bash
php artisan migrate --seed
```

```bash
npm ci && npm run build
```

```bash
composer dev
```

`composer dev` starts the web server, queue worker, Reverb WebSocket server, log viewer and Vite together (`php artisan dev:list` shows them).

### Using Docker Compose
`docker-compose.yml` defines the `app`, `queue`, `reverb`, `node`, `pgsql` and `redis` services. Its credentials are for local use only.

```bash
docker compose up -d
```

```bash
docker compose exec app php artisan migrate --seed
```

### Accounts
There is no public registration. Create staff accounts from the console; the password is always prompted:

```bash
php artisan staff:create --name="Clinic Admin" --email=admin@example.test --role=admin
```

When `APP_ENV=local`, the seeder also creates a synthetic demo clinic. It has departments, services, counters, a doctor's schedule and 12 fictional patients. It also has one account per role, all with the password `password`:

| Role | Account |
|---|---|
| Administrator | `admin@example.test` |
| Receptionist | `receptionist@example.test` |
| Nurse | `nurse@example.test` |
| Doctor | `doctor@example.test` |
| Service staff | `service-staff@example.test` |

Demo accounts must never exist in production. `clinic:preflight` fails if it finds them.

## Waiting-room displays
Screens receive updates through Laravel Reverb. Display broadcasts are queued, so a queue worker must be running.

Generate local keys once:

```bash
php artisan reverb:install
```

Set `BROADCAST_CONNECTION=reverb` in `.env` and rebuild the assets with `npm run build`. Then start the WebSocket server and the queue worker:

```bash
php artisan reverb:start
```

```bash
php artisan queue:work
```

To set up a screen:
1. An administrator creates a display under **Displays**. A pairing key is shown once.
2. Open `/display/{id}` on the screen and enter the key.
3. Press **Enable sound** on the screen. Browsers only allow speech after a user gesture.

## Testing and quality
Tests run against PostgreSQL. The database is `patient_queue_testing` (see `phpunit.xml`). The database constraints and the concurrency behaviour are part of what is tested, so SQLite is not supported.

```bash
composer test
```

This runs Pint in check mode, Larastan at level 7 and the Pest suite.

```bash
npm test
```

This runs the display client tests: state, duplicate events and the announcer.

The suite has 414 PHP tests and 18 JavaScript tests. It includes:
- **Concurrency tests** (`tests/Concurrency`): parallel PHP processes race against PostgreSQL to show that duplicate bookings, check-ins, ticket numbers and counter claims are rejected under real contention.
- **Database tests**: every constraint and trigger the application relies on, including immutable signed notes and append-only history, is tested directly.
- **Authorisation tests**: each screen and action is tested for every role.

CI ([`.github/workflows/tests.yml`](.github/workflows/tests.yml)) runs these checks on every push to `main` and on every pull request. It also runs the migrations and the backup and restore drill.

## Operations
Deployment is manual, and nothing deploys automatically. See [DEPLOYMENT](docs/operations/DEPLOYMENT.md).

| Command | Purpose |
|---|---|
| `php artisan clinic:preflight` | Production readiness check. Exits non-zero on any failure. |
| `php artisan clinic:health` | JSON health probe for monitoring: database, Redis, queue backlog, failed jobs and the WebSocket server. |
| `scripts/backup.sh` | Encrypted, checksummed PostgreSQL backup with a row-count manifest. |
| `scripts/restore-drill.sh <file>` | Restores a backup into a scratch database and checks its integrity. |
| `php artisan staff:create` | Creates a staff account. |

The scheduler runs `appointments:mark-no-shows` and `queue:close-day` every hour. Production also needs a running scheduler (`php artisan schedule:work`, or the equivalent cron entry).

## Documentation
The full documentation index is [docs/README.md](docs/README.md).

| Document | Contents |
|---|---|
| [Requirements](docs/REQUIREMENTS.md) | Scope, exclusions and the defaults still to be confirmed |
| [Architecture](docs/ARCHITECTURE.md) | Structure, request flow and design rules |
| [Workflow](docs/WORKFLOW.md) | Patient journey and lifecycle states |
| [Queue engine](docs/QUEUE_ENGINE.md) | Numbering, ordering, transitions and concurrency |
| [Database schema](docs/DATABASE_SCHEMA.md) | Tables and integrity constraints |
| [Roles and permissions](docs/ROLES_AND_PERMISSIONS.md) | Roles, permissions and access scope |
| [Security](docs/SECURITY.md) | Security and privacy controls |
| [Operations](docs/operations/) | Deployment, backups, monitoring, runbooks, threat model, privacy review |
| [Implementation plan](docs/IMPLEMENTATION_PLAN.md) | Phases and their verification records |

## Project status
All eight implementation phases are complete and green in CI. What remains before real patient data is not engineering work: clinic, legal and privacy approvals, a restore drill on the production backup target, and an independent security review. The [implementation plan](docs/IMPLEMENTATION_PLAN.md) has the details.

The repository contains synthetic data only. Never commit real patient records, credentials, database dumps or production configuration. Contributors and coding agents should read [AGENTS.md](AGENTS.md).

No license has been chosen yet.
