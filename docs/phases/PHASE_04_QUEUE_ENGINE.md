# Phase 04 — Queue engine
Status: Implemented and verified locally; completion waits on the first CI run for phases 02–04
Dependencies: 03
## Objective and scope
Implement atomic daily sequences, call-next, recall, skip, requeue, start, complete, cancel and transfer; enforce counter sessions and eligibility.
## Implementation checklist
- [x] Implement atomic daily sequences, call-next, recall, skip, requeue, start, complete, cancel and transfer
- [x] enforce counter sessions and eligibility
- [x] Implement applicable migrations/models: queue_tickets, queue_sequences, queue_events, counter_sessions.
- [x] Implement applicable Livewire screens/routes: /queues, /workspace.
- [x] Enforce policies, validation and domain rules in linked architecture documents.
- [x] Add feature/unit tests and PostgreSQL integration tests where applicable.
- [x] Run formatting, static analysis and test suite; document results.
## Acceptance criteria
Concurrent PostgreSQL tests prove no duplicate tickets or claims; transfers atomic; events append-only; integrate reception issuance.
## Implementation notes
- **Numbering.**
  - Each service numbers its tickets per clinic-local day: `PREFIX-001`.
  - `queue_sequences` is incremented by a single `INSERT … ON CONFLICT DO UPDATE … RETURNING`, which row-locks the (service, day) counter.
  - `unique(service_id, queue_date, sequence_number)` is the backstop.
  - The ticket number is stored as issued, so history keeps the old prefix if a service's prefix changes. Numbering restarts at the clinic's local midnight (`CLINIC_TIMEZONE`).
- **Transitions** follow QUEUE_ENGINE.md exactly (`QueueTicketStatus`): waiting→called/cancelled; called→serving/skipped/cancelled; skipped→waiting/cancelled; serving→completed/transferred.
  - Recall is an event only, limited to `clinic.queue.max_recalls` (3).
  - Every transition, recall and priority change writes a `queue_events` row with the previous and new status, actor and counter.
- **Database invariants:**
  - A trigger makes `queue_events` append-only.
  - `CHECK`s tie the counter, `called_at`, `started_at`, `completed_at`, `served_by` and the priority reason to status.
  - Partial unique indexes allow one unresolved ticket per visit/service and one called-or-serving ticket per counter.
  - `counter_sessions` allows one open session per counter and per user.
- **Claiming a ticket.** Call-next locks the counter row, requires the caller's open session there, and refuses while a ticket is at the counter. It claims the first eligible ticket with `FOR UPDATE SKIP LOCKED`, so concurrent counters never claim the same patient.
- **Idempotency.** Each workspace or board submit carries a key, stored in the event's metadata under a unique index. A repeated request (a double click) returns the original result instead of acting twice.
- **Call order** (`App\Services\Queue\QueueOrdering`), stable and starvation-safe:
  1. Clinical priority (urgent → high → routine). Only nurses and doctors set it, within their departments, and a reason is required above routine. Scheduling never overrides it.
  2. Scheduling class. Appointment tickets, and walk-ins that have waited longer than `walk_in_safeguard_minutes` (45), come before other walk-ins.
  3. Eligibility time.
  4. Sequence number, then id.

  Appointment tickets become eligible `early_eligibility_minutes` (15) before the slot, and are offered only to the booked provider; their triage tickets are open to any triage staff. Clinical priority and scheduling preference are separate columns.
- **Requeue ordering** (proposed). A requeued ticket keeps its number, priority and scheduling preference, and becomes eligible from the requeue time. It rejoins behind those already waiting in its class.
- **Reception issuance.**
  - Appointment and walk-in check-in now issue the first ticket in the same transaction as the visit.
  - Triage routing: required → the triage service; optional → reception's choice ("Check in → triage", "Send to triage first"); none → the requested service.
  - Reception can issue a new ticket for an open visit that has none in progress, and can requeue or cancel any ticket.
- **Transfer.** In one transaction, the serving ticket becomes `transferred` and the next ticket is issued in the same visit, carrying clinical priority and `transferred_from_id`. Any failure, such as an inactive target or the same service, leaves everything unchanged. The documented T → C → L → C → P journey is covered by a test.
- **Completion and disposition.**
  - The first started ticket moves the visit to in progress.
  - Completing the last unresolved ticket completes the visit, and then its appointment.
  - Reception's close-visit action (`CloseVisit`) is the explicit disposition for skipped or cancelled tickets. It cancels waiting and skipped tickets with events, refuses while a ticket is at a counter, and allows only "left without service" once service has started.
- **Department scope** (server side, in policies).
  - Staff open counters only in their assigned departments and act only on tickets of those departments' services. Only the session holder at the ticket's counter can call, recall, skip, start, complete or transfer it.
  - Reception issues, requeues and cancels anywhere but cannot staff counters. Administrators have no queue access.
  - The board shows staff only their departments' services.
- **End of day** (proposed). `queue:close-day` runs hourly. It cancels waiting, called and skipped tickets from earlier clinic days with an "End of day" event. It reports tickets still being served and never changes them; open visits are left for reception.
- **After-commit domain event.** `QueueTicketChanged` (ticket, service, counter and event type only; no patient data) is dispatched with `ShouldDispatchAfterCommit`. Phase 06 turns it into sanitized broadcasts and the reconnect snapshot.
- **Screens.**
  - `/workspace` (queue staff): open or close a counter, call next, recall (with count), skip, start, complete, transfer with a note, set priority, and an "Up next" list in call order.
  - `/queues` (reception and queue staff): today's unresolved tickets per service in call order, with requeue, cancel and priority actions.
  - Both pages refresh by Livewire polling (5 s / 10 s) until phase 06 adds push updates.
  - The reception desk shows each open visit's ticket.
## Verification results (2026-11-26)
- `composer test`: Pint passed, PHPStan level 7 passed, Pest passed — 306 tests, 1,025 assertions, on PostgreSQL 16. The full suite passed in two consecutive runs.
- **Concurrency** (`tests/Concurrency/QueueConcurrencyTest.php`): separate PHP processes with their own connections race on committed data.
  - 4 counters calling at once → 4 distinct tickets.
  - 1 waiting patient and 4 counters → called exactly once; the others get "Nobody is waiting".
  - 4 different requests on one counter → 1 called ticket.
  - 4 identical double-submits → the same ticket and 1 event.
  - 4 concurrent transfers of one ticket → exactly 1 next ticket.
  - 6 simultaneous walk-ins → tickets `-001`…`-006`, with no duplicates.
- **Mutation check.** Removing `FOR UPDATE SKIP LOCKED` from call-next made the race test fail in 3 of 3 runs (only 2–3 distinct tickets for 4 counters). Restored.
- **Feature tests** (50 queue tests):
  - The transition table and invalid transitions.
  - Recall limit, skip and requeue ordering.
  - Ordering by priority, appointment class, safeguard, eligibility, provider assignment and other-day exclusion.
  - Triage routing, appointment eligibility, daily and timezone reset, and prefix history.
  - Transfer atomicity and priority carry-over; the T→C→L→C→P journey.
  - Visit and appointment completion; close-visit disposition.
  - Every named ticket and session constraint.
  - Append-only events, idempotent replay, after-commit dispatch, and the end-of-day command.
  - Scope and permissions per role, and the workspace and board components.
- **Migrations.** Rolling back and re-applying both phase-04 migrations, and `migrate:fresh --seed`, succeeded. The jsonb `?` operator in the idempotency index was replaced by `jsonb_exists()` because PDO treats `?` as a placeholder.
- **Browser smoke test** (Playwright; receptionist, nurse and doctor sessions):
  - Walk-in for consultation → `T-001`.
  - The nurse opened Triage station 1. A double-clicked "Call next" produced exactly one call (one `called` event). The nurse started service and transferred to C → `C-001`.
  - The doctor opened Consultation room 1, called `C-001`, started and completed it, and the visit showed Completed.
  - The next call reported that nobody was waiting, because appointment tickets are not yet eligible.
  - The receptionist gets 403 on `/workspace`.
## Completion
- [ ] Acceptance criteria verified and implementation plan updated.
