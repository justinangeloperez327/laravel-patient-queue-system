# Implementation plan
Phase 1 in progress (implemented; awaiting first CI run); phases 2–8 pending. Update checklists after tested implementation.

- [ ] [Phase 1: Project foundation](phases/PHASE_01_FOUNDATION.md) — depends on None — in progress
- [ ] [Phase 2: Master data](phases/PHASE_02_MASTER_DATA.md) — depends on 01
- [ ] [Phase 3: Appointments and reception](phases/PHASE_03_APPOINTMENTS.md) — depends on 02
- [ ] [Phase 4: Queue engine](phases/PHASE_04_QUEUE_ENGINE.md) — depends on 03
- [ ] [Phase 5: Clinical documentation](phases/PHASE_05_CLINICAL_RECORDS.md) — depends on 04
- [ ] [Phase 6: Real-time public display](phases/PHASE_06_REALTIME_DISPLAY.md) — depends on 04, 05
- [ ] [Phase 7: Dashboard and reporting](phases/PHASE_07_REPORTING.md) — depends on 04, 05, 06
- [ ] [Phase 8: Production readiness](phases/PHASE_08_PRODUCTION.md) — depends on 01–07

Release gate: all acceptance criteria, security review, restore drill and clinic policy approval. Reporting definitions: distinct patients, visits, eligible-to-start wait, start-to-end service duration, no-shows / eligible scheduled bookings. Exports permission-scoped.
