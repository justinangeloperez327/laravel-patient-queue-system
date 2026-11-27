# Operational workflow

## Patient journey
1. **Find or register the patient.** Reception searches by patient number, name, phone or date of birth. A new patient is registered with an automatic `PT-######` number. A matching name and date of birth raises a possible-duplicate warning, which the receptionist must confirm.
2. **Check in.** Reception checks in a booked appointment, or registers a walk-in. Check-in opens exactly one **visit** (`V-YYYYMMDD-####`) and issues the first **ticket** in the same transaction.
3. **Triage, if applicable.**
   - If the requested service requires triage, the first ticket is for the triage service.
   - If triage is optional, reception chooses whether to send the patient there first.
   - Otherwise the ticket is for the requested service directly.
   - After the assessment, the nurse sends the patient on to the requested service, and can set clinical priority with a reason.
4. **Service.** Staff at a counter call the next patient. They can recall or skip, then start service and record notes and vital signs if authorised.
5. **Transfer or complete.** A transfer closes the current ticket and issues the next service's ticket on the same visit, atomically. Completing the last unresolved ticket completes the visit, and then its appointment.
6. **Exceptions.** Skipped tickets can be requeued. Reception gives an explicit disposition to visits that cannot finish: cancelled, or left without service.

**Example journey.** One visit moves through triage, consultation, laboratory, consultation again and procedure:

```
T-012 → C-018 → L-007 → C-025 → P-009
```

## Appointment lifecycle
```
scheduled ──confirm──▶ confirmed
    │                     │
    ├──────check in───────┴──▶ checked_in ──visit completed──▶ completed
    ├──cancel (reason)──▶ cancelled
    └──after the grace period──▶ no_show
```
- Rescheduling keeps the appointment number and is audited with the old and new provider and time.
- An appointment has no ticket until it is checked in.
- Check-in is allowed on the appointment's local day, until the slot start plus the late grace period (proposed: 15 minutes).
- After the grace period, reception can record a no-show and register the patient as a walk-in. An hourly job marks pending bookings from earlier days as no-shows.
- Bookings can be made up to 90 days ahead (proposed).

## Visit lifecycle
| Status | Meaning |
|---|---|
| `checked_in` | The patient has arrived; no ticket has started yet |
| `in_progress` | At least one ticket has started service |
| `completed` | Every required service has been resolved |
| `cancelled` | Closed by reception before any service started |
| `left_without_service` | The patient left; this is the only closing disposition once service has begun |

A patient can have at most one open visit (`checked_in` or `in_progress`) at a time.

## Ticket lifecycle
`waiting`, `called`, `serving`, `skipped`, `completed`, `transferred` and `cancelled`. The [queue engine](QUEUE_ENGINE.md) defines the transitions, call order and concurrency rules.

## End of day (proposed)
An hourly job cancels waiting, called and skipped tickets left over from earlier clinic days, and records an "End of day" event on each. It reports tickets still being served but never changes them. Open visits are left for reception to close.
