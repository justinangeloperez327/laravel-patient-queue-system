# Phase 08 — Production readiness
Status: Pending
Dependencies: 01–07
## Objective and scope
Review privacy, threat model, access logs, backups, restore, monitoring, deployment and operational runbooks.
## Implementation checklist
- [ ] Review privacy, threat model, access logs, backups, restore, monitoring, deployment and operational runbooks
- [ ] Implement applicable migrations/models: audit_logs, settings; review all constraints. (A minimal append-only `audit_logs` table already exists from phase 03 for appointment/visit changes; this phase reviews it and adds read auditing.)
- [ ] Implement applicable Livewire screens/routes: /settings; deployment and recovery documentation.
- [ ] Enforce policies, validation and domain rules in linked architecture documents.
- [ ] Add feature/unit tests and PostgreSQL integration tests where applicable.
- [ ] Run formatting, static analysis and test suite; document results.
## Acceptance criteria
CI green, concurrency and authorization tests pass, restore drill succeeds, jurisdiction-specific compliance and clinic policy approved before real data.
## Completion
- [ ] Acceptance criteria verified and implementation plan updated.
