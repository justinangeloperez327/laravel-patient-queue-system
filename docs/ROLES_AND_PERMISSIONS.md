# Roles and permissions

## Principles
- **Deny by default.** Access requires an explicit permission, and actions check it again on the server for the acting user.
- **No superuser.** Administrators configure the clinic. They have no clinical or reception access unless they are also given those roles.
- **Scope.** Queue and clinical work is limited to the departments a staff member is assigned to. Clinical history is further limited to patients the clinician is currently caring for.
- **Multiple roles.** A user can hold more than one role when the clinic authorises it.
- **Source of truth.** Roles and permissions are defined in `App\Enums\Role` and `App\Enums\Permission`. `SyncRolesAndPermissions` syncs them idempotently through the seeder.

The mapping below is a proposed default that the clinic must validate before go-live.

## Roles
| Role | Responsibilities |
|---|---|
| Receptionist | Registers and updates patients; books, reschedules and cancels appointments; checks patients in; issues initial tickets; requeues and cancels tickets. No clinical access. |
| Nurse | Works the queues of assigned departments; performs triage; records vital signs; writes nursing and triage notes; views authorised clinical history; sets clinical priority; transfers and completes tickets. |
| Doctor | Works the queues of assigned departments; records vital signs; writes and signs SOAP notes; views authorised clinical history; sets clinical priority; transfers and completes tickets. |
| Service staff | Works the queues of assigned departments: call, recall, skip, requeue, transfer and complete. No clinical history. |
| Administrator | Manages staff, departments, services, counters, schedules, displays and clinic settings; views operational reports, exports and the audit log. No clinical or reception access. |
| Display device | Not a user account. A paired screen receives only the sanitised display feed for its own channel. |

## Permission matrix
| Permission | Admin | Reception | Nurse | Doctor | Service staff |
|---|:-:|:-:|:-:|:-:|:-:|
| `dashboard.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `users.manage` | ✓ | | | | |
| `departments.manage` | ✓ | | | | |
| `services.manage` | ✓ | | | | |
| `counters.manage` | ✓ | | | | |
| `schedules.manage` | ✓ | | | | |
| `displays.manage` | ✓ | | | | |
| `settings.manage` | ✓ | | | | |
| `reports.operational.view` | ✓ | | | | |
| `reports.export` | ✓ | | | | |
| `audit.view` | ✓ | | | | |
| `patients.view` | | ✓ | | | |
| `patients.register` | | ✓ | | | |
| `patients.demographics.update` | | ✓ | | | |
| `appointments.manage` | | ✓ | | | |
| `visits.check_in` | | ✓ | | | |
| `queue.tickets.issue` | | ✓ | | | |
| `queue.tickets.requeue` | | ✓ | | | ✓ |
| `queue.operate` | | | ✓ | ✓ | ✓ |
| `queue.tickets.transfer` | | | ✓ | ✓ | ✓ |
| `queue.tickets.complete` | | | ✓ | ✓ | ✓ |
| `clinical.triage.perform` | | | ✓ | | |
| `clinical.vitals.record` | | | ✓ | ✓ | |
| `clinical.notes.nursing.write` | | | ✓ | | |
| `clinical.notes.soap.write` | | | | ✓ | |
| `clinical.notes.sign` | | | | ✓ | |
| `clinical.history.view` | | | ✓ | ✓ | |
| `clinical.priority.assign` | | | ✓ | ✓ | |

## Scope rules
**Queue work**
- Staff open counters only in their assigned departments, and act only on tickets for those departments' services.
- Only the holder of the open session at a ticket's counter can call, recall, skip, start, complete or transfer that ticket.
- Reception can issue, requeue and cancel tickets anywhere, but cannot staff a counter.

**Clinical history** (`ClinicalAccess::hasCareRelationship`)
- The clinician needs `clinical.history.view` and an active account.
- The patient must have an open visit with a ticket either in one of the clinician's departments, or served by the clinician.
- Access ends when the visit closes.

**Encounter**
- Vital signs and new notes can be recorded only for the ticket the clinician is currently serving. The server works out which ticket that is; it is never taken from the client.
- A draft can be finished after the patient has moved on.

**Notes**
- Only the author can edit or sign a draft.
- Amendments can be added by the author, or by a clinician with the note type's write permission and a care relationship.

**Audit**
- Every clinical read and write is recorded, and so is every refused access attempt.

## Open decision
No emergency ("break-glass") access is implemented. The clinic must define and approve that policy before deployment. See the [privacy review](operations/PRIVACY_REVIEW.md).
