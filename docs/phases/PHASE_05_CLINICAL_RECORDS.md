# Phase 05 — Clinical documentation
Status: Implemented and verified locally; completion waits on CI
Dependencies: 04
## Objective and scope
Implement optional triage, vital signs, SOAP drafts, signing, amendments and authorized history.
## Implementation checklist
- [x] Implement optional triage, vital signs, SOAP drafts, signing, amendments and authorized history
- [x] Implement applicable migrations/models: clinical_notes, clinical_note_amendments, vital_signs.
- [x] Implement applicable Livewire screens/routes: /workspace/triage, /workspace/consultation, /patients/{patient}.
- [x] Enforce policies, validation and domain rules in linked architecture documents.
- [x] Add feature/unit tests and PostgreSQL integration tests where applicable.
- [x] Run formatting, static analysis and test suite; document results.
## Acceptance criteria
Signed notes immutable; clinical access audited; validate units and visit/ticket consistency; no receptionist clinical access.
## Implementation notes
- **Immutability in the database.**
  - A trigger rejects any `UPDATE` or `DELETE` of a `clinical_notes` row once its status is `signed`, so un-signing is blocked too.
  - `clinical_note_amendments` is append-only, and its trigger refuses an amendment on a note that isn't signed.
  - `vital_signs` is append-only; a wrong reading is corrected by recording a new set.
  - Drafts stay editable, one draft per author, ticket and note type (partial unique index).
  - `CHECK (status = 'signed') = (signed_at IS NOT NULL)`.
- **Visit and ticket consistency.** Notes and vitals carry `(queue_ticket_id, visit_id)` with a composite foreign key to `queue_tickets(id, visit_id)`, so a record's ticket always belongs to its visit.
- **Units.** Validation and database `CHECK`s match:
  - BP 50–300 / 20–200 mmHg, as a pair, with diastolic below systolic.
  - HR 20–250 /min; RR 4–80 /min.
  - Temperature 30.0–45.0 °C (1 decimal); SpO₂ 50–100 %.
  - Weight 0.3–500 kg; height 20–260 cm; pain 0–10.
  - At least one measurement per set.
  - Fahrenheit, metres or extra precision are rejected.
- **Note types and permissions:**
  - Triage and nursing notes are written and finalised by nurses (`clinical.notes.nursing.write`; triage also needs `clinical.triage.perform`).
  - SOAP notes are written by doctors (`clinical.notes.soap.write`) and signed with `clinical.notes.sign`.
  - Before signing: triage needs a chief complaint, nursing needs a note, SOAP needs chief complaint, assessment and plan.
  - Only the author can edit or sign a draft.
  - Amendments are signed when created and attributed to their author. They may be added by anyone with the note type's write permission and a care relationship, or by the note's own author.
- **Encounter rule.** Vitals can be recorded and a note started only for the ticket the clinician is serving at their open counter (`ClinicalAccess::ensureServing`). The ticket is derived on the server, never taken from the client. An existing draft can be finished after the patient moves on.
- **Authorized history** (`ClinicalAccess::hasCareRelationship`), deny by default. A clinician needs `clinical.history.view`, an active account, and an open visit for the patient that has either:
  - a ticket in one of the clinician's assigned departments; or
  - a ticket the clinician served.

  Access ends when the visit closes. No emergency ("break-glass") access is implemented; ROLES_AND_PERMISSIONS.md requires that policy to be validated with the clinic first.
- **Audit** (`audit_logs`; IDs and context only, never clinical text or patient identifiers, which a test checks):
  - every history read (`clinical.history.view` with context `encounter` or `patient_record`);
  - every refused record access — a `Gate::after` hook catches denials from route middleware as well as from code;
  - every vitals record, note save, sign and amendment;
  - refused amendments.
- **No receptionist clinical access.** Reception gets 403 on `/workspace/triage` and `/workspace/consultation`, and all clinical actions throw `AuthorizationException` for them. On `/patients/{patient}` reception sees demographics and visit/ticket history only, with a notice that clinical history is restricted.
  - Service staff and administrators get 403 on patient records.
  - A clinician without a care relationship gets 403, and the attempt is audited.
- **Triage integration.** `/workspace/triage` records vitals, the triage assessment (draft, then sign) and clinical priority (with a reason above routine), then sends the patient to the visit's requested service. `TransferTicket` gained an optional priority applied to the next ticket, in the same transaction and with a `priority_changed` event.
- **Screens.**
  - `/workspace/consultation`: vitals plus a SOAP note (doctors) or nursing note (nurses), showing this visit's signed notes and the clinician's own drafts.
  - `/patients/{patient}`: demographics, visits with tickets, and for authorized clinicians vitals, signed notes, amendments and an "Add amendment" form.
  - The workspace links to Triage and to "Notes & vitals" while serving.
## Verification results (2026-11-26)
- `composer test`: Pint passed, PHPStan level 7 passed, Pest passed — 344 tests, 1,175 assertions, on PostgreSQL 16, including 38 new clinical tests.
- Database tests:
  - Signed-note update, un-sign and delete are rejected by the trigger, while drafts stay editable.
  - Amendments are rejected on drafts and are append-only.
  - Vitals are append-only.
  - Named unit `CHECK`s and the composite foreign keys for notes and vitals are each exercised.
- Mutation checks:
  - Disabling the "serving this ticket" check fails 2 tests.
  - Making every clinician "in a care relationship" fails 3 tests.
  - Both were restored.
- Browser smoke test (Playwright; receptionist, nurse, doctor, service staff, admin):
  - The nurse triaged a walk-in: vitals 128/82 mmHg, 38.4 °C; triage assessment drafted then signed; priority High with a reason; sent on as `C-001`.
  - The doctor saw the triage note. Signing a SOAP note without an assessment or plan was refused. The doctor then signed it and added an amendment from the patient record.
  - Reception saw the record without clinical content, plus the restriction notice.
  - Service staff got 403 on the record and on triage; the admin got 403 on the record.
  - The audit log showed each read and write with IDs only.
- Fixed during verification: denied record views at the route level were not audited. A `Gate::after` hook now records them.
## Completion
- [ ] Acceptance criteria verified and implementation plan updated.
