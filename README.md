# Laravel Patient Queue System

Single-clinic queue management with walk-ins, receptionist-booked appointments, optional triage, per-service tickets, visual and voice announcements, demographics and clinical notes.

## Status
Phases 1–4 (foundation, master data, appointments and reception, queue engine) are complete and green in CI. Phase 5 (clinical documentation: triage, vital signs, SOAP notes, signing, amendments, authorized history) is implemented and verified locally, awaiting CI. Phases 6–8 are pending. See the [implementation plan](docs/IMPLEMENTATION_PLAN.md).

## Stack
Laravel 13, PHP 8.3, Livewire 4, Flux, Tailwind 4, Fortify, Spatie Permission, PostgreSQL 16, Redis 7, Pest, Pint and Larastan.

## Local development
Requirements: PHP 8.3 with `pdo_pgsql` and `redis`, Composer, Node 22, PostgreSQL 16 and Redis 7 (or Docker Compose).

```bash
cp .env.example .env
```

Set the database credentials in `.env`, then:

```bash
composer install && php artisan key:generate && php artisan migrate --seed
```

```bash
npm ci && npm run build
```

```bash
composer dev
```

With Docker Compose instead (local-only credentials are defined in `docker-compose.yml`):

```bash
docker compose up -d
```

```bash
docker compose exec app php artisan migrate --seed
```

There is no public registration. Create staff accounts from the console (the password is prompted):

```bash
php artisan staff:create --name="Clinic Admin" --email=admin@example.test --role=admin
```

With `APP_ENV=local`, the seeder also creates a synthetic demo clinic (departments, services, counters, a doctor schedule, 12 patients) and one demo account per role (`admin@example.test`, `receptionist@example.test`, `nurse@example.test`, `doctor@example.test`, `service-staff@example.test`; password `password`).

## Checks
Tests run against PostgreSQL (database `patient_queue_testing`, see `phpunit.xml`).

```bash
composer test
```

This runs Pint (check mode), Larastan and the Pest suite. The `Concurrency` test suite starts parallel PHP worker processes against the test database to prove duplicate bookings, check-ins, ticket numbers and queue claims are rejected under real contention.

## Documentation
- [REQUIREMENTS](docs/REQUIREMENTS.md)
- [ARCHITECTURE](docs/ARCHITECTURE.md)
- [DATABASE_SCHEMA](docs/DATABASE_SCHEMA.md)
- [WORKFLOW](docs/WORKFLOW.md)
- [ROLES_AND_PERMISSIONS](docs/ROLES_AND_PERMISSIONS.md)
- [QUEUE_ENGINE](docs/QUEUE_ENGINE.md)
- [SECURITY](docs/SECURITY.md)
- [IMPLEMENTATION_PLAN](docs/IMPLEMENTATION_PLAN.md)
- [Agent instructions](AGENTS.md)

No deployment configured. Use synthetic data only. License not yet selected.
