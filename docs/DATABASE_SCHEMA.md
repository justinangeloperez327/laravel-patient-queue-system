# Database schema
BIGINT primary keys; timestamptz timestamps unless noted. FK references by convention; restrict deletion of historical records. Nullable fields marked ?. Use enums/check constraints for statuses, positive durations and valid ranges. Add indexes for FK and queue lookups.

| Table | Domain fields (in addition to id and applicable timestamps) |
|---|---|
| patients | patient_number unique, first_name, middle_name?, last_name, date_of_birth date, sex?, phone?, email?, nationality?, address?, emergency_contact_name?, emergency_contact_phone?, registered_by FK users, is_active |
| departments | code unique, name, description?, is_active |
| services | department_id, name, queue_prefix unique, triage_mode (none/optional/required), triage_service_id?, allows_appointments, estimated_duration minutes, is_active |
| counters | department_id, code unique, name, location?, is_active |
| counter_services | counter_id, service_id composite PK |
| staff_assignments | user_id, department_id unique pair, is_active |
| provider_schedules | provider_id, service_id, day_of_week, start_time, end_time, slot_duration_minutes, is_active |
| provider_schedule_exceptions | provider_id, starts_at, ends_at, reason |
| appointments | appointment_number unique, patient_id, service_id, provider_id, scheduled_start_at, scheduled_end_at, reason_for_visit?, status, booked_by, checked_in_at?, cancelled_at?, cancellation_reason? |
| visits | visit_number unique, patient_id, appointment_id? unique, requested_service_id, visit_type, status, reason_for_visit?, registered_by, checked_in_at, completed_at?, cancelled_at? |
| queue_tickets | visit_id, service_id, counter_id?, assigned_provider_id?, served_by?, transferred_from_id?, ticket_number, sequence_number, queue_date date, priority, priority_reason?, status, issued_by, queued_at, eligible_at, called_at?, started_at?, completed_at? |
| queue_sequences | service_id, queue_date, last_number; unique service/date |
| queue_events | queue_ticket_id, user_id?, event_type, previous_status?, new_status?, counter_id?, remarks?, metadata JSONB, created_at; append-only |
| clinical_notes | visit_id, queue_ticket_id, author_id, note_type, chief_complaint?, subjective?, objective?, assessment?, plan?, additional_notes?, status, signed_at? |
| clinical_note_amendments | clinical_note_id, author_id, amendment, reason, signed_at, created_at |
| vital_signs | visit_id, queue_ticket_id, recorded_by, systolic_bp?, diastolic_bp?, heart_rate?, respiratory_rate?, temperature_celsius?, oxygen_saturation?, weight_kg?, height_cm?, pain_score?, remarks?, recorded_at |
| counter_sessions | counter_id, user_id, opened_at, closed_at? |
| display_devices | name, credential_hash, is_active, last_seen_at?, configuration JSONB |
| audit_logs | actor_id?, action, subject_type, subject_id?, outcome, metadata JSONB, created_at |
| settings | key unique, value JSONB |
| users and RBAC | framework users, roles, permissions, model_has_roles, model_has_permissions, role_has_permissions |
| framework | sessions, password_reset_tokens, jobs/failed_jobs as applicable |

Constraints: unique(service_id,queue_date,sequence_number); partial unique active ticket per visit/service and active called/serving ticket per counter; unique active counter session; appointment exclusion using provider and overlapping half-open timestamptz ranges for active bookings (btree_gist); ensure visit patient matches appointment patient and clinical ticket belongs to visit; validate counter/service and provider eligibility. Avoid cascading deletes of clinical history. Signed notes and queue events append-only after finalization. Clinical priority and scheduling preference are distinct concepts. Exact column lengths and migration ordering finalized during implementation; phase 02 decisions (column `estimated_duration_minutes`, ISO weekday numbers, denormalised `counter_services.department_id` with composite foreign keys, `patient_number_seq`) are recorded in phases/PHASE_02_MASTER_DATA.md.
