# Phase 05 — Clinical documentation
Status: Pending
Dependencies: 04
## Objective and scope
Implement optional triage, vital signs, SOAP drafts, signing, amendments and authorized history.
## Implementation checklist
- [ ] Implement optional triage, vital signs, SOAP drafts, signing, amendments and authorized history
- [ ] Implement applicable migrations/models: clinical_notes, clinical_note_amendments, vital_signs.
- [ ] Implement applicable Livewire screens/routes: /workspace/triage, /workspace/consultation, /patients/{patient}.
- [ ] Enforce policies, validation and domain rules in linked architecture documents.
- [ ] Add feature/unit tests and PostgreSQL integration tests where applicable.
- [ ] Run formatting, static analysis and test suite; document results.
## Acceptance criteria
Signed notes immutable; clinical access audited; validate units and visit/ticket consistency; no receptionist clinical access.
## Completion
- [ ] Acceptance criteria verified and implementation plan updated.
