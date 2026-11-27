# Phase 07 — Dashboard and reporting
Status: Complete — on `main`, CI green
Dependencies: 04, 05, 06
## Objective and scope
Implement operational KPIs, date/service/provider filters and authorized CSV/Excel exports.
## Implementation checklist
- [x] Implement operational KPIs, date/service/provider filters and authorized CSV/Excel exports
- [x] Implement applicable migrations/models: Read-only queries and optional indexes.
- [x] Implement applicable Livewire screens/routes: /dashboard, /reports.
- [x] Enforce policies, validation and domain rules in linked architecture documents.
- [x] Add feature/unit tests and PostgreSQL integration tests where applicable.
- [x] Run formatting, static analysis and test suite; document results.
## Acceptance criteria
Verify distinct patients vs visits, wait/service duration and no-show denominator against fixtures; enforce export authorization.
## Implementation notes
- **Metric definitions** (`App\Services\Reporting\OperationalReport`, matching IMPLEMENTATION_PLAN.md):
  - **Visits**: check-ins whose clinic-local date falls in the range.
  - **Distinct patients**: unique patients among those visits.
  - **Wait**: `eligible_at → started_at` of tickets started, with the ticket's queue date in range. It is measured from eligibility, not arrival, so an appointment patient who arrives early is not counted as waiting before their window.
  - **Service duration**: `started_at → completed_at` of completed or transferred tickets.
  - Wait and service are reported as mean, median and 90th percentile (`percentile_cont`, linear interpolation), computed in PostgreSQL and rounded to 0.1 min.
  - **No-show rate**: no-shows ÷ eligible scheduled bookings. Eligible bookings are scheduled in the range and reached an outcome (checked in, completed or no-show); cancelled and still-pending bookings are excluded. With no eligible bookings the rate is shown as "—", not 0.
- **Filters.**
  - Dates are inclusive clinic-local dates, at most 366 days. `to ≥ from` is validated, and so are the service and provider.
  - The service filter covers that service's tickets, visits that requested it or had a ticket for it, and appointments for it.
  - The provider filter covers tickets they served, visits with such a ticket, and appointments booked with them.
- **Breakdowns.** By service, by provider and by day (every day in the range, including empty ones).
- **Indexes** (migration `add_reporting_indexes`): `queue_tickets(queue_date)`, partial `queue_tickets(served_by, queue_date)`, `visits(checked_in_at)` and `appointments(scheduled_start_at)`. No new tables; reporting is read-only.
- **Access.** `reports.operational.view` and a new `reports.export` are granted to administrators only; every other role gets 403 on `/reports` and on exports.
- **Exports** (`GET /reports/export`, rate-limited to 20 per minute):
  - CSV: one table (summary, service, provider or day), UTF-8 with a BOM.
  - Excel (OpenSpout 5): a workbook with Summary, By service, By provider and By day sheets.
  - Exports are aggregates only: no patient names or numbers. Staff names appear in the provider table.
  - Cells starting with `= + - @` or a control character are prefixed with an apostrophe, which blocks formula injection.
  - Every export is audited (`report.exported` with format, table and filters). Invalid options produce validation errors and are not audited.
- **Dashboard** (`/dashboard`, refreshed every 30 s).
  - Administrators see today's KPIs: visits, distinct patients, tickets served, mean and p90 wait, and no-show rate.
  - Reception and administrators see live counts (waiting, at a counter, skipped) for every service. Queue staff see counts only for services in their departments.
  - No patient data appears on the dashboard.
- **Screens.** `/reports` has date, service and provider filters, summary KPI cards with their definitions, and by-service, by-provider and by-day tables, plus export buttons for users who may export. The sidebar shows an "Insights → Reports" item for administrators.
## Verification results (2026-11-26)
- `composer test`: Pint passed, PHPStan level 7 passed (raw SQL fragments typed as `literal-string`), Pest passed — 388 tests, 1,385 assertions, on PostgreSQL 16. `npm test`: 18 passed.
- **Fixture verification** (`tests/Support/ReportFixture.php`, 13 metric tests). A hand-built data set with exact timestamps; every expected value was computed by hand:
  - visits 4 vs distinct patients 3 (one patient visited twice);
  - waits [10, 30, 20] → mean 20.0, median 20.0, p90 28.0;
  - services [15, 5, 30], including a transferred ticket → mean 16.7, median 15.0, p90 27.0;
  - bookings 6 → eligible 4, no-shows 2 → 50.0 %;
  - each filter, each breakdown, empty ranges, and the Asia/Manila day boundary moving a 23:30 UTC visit to the next day.
- **Mutation checks.** Counting cancelled bookings in the denominator fails 7 tests. Measuring wait from arrival (`queued_at`) instead of eligibility fails 6 tests, once the fixture included an early-arriving appointment patient (the first fixture did not catch this, so it was strengthened). Both were restored.
- **Export tests** (12 tests):
  - 403 for receptionist, nurse, doctor and service staff, with no audit entry.
  - CSV header, values, BOM and filename; no patient names; audit metadata.
  - Excel sheet names and values, read back with OpenSpout.
  - Option validation; formula-injection unit cases and an end-to-end case (a provider named `=cmd|calc` exports as `'=cmd|calc`).
  - Dashboard scope per role; sidebar visibility.
- **Browser smoke test** (Playwright, admin and receptionist; 5 lab walk-ins, 3 served through the real actions):
  - The dashboard and `/reports` showed visits 5, served 3 of 5, wait mean 28.7 / median 26.0 / p90 47.6 min, and service 6.0 / 6.0 / 6.8 min. These match a hand calculation from waits of 7, 26 and 53 minutes and service times of 5, 6 and 7 minutes.
  - The CSV and Excel downloads worked. The receptionist got 403 on `/reports` and on export.
## Completion
- [x] Acceptance criteria verified and implementation plan updated. CI (GitHub Actions `tests`, run 11 on `main`, 2026-11-27) passed: Pint, PHPStan, the display JS tests, migrations, the backup and restore drill and the full Pest suite (including the multi-process concurrency tests) on PostgreSQL 16.
