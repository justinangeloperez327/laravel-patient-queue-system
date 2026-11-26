# Phase 03 — Appointments and reception
Status: Implemented and verified locally; completion waits on the first CI run (shared with phases 01–02)
Dependencies: 02
## Objective and scope
Implement calendar, provider availability, booking, rescheduling, cancellation, check-in and walk-in visits; integrate ticket issuance in phase 04.
## Implementation checklist
- [x] Implement calendar, provider availability, booking, rescheduling, cancellation, check-in and walk-in visits
- [ ] integrate ticket issuance in phase 04 (by design: check-in creates the visit only; phase 04 adds ticket issuance at reception)
- [x] Implement applicable migrations/models: appointments, visits.
- [x] Implement applicable Livewire screens/routes: /appointments, /reception, /visits.
- [x] Enforce policies, validation and domain rules in linked architecture documents.
- [x] Add feature/unit tests and PostgreSQL integration tests where applicable.
- [x] Run formatting, static analysis and test suite; document results.
## Acceptance criteria
Reject overlapping bookings and duplicate check-ins under concurrency; test grace periods and appointment lifecycle.
## Implementation notes
- **Availability** (`App\Services\ProviderAvailability`):
  - Free slots are the provider's active weekly blocks for the service, in clinic-local time.
  - Removed from them: past times, unavailability periods, and the provider's bookings in occupying statuses, for any service.
  - Booking and rescheduling accept only a start time that is one of these slots; the slot length comes from the schedule.
- **Overlap protection:**
  - Two `btree_gist` exclusion constraints on `tstzrange(scheduled_start_at, scheduled_end_at, '[)')` stop overlaps per provider and per patient.
  - They apply to occupying statuses only (scheduled, confirmed, checked in, completed), so cancelled and no-show bookings free the time. Back-to-back slots are allowed.
  - Races that get past the application check surface as field errors and roll back.
- **Duplicate check-in protection:**
  - `visits.appointment_id` is unique.
  - A partial unique index allows one open (`checked_in`/`in_progress`) visit per patient.
  - A composite foreign key `(appointment_id, patient_id)` makes a visit's patient match its appointment's.
  - Check-in locks the appointment row (and walk-in locks the patient row), so concurrent attempts serialise.
- **Lifecycle and timestamps:**
  - Appointment and visit transitions follow `AppointmentStatus`/`VisitStatus` (WORKFLOW.md).
  - `CHECK` constraints tie `checked_in_at`, `cancelled_at`, `cancellation_reason` and `completed_at` to status.
  - Reception can close a visit before service starts, as cancelled or as left without service.
  - Cancelling an appointment visit also cancels the appointment. A patient who left keeps the appointment as checked in, since they attended.
  - Appointment completion will follow visit completion in phase 04.
- **Grace periods** (`config/clinic.php` → `appointments`; proposed defaults, not yet approved by the clinic):
  - Check-in is allowed on the appointment's clinic-local day until start + `late_grace_minutes` (15).
  - After that, reception can record a no-show and register a walk-in.
  - `appointments:mark-no-shows` runs hourly and marks pending bookings from earlier clinic days.
  - `booking_horizon_days` (90) limits how far ahead bookings can be made.
  - `early_eligibility_minutes` (15) is reserved for queue eligibility in phase 04.
- **Numbers.** `APT-YYYYMMDD-####` uses the clinic-local booking date and never changes, including on reschedule. `V-YYYYMMDD-####` uses the clinic-local check-in date. Both are generated under a per-day transaction advisory lock (`App\Support\DailyNumber`), so they are gap-free and sequential, with unique and format `CHECK` constraints behind it.
- **Audit (pulled forward from phase 08).** WORKFLOW.md requires rescheduling to be audited, so a minimal `audit_logs` table was added now.
  - It is append-only: a trigger rejects `UPDATE`/`DELETE`.
  - It records booking, confirmation, rescheduling (from/to provider and time), cancellation, no-show (including the automatic sweep, which has a null actor), check-in (with a `late` flag) and visit closing.
  - Metadata holds IDs and times only, never patient identifiers.
  - The appointment page shows this history. Phase 08 still owns read auditing and the review.
- **Access.** Appointments use `appointments.manage`; check-in, walk-ins and visits use `visits.check_in`, so both are receptionist-only. Every action authorizes the acting user. Admin, nurse, doctor and service staff get 403 on all three screens.
- **Screens:**
  - `/appointments`: day calendar grouped by provider, with provider and service filters.
  - `/appointments/create`: patient search, service, eligible provider, date and slot picker.
  - `/appointments/{id}`: confirm, check in, reschedule, cancel with a reason, record a no-show, and the audit history.
  - `/reception`: today's bookings with check-in, walk-in check-in, and who is checked in now.
  - `/visits`: visits by clinic day and status, with the close actions.
## Verification results (2026-11-26)
- `composer test`: Pint passed, PHPStan level 7 passed, Pest passed — 251 tests, 807 assertions, on PostgreSQL 16.
- **Concurrency** (`tests/Concurrency`, 6 tests): separate PHP processes with their own PostgreSQL connections race on committed data, starting at a shared instant.
  - 6 simultaneous bookings of one slot → exactly 1 appointment.
  - 6 bookings of different slots → all succeed with numbers `-0001`…`-0006`.
  - One patient booked into overlapping slots with two providers → 1 appointment.
  - 6 simultaneous check-ins of one appointment → 1 visit.
  - 6 simultaneous walk-ins of one patient → 1 open visit.
  - 6 walk-ins of different patients → 6 unique visit numbers.
  - These tests truncate and re-seed before and after each test; the full suite passed twice in a row and each suite passed alone.
- **Mutation check.** Disabling the grace-period check makes the grace test fail. Disabling the application-level open-visit check leaves tests green because the partial unique index rejects the duplicate and maps it to the same field error, which confirms the database safeguard on its own.
- **Grace and lifecycle tests** use a fixed clock and cover early, on-time, end-of-grace and after-grace check-in; no-show only after grace; a configurable grace period; the end-of-day sweep; the transition table; and the timezone-correct operational day (Asia/Manila).
- **Migrations:** rolling back and re-applying the 3 phase-03 migrations and `migrate:fresh` twice succeeded.
- **Browser smoke test** (Playwright, local server, receptionist):
  - Booked `APT-20261126-0005` through the slot picker, then rescheduled it; the history shows the move.
  - The calendar lists the day's bookings.
  - Checked in an appointment (`V-20261126-0001`), checked in a walk-in (`V-20261126-0002`) and cancelled it with a reason; the visits list shows both.
  - The doctor gets 403 on `/reception`, `/appointments` and `/visits`.
- Fixed during verification: a Blade component named `slot-picker` was parsed as a `<x-slot>` tag and hid most of the booking form. It was renamed `appointment-slots`.
## Completion
- [ ] Acceptance criteria verified and implementation plan updated.
