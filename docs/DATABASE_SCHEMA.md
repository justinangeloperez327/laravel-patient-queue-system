# Database schema

PostgreSQL 16 is authoritative. Integrity rules that must never be broken are enforced in the database (constraints, exclusion constraints and triggers), not only in application code. The migrations in `database/migrations` are the definitive schema. This document summarises it.

## Conventions
- `BIGINT` identity primary keys. All timestamps are `timestamptz`, stored in UTC. Calendar dates such as `date_of_birth` and `queue_date` use `date`.
- Statuses and enumerations are `CHECK`-constrained to the values of the matching PHP enum in `app/Enums`.
- Foreign keys use `RESTRICT`. Historical and clinical records are never deleted by cascade, and master data is deactivated (`is_active`) instead of deleted.
- Fields marked `?` below are nullable.

## Tables

### People and access
| Table | Columns |
|---|---|
| `users` | name, email (unique, case-insensitive), password, is_active, two_factor_secret?, two_factor_recovery_codes?, two_factor_confirmed_at? |
| `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` | Spatie Laravel Permission |
| `staff_assignments` | user_id, department_id (unique pair), is_active |
| `patients` | patient_number (unique, `PT-######`), first_name, middle_name?, last_name, date_of_birth, sex?, phone?, email?, nationality?, address?, emergency_contact_name?, emergency_contact_phone?, registered_by, is_active |

### Clinic configuration
| Table | Columns |
|---|---|
| `departments` | code (unique), name (unique, case-insensitive), description?, is_active |
| `services` | department_id, name (unique per department), queue_prefix (unique, 1–3 letters), triage_mode (`none`/`optional`/`required`), triage_service_id?, allows_appointments, estimated_duration_minutes, is_active |
| `counters` | department_id, code (unique), name, location?, is_active |
| `counter_services` | counter_id, service_id, department_id. Composite primary key; composite foreign keys keep a counter and its services in the same department. |
| `provider_schedules` | provider_id, service_id, day_of_week (ISO 1–7), start_time, end_time, slot_duration_minutes, is_active |
| `provider_schedule_exceptions` | provider_id, starts_at, ends_at, reason? |
| `display_devices` | name, credential_hash (SHA-256), is_active, last_seen_at?, configuration (JSONB: title, services, voice language) |
| `settings` | key (unique), value (JSONB), updated_by? |

### Scheduling and visits
| Table | Columns |
|---|---|
| `appointments` | appointment_number (unique), patient_id, service_id, provider_id, scheduled_start_at, scheduled_end_at, reason_for_visit?, status, booked_by, checked_in_at?, cancelled_at?, cancellation_reason? |
| `visits` | visit_number (unique), patient_id, appointment_id? (unique), requested_service_id, visit_type, status, reason_for_visit?, registered_by, checked_in_at, completed_at?, cancelled_at? |

### Queue
| Table | Columns |
|---|---|
| `queue_tickets` | visit_id, service_id, counter_id?, assigned_provider_id?, served_by?, transferred_from_id?, ticket_number, sequence_number, queue_date, priority (clinical, 0–2), priority_reason?, scheduling_preference, status, issued_by, queued_at, eligible_at, called_at?, started_at?, completed_at? |
| `queue_sequences` | service_id, queue_date, last_number. Unique on service and date. |
| `queue_events` | queue_ticket_id, user_id?, event_type, previous_status?, new_status?, counter_id?, remarks?, metadata (JSONB), created_at. Append-only. |
| `counter_sessions` | counter_id, user_id, opened_at, closed_at? |

### Clinical
| Table | Columns |
|---|---|
| `clinical_notes` | visit_id, queue_ticket_id, author_id, note_type (`triage`/`nursing`/`soap`), chief_complaint?, subjective?, objective?, assessment?, plan?, additional_notes?, status (`draft`/`signed`), signed_at? |
| `clinical_note_amendments` | clinical_note_id, author_id, amendment, reason, signed_at, created_at. Append-only. |
| `vital_signs` | visit_id, queue_ticket_id, recorded_by, systolic_bp?, diastolic_bp?, heart_rate?, respiratory_rate?, temperature_celsius?, oxygen_saturation?, weight_kg?, height_cm?, pain_score?, remarks?, recorded_at. Append-only. |

### Audit and framework
| Table | Columns |
|---|---|
| `audit_logs` | actor_id?, action, subject_type, subject_id?, outcome, metadata (JSONB, IDs and field names only), created_at. Append-only. |
| Framework | `sessions`, `password_reset_tokens`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `migrations` |

## Integrity rules

### Exclusion constraints (`btree_gist`, half-open `[)` ranges)
| Constraint | Prevents |
|---|---|
| `appointments_provider_no_overlap` | Overlapping bookings for a provider. Applies to occupying statuses only; back-to-back slots are allowed. |
| `appointments_patient_no_overlap` | Overlapping bookings for a patient |
| `provider_schedules_no_overlap` | Overlapping active weekly blocks for a provider, across services |
| `provider_schedule_exceptions_no_overlap` | Overlapping unavailability periods |

### Partial unique indexes
| Index | Guarantees |
|---|---|
| `visits_one_open_per_patient` | One open (`checked_in`/`in_progress`) visit per patient |
| `queue_tickets_one_unresolved_per_visit_service` | One unresolved ticket per visit and service |
| `queue_tickets_one_active_per_counter` | One called or serving ticket per counter |
| `counter_sessions_one_open_per_counter` | One open session per counter |
| `counter_sessions_one_open_per_user` | One open session per user |
| `clinical_notes_one_draft` | One draft per author, ticket and note type |
| `queue_events_idempotency_key_unique` | A repeated request cannot act twice |

Also unique: `queue_tickets (service_id, queue_date, sequence_number)` and every human-readable identifier.

### Composite foreign keys
- `visits (appointment_id, patient_id)` → `appointments`: a visit's patient matches its appointment's patient.
- `clinical_notes` and `vital_signs (queue_ticket_id, visit_id)` → `queue_tickets (id, visit_id)`: a record's ticket belongs to its visit.
- `counter_services (counter_id, department_id)` and `(service_id, department_id)`: no cross-department counter assignments.

### Triggers
| Trigger | Effect |
|---|---|
| `clinical_notes_signed_immutable` | Rejects updates and deletes of signed notes, including un-signing |
| `clinical_note_amendments_append_only` | Append-only; rejects amendments to unsigned notes |
| `vital_signs_no_update_or_delete` | Append-only |
| `queue_events_no_update_or_delete` | Append-only |
| `audit_logs_no_update_or_delete` | Append-only |

### Check constraints
There are 60 named `CHECK` constraints. They cover:
- status values;
- timestamps consistent with status (for example, `signed_at` is set exactly when a note is signed);
- identifier formats and code formats;
- positive durations and whole slots;
- the triage routing rules;
- clinical value ranges, such as blood pressure 50–300 / 20–200 mmHg with diastolic below systolic, and temperature 30.0–45.0 °C.

### Sequences and numbering
- `patient_number_seq` (owned by `patients.patient_number`) issues patient numbers.
- Appointment and visit numbers are issued under a per-day transaction advisory lock.
- Ticket numbers come from an atomic upsert on `queue_sequences`.

`tests/Feature/Database` checks that every constraint and trigger the application relies on is present. The restore drill checks them again after every restore.

## Design decisions
The phase records explain the detailed decisions behind the schema:

| Phase | Decisions recorded |
|---|---|
| [Phase 02](phases/PHASE_02_MASTER_DATA.md) | Master data, counter scoping, triage routing |
| [Phase 03](phases/PHASE_03_APPOINTMENTS.md) | Occupying statuses, one open visit, early `audit_logs` |
| [Phase 04](phases/PHASE_04_QUEUE_ENGINE.md) | Clinical priority kept separate from scheduling preference, idempotency, counter sessions |
| [Phase 05](phases/PHASE_05_CLINICAL_RECORDS.md) | Clinical triggers and composite keys |
| [Phase 06](phases/PHASE_06_REALTIME_DISPLAY.md) | Display credentials |
| [Phase 07](phases/PHASE_07_REPORTING.md) | Reporting indexes |
| [Phase 08](phases/PHASE_08_PRODUCTION.md) | Settings |
