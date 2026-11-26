# Privacy review

Status: engineering input for the clinic's privacy and legal review. **No claim of regulatory compliance is made** (docs/SECURITY.md). Synthetic data only until the release gate is signed.

## Data inventory
| Data | Where | Who can see it | Notes |
|---|---|---|---|
| Patient demographics: name, DOB, sex, phone, email, nationality, address, emergency contact | `patients` | Receptionists; clinicians with an active care relationship (record page) | Contact fields hidden from model serialisation; every view and change audited (field names only) |
| Visits, appointments, tickets | `visits`, `appointments`, `queue_tickets` | Reception; queue staff in their departments | Ticket numbers are not identifying on their own |
| Clinical notes, amendments, vital signs | `clinical_*`, `vital_signs` | Clinicians with an active care relationship | Every read and refused read audited; signed notes immutable |
| Staff accounts | `users`, `staff_assignments` | Administrators | Passwords hashed (bcrypt), 2FA secrets encrypted |
| Audit and queue history | `audit_logs`, `queue_events` | Administrators (audit log) | Identifiers only; queue remarks are free text entered by staff, so train staff to avoid clinical detail |
| Display data | Broadcasts and snapshots | Anyone in the waiting room | Ticket number and room only |
| Exports | CSV/XLSX downloads | Administrators | Aggregates only; staff names in the provider table |
| Backups | Encrypted files | Operators holding the passphrase | Contain everything above |

## Minimisation already applied
- Public displays never receive names or numbers.
- Audit metadata never contains values or clinical text.
- Reports are aggregate.
- Optional demographic fields are optional.
- Reasons for visits and cancellations are prompted as non-clinical.

## Decisions required from the clinic (release gate)
1. **Jurisdiction and legal basis:** which health-privacy law applies, where data is hosted (data residency), and whether a data-protection officer or registration is required.
2. **Retention periods** for patient records, clinical notes (often set by medical-records law), audit logs, queue history, backups and exports. The application has no automatic deletion yet. Backups default to 30-day local retention (`BACKUP_RETENTION_DAYS`), to be confirmed.
3. **Deletion and patient rights:** correction is supported (demographic edits, note amendments). Access requests, export of a patient's own data, and deletion or anonymisation policy are not yet implemented. Records are deactivated, never deleted, by design.
4. **Breach response:** who decides, notification duties and timelines. See RUNBOOKS.md for the technical containment steps.
5. **Clinical access policy:** the care-relationship rule and the absence of break-glass access must be approved.
6. **Consent and notices:** patient privacy notice wording, and signage near displays explaining announcements by ticket number.
7. **Processor agreements** with hosting, email and backup providers.
