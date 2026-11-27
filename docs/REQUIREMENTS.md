# Requirements (v1)

## Confirmed requirements
| # | Requirement |
|---|---|
| R1 | A single clinic. |
| R2 | Both walk-in patients and booked appointments. |
| R3 | Only reception registers patients and books appointments. |
| R4 | Triage is configurable per service: none, optional or required. |
| R5 | A separate ticket for each service, numbered per day. |
| R6 | Waiting-room displays with visual and voice announcements. |
| R7 | Patient demographics and clinical notes. |
| R8 | Built with Laravel Livewire and Blade. |

## MVP modules
Dashboard, patients, appointments, visits, queues, clinical records, departments and services, counters, staff, displays, reports and settings. All of them are implemented.

## Exclusions
- Billing and insurance
- E-prescribing and pharmacy inventory
- Laboratory result processing
- A patient portal
- Multiple branches
- AI-assisted diagnosis

## Defaults awaiting clinic approval
These are implemented as working defaults. The clinic must confirm them before go-live. Most can be changed under **Clinic settings** (`/settings/clinic`) without a code change.

| Topic | Current default |
|---|---|
| Clinical note format | SOAP notes for doctors; triage and nursing notes for nurses |
| Vital signs | Blood pressure, heart rate, respiratory rate, temperature (°C), SpO₂, weight, height, pain score |
| Daily reset | Ticket numbering restarts at local midnight in the clinic timezone |
| Clinical priority | Set only by nurses and doctors, with a reason above routine |
| Appointment eligibility | A patient can be called 15 minutes before their slot |
| Late arrival | Check-in is allowed until 15 minutes after the slot starts |
| Walk-in fairness | After 45 minutes, a walk-in is ordered with appointment patients |
| Recalls | A called ticket can be recalled at most 3 times |
| Announcements | Browser speech synthesis, in the language set for the display |
| Access control | The role-to-permission mapping in [Roles and permissions](ROLES_AND_PERMISSIONS.md) |

## Data
Use synthetic data only until the compliance approvals in the [release gate](phases/PHASE_08_PRODUCTION.md) are complete.
