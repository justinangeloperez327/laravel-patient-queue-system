# Project scope

## Purpose
The system manages patient flow through a single outpatient clinic. It replaces paper tickets and verbal calling with a reliable queue, gives clinicians a shared record of each visit, and gives managers accurate operational figures.

## In scope
- Patient registration and demographics, maintained by reception.
- Appointments booked by reception, and walk-in patients.
- Check-in that opens one visit per attendance.
- Optional or mandatory triage before a service.
- A numbered ticket for each service, reset daily, with transfers between services on the same visit.
- Waiting-room displays with visual and spoken announcements.
- Clinical documentation: vital signs, triage, nursing and SOAP notes, signing and amendments.
- An operational dashboard, reports and exports.
- Administration of departments, services, counters, staff, schedules, displays and settings.

## Out of scope
Billing, insurance, e-prescribing, pharmacy inventory, laboratory result processing, a patient portal, multiple branches and AI-assisted diagnosis. See [Requirements](REQUIREMENTS.md).

## Constraints
- One clinic, one timezone. Operational dates come from the clinic's local time.
- A modular Laravel monolith with Livewire and Blade. See [Architecture](ARCHITECTURE.md).
- No production use with real patient data until the clinic, legal and privacy approvals in the [phase 08 release gate](phases/PHASE_08_PRODUCTION.md) are complete.
