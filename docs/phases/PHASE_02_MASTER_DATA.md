# Phase 02 — Master data
Status: Complete — merged to `main`, CI green
Dependencies: 01
## Objective and scope
Implement receptionist-only patient CRUD, departments, services, counters, staff assignments, provider schedules and exceptions.
## Implementation checklist
- [x] Implement receptionist-only patient CRUD, departments, services, counters, staff assignments, provider schedules and exceptions
- [x] Implement applicable migrations/models: patients, departments, services, counters, counter_services, staff_assignments, provider_schedules, provider_schedule_exceptions.
- [x] Implement applicable Livewire screens/routes: /patients, /departments, /services, /counters, /staff.
- [x] Enforce policies, validation and domain rules in linked architecture documents.
- [x] Add feature/unit tests and PostgreSQL integration tests where applicable.
- [x] Run formatting, static analysis and test suite; document results.
## Acceptance criteria
Validate unique codes, department scope, schedule intervals and triage routing; test permissions and CRUD.
## Implementation notes
- **No deletes.** Patients, departments, services, counters and staff are deactivated, never deleted; foreign keys use `RESTRICT`. Staff assignment rows are deactivated rather than removed, which keeps the history. Only schedule exceptions that have not started can be removed.
- **Actions authorize themselves.** Every write goes through a transactional action in `app/Actions/*`, which checks the policy for the acting user (`Gate::forUser`). Routes (`can:` middleware) and Livewire components check again. Livewire components are class-based (`app/Livewire`) and only map form fields to actions.
- **Permissions added:** `patients.view` (receptionist) and `schedules.manage` (admin). Patient screens are receptionist-only in this phase; clinical access to patient records arrives with care-relationship scoping in phase 05.
- **Codes and prefixes.** Department and counter codes are uppercase `[A-Z0-9_-]` (normalised on input, `CHECK`-constrained, unique). Department names are unique case-insensitively; service names are unique per department. Queue prefixes are 1–3 uppercase letters and unique.
- **Triage routing.** A service with `optional`/`required` triage names exactly one triage service and `none` names none (`CHECK`), never itself (`CHECK`). The target must be active and must not route through triage itself, so there are no chains. A service that others route through cannot gain triage, and cannot be deactivated while active services route through it. Row locks (`FOR UPDATE` on the service, `FOR SHARE` on the target) keep these checks correct under concurrent edits.
- **Department scope.**
  - Counters serve only services of their own department. Besides validation, `counter_services.department_id` carries composite foreign keys to `counters(id, department_id)` and `services(id, department_id)`, so PostgreSQL rejects cross-department pairs.
  - Moving a counter clears its services. A service on counters, or with active schedules, cannot change department.
  - Schedule providers must be active, hold a provider role (doctor, nurse, service staff) and be assigned to the service's department. The service must be active and accept appointments.
  - A provider's role or department assignment cannot be removed while active schedules depend on it.
- **Schedule intervals.**
  - Weekly blocks use ISO weekdays (1 = Monday) and clinic-local times. The end must be after the start and the block must hold whole slots of 5–240 minutes (`CHECK`s).
  - Active blocks of one provider never overlap on a weekday, across services too. This is enforced by a `btree_gist` exclusion constraint on half-open ranges, so back-to-back blocks are allowed.
  - Exceptions are entered in clinic time, stored as UTC `timestamptz`, and cannot overlap (exclusion constraint).
- **Patients.**
  - Patient numbers `PT-######` come from the PostgreSQL sequence `patient_number_seq`, which is atomic, never reused and owned by the column. The format is `CHECK`ed and the column is unique; the number and the registering user are immutable.
  - A matching name and date of birth triggers a duplicate warning that the receptionist must confirm.
  - Search covers number, name, phone and exact date of birth, with wildcard characters escaped. Contact fields are hidden from model serialisation.
- **Concurrency.** Unique/exclusion violations raised by concurrent requests are mapped to field validation errors (`App\Support\ConstraintViolation`), and the whole transaction rolls back.
- **Staff screen.** Admins create staff without choosing a password; the user gets a password-reset link. Admins cannot deactivate themselves or remove their own admin role.
- **Seeders.** `DemoMasterDataSeeder` (local only) creates the synthetic T → C → L → P clinic from WORKFLOW.md, department assignments, a doctor schedule and 12 synthetic patients.
- **Deferred to later phases:**
  - Audited reads/writes of patient records, which needs `audit_logs` (phase 08).
  - Scoping staff to their assigned departments when working queues (phase 04).
  - Clinical access to patient history (phase 05).
## Verification results (2026-11-26)
- `composer test`: Pint passed, PHPStan level 7 passed, Pest passed — 186 tests, 602 assertions, on PostgreSQL 16.
- PostgreSQL constraint tests assert the specific constraint name for each violation. Each statement runs in a savepoint so one failure cannot mask the next.
- A simulated race (a conflicting row inserted inside the save transaction) surfaces as a field error with a full rollback.
- Migrations: `migrate:fresh` twice, rollback of all 7 phase-02 migrations, and re-apply all succeeded.
- Browser smoke test (Playwright, local server):
  - All admin screens return 200; admin is refused `/patients` (403) and the receptionist is refused `/departments` (403).
  - A service was created with triage routing through the UI.
  - A patient was registered (`PT-000013`); the duplicate warning appears for the same name and date of birth; search finds the patient.
## Completion
- [x] Acceptance criteria verified and implementation plan updated. CI (GitHub Actions `tests`) passed on the pull requests and on `main` for #1, #2 and #3 (runs 1–6, 2026-11-26), including the multi-process concurrency suite against PostgreSQL 16.
