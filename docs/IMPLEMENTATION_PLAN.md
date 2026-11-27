# Implementation plan

## Status
Phases 1 to 7 are complete and verified in CI on `main`. Phase 8 engineering is complete and green in CI. Its release gate remains open, and no real patient data may be used until it is closed.

| Phase | Depends on | Status |
|---|---|---|
| [1. Project foundation](phases/PHASE_01_FOUNDATION.md) | — | ✅ Complete (Docker application image build still to be verified) |
| [2. Master data](phases/PHASE_02_MASTER_DATA.md) | 1 | ✅ Complete |
| [3. Appointments and reception](phases/PHASE_03_APPOINTMENTS.md) | 2 | ✅ Complete |
| [4. Queue engine](phases/PHASE_04_QUEUE_ENGINE.md) | 3 | ✅ Complete |
| [5. Clinical documentation](phases/PHASE_05_CLINICAL_RECORDS.md) | 4 | ✅ Complete |
| [6. Real-time public display](phases/PHASE_06_REALTIME_DISPLAY.md) | 4, 5 | ✅ Complete |
| [7. Dashboard and reporting](phases/PHASE_07_REPORTING.md) | 4, 5, 6 | ✅ Complete |
| [8. Production readiness](phases/PHASE_08_PRODUCTION.md) | 1–7 | 🟡 Engineering complete; release gate open |

Each phase record contains its scope, checklist, design decisions and verification results. A checklist item is ticked only after it has been verified.

## Release gate
Before real patient data is used:
- all acceptance criteria are verified;
- a restore drill has been run on the production backup target;
- jurisdiction, data residency and the applicable health-privacy law are confirmed;
- retention, deletion, patient-rights and breach-response policies are approved;
- the clinical access and emergency access policy is approved;
- queue, grace and no-show parameters are approved;
- an independent security review has been completed;
- `clinic:preflight` reports no failures in production.

The [phase 8 record](phases/PHASE_08_PRODUCTION.md#release-gate--open-items-not-engineering-completable) tracks the status of each item.

## Reporting definitions
| Metric | Definition |
|---|---|
| Visits | Check-ins whose clinic-local date falls within the range |
| Distinct patients | Unique patients among those visits |
| Wait time | From the ticket becoming eligible to service starting |
| Service duration | From service start to completion or transfer |
| No-show rate | No-shows ÷ eligible scheduled bookings (cancelled and still-pending bookings excluded) |

Exports are permission-scoped and audited. See [Phase 7](phases/PHASE_07_REPORTING.md).

## Candidate follow-up work
The items below are not yet scheduled, and each needs approval before it starts:
- build and verify the Docker application image in CI;
- dependency auditing (`composer audit`, `npm audit`) and automated update pull requests;
- a Content-Security-Policy header;
- emergency ("break-glass") clinical access, once the clinic has approved a policy;
- retention and deletion jobs, once a retention policy has been approved.
