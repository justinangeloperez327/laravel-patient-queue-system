# Phase 01 — Project foundation
Status: In progress (implementation complete; awaiting first CI run and Docker app-image verification)
Dependencies: None
## Objective and scope
Scaffold Laravel with Livewire/Blade; configure PostgreSQL, Redis, Docker Compose, Fortify, RBAC, layouts, Pint, Pest, Larastan and CI.
## Implementation checklist
- [x] Scaffold Laravel with Livewire/Blade
- [ ] configure PostgreSQL, Redis, Docker Compose, Fortify, RBAC, layouts, Pint, Pest, Larastan and CI
  - [x] PostgreSQL (default connection, UTC session timezone, timestamptz), Redis (cache, sessions, queues), Fortify, RBAC, layouts, Pint, Pest, Larastan
  - [x] Docker Compose `pgsql` and `redis` services verified (suite passed against them, migrations and seeders ran)
  - [ ] Docker Compose `app`/`queue` image build — not verifiable in the development sandbox (no plain-HTTP egress for apt); verify on a workstation
  - [ ] GitHub Actions workflow (`.github/workflows/tests.yml`) — written; first run pending
- [x] Implement applicable migrations/models: users, roles, permissions and framework tables.
- [x] Implement applicable Livewire screens/routes: /login, /dashboard; shared layout.
- [x] Enforce policies, validation and domain rules in linked architecture documents.
- [x] Add feature/unit tests and PostgreSQL integration tests where applicable.
- [x] Run formatting, static analysis and test suite; document results.
## Acceptance criteria
Authentication, authorization, migrations and CI pass; no public staff registration.
## Implementation notes
- Stack: Laravel 13, Livewire 4, Flux (free), Tailwind 4, Fortify, Spatie Permission 8, Pest 4, Larastan 3 (level 7), PHP 8.3, PostgreSQL 16, Redis 7. Scaffolded from the official Livewire starter kit.
- Authentication: Fortify login, password reset, optional TOTP two-factor and password confirmation. Registration, email verification and passkeys are disabled. Accounts are provisioned with `php artisan staff:create` (password always prompted). Self-service account deletion was removed to preserve attribution.
- Inactive accounts (`users.is_active = false`) fail login with the generic credential error and are signed out on their next request (`EnsureUserIsActive`).
- RBAC: roles and permissions are defined in `App\Enums\Role` / `App\Enums\Permission` and synced idempotently by `App\Actions\Authorization\SyncRolesAndPermissions` (seeded by `RolesAndPermissionsSeeder`). No super-admin bypass: administrators have no implicit clinical or reception permissions. The role→permission mapping is a proposed default pending clinic validation. Department and care-relationship scoping arrives with policies in later phases.
- Database: users email has a case-insensitive unique index (`lower(email)`); all application timestamps are `timestamptz`; the PostgreSQL session timezone is UTC. `config/clinic.php` holds the clinic timezone used to derive operational dates.
- `/` redirects to `/dashboard`; guests are sent to `/login`; the dashboard requires `dashboard.view` (users with no role get 403).
- Local demo accounts (`<role>@example.test` / `password`, synthetic) are seeded only when `APP_ENV=local`.
- The remote web font was removed (the build fetched it from a third-party CDN); the UI uses the system font stack.
## Verification results (2026-11-26)
- `composer test` (Pint `--test`, PHPStan level 7, Pest): all passed — 73 tests, 292 assertions, on PostgreSQL 16 (`patient_queue_testing`).
- Test suite also passed against the Docker Compose `pgsql`/`redis` services; `migrate:fresh --seed` succeeded there.
- Browser smoke test (Playwright): `/` → `/login`; `/register` → 404; receptionist login lands on `/dashboard` showing the assigned role.
## Completion
- [ ] Acceptance criteria verified and implementation plan updated.
