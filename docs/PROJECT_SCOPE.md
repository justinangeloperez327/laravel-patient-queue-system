# Project Scope (Draft)

## Objective
Manage outpatient queues from registration through service completion, with live staff views and waiting-room displays.

## Proposed users
- Receptionist: register patients and issue tickets
- Nurse / Triage staff: call or route patients
- Clinician: serve and complete encounters
- Queue administrator: configure departments, counters and queue policy
- Display device: show public queue information only

## Proposed MVP
1. Secure staff authentication and role-based permissions
2. Department / service / counter administration
3. Ticket creation with concurrency-safe numbering
4. Queue state transitions and audit events
5. Call-next and recall workflows
6. Real-time public display, using ticket numbers rather than patient names
7. Basic reports for wait time, service time and daily throughput

## Design considerations
- Queue numbers may reset by clinic, service, and date; define exact uniqueness rules first
- Transfers should preserve a complete event trail
- Concurrent staff requests must never issue the same ticket or call the same waiting patient
- Keep sensitive patient and medical information out of public displays and application logs
- Define time zone, working hours and what happens to unfinished tickets at close of day
- Use synthetic patient data for development and tests

## Out of scope pending discussion
- Full electronic medical records
- Billing and insurance
- Prescription management
- Online patient portal
- SMS / WhatsApp notifications
- Appointment scheduling
- Multi-branch operations
