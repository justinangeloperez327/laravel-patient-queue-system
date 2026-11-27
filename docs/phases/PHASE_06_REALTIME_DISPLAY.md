# Phase 06 — Real-time public display
Status: Complete — on `main`, CI green
Dependencies: 04, 05
## Objective and scope
Configure Reverb/Echo, after-commit broadcasts, restricted device credentials, reconnect snapshots and sequential browser speech.
## Implementation checklist
- [x] Configure Reverb/Echo, after-commit broadcasts, restricted device credentials, reconnect snapshots and sequential browser speech
- [x] Implement applicable migrations/models: display_devices.
- [x] Implement applicable Livewire screens/routes: /display/{device}, display settings.
- [x] Enforce policies, validation and domain rules in linked architecture documents.
- [x] Add feature/unit tests and PostgreSQL integration tests where applicable.
- [x] Run formatting, static analysis and test suite; document results.
## Acceptance criteria
No PHI in display payloads; test reconnect, duplicate events, audio enablement and sequential announcements.
## Implementation notes
- **Stack.** Laravel Reverb 1.14 (Pusher protocol), `laravel-echo` and `pusher-js` loaded only by the display bundle (`resources/js/display.js`); staff pages open no websocket.
  - Reverb keys are generated locally with `php artisan reverb:install` and live only in `.env`. `.env.example` has empty placeholders and `BROADCAST_CONNECTION=log` by default.
  - Docker Compose has a `reverb` service.
- **Device credentials.**
  - An administrator creates a display at `/displays` and is shown a random pairing key once (`xxxxxxxx-xxxxxxxx-xxxxxxxx`). Only its SHA-256 hash is stored; a `CHECK` enforces the hash format and the key never reaches logs or audit.
  - The screen pairs by POSTing the key (`/display/{id}/pair`), never in a URL, throttled to 5 attempts per minute. Every failure gets one generic message.
  - The session stores the device's credential hash. Deactivating the device or rotating its key invalidates every paired screen immediately: snapshot and channel authorization return 403 and the screen reloads to the pairing form.
  - Device management is admin-only (`displays.manage`) and audited (`display.created/updated/key_rotated`).
- **Private channels.** Each device listens on `private-display-device.{id}`. `/display/{id}/broadcasting/auth` signs the Pusher auth string (`key:HMAC-SHA256(socket_id:channel, secret)`) only for a paired, active device's own channel, with a valid socket id. Staff logins play no part.
- **After-commit broadcasts.**
  - The phase 04 `QueueTicketChanged` event (after commit) now carries the queue event id.
  - `BroadcastQueueChangeToDisplays` sends a queued `DisplayUpdated` (`ShouldBroadcast`, `afterCommit`, 3 tries with backoff) to the active devices showing that service.
  - Relevant types: called, recalled, started, skipped, completed, transferred, cancelled, requeued. "Issued" and priority changes are never broadcast.
  - Because the broadcast is queued, a Reverb outage never fails a staff action.
- **No PHI.** `App\Services\Display\DisplayPayload` is the only display data shape: `{id, type, ticket, room, service_id, at}`. The snapshot adds `status` and `called_at` and nothing else.
  - Patient names, patient and visit numbers, dates of birth, reasons and clinical data are never included.
  - The announcement text is "Ticket C, 0, 0, 1, please proceed to Room 1." — ticket and room only (QUEUE_ENGINE.md).
- **Reconnect snapshot.** `/display/{id}/snapshot` (no-store) returns `last_event_id`, now serving (called/serving tickets of today for the configured services) and the 8 most recent calls. The client loads it at start, on every websocket (re)connection and every 60 s as a safety net, which also detects revocation.
- **Duplicate events.** Each update carries its queue event id. The client ignores ids at or below the snapshot's `last_event_id` and ids it has already applied. Updates that arrive while a reconnect snapshot is loading are buffered, then only the newer ones are applied and announced.
  - A recall is a new event, so it is announced again by design. "Recently called" lists each ticket once.
- **Audio enablement and sequential speech.**
  - Browser speech starts only after the "Enable sound" button is pressed (browsers require a user gesture).
  - `Announcer` speaks one announcement at a time, in the device's language (`voice_lang`), and never speaks the same event twice.
  - A watchdog moves on if the engine never reports the end of speech. Errors from the engine or the utterance constructor are caught.
  - The screen renders before announcing, so a speech failure can never hide a call.
- **Design decision.** A call made while a display was disconnected is shown after reconnection, from the snapshot, but not spoken late, because announcing a stale call could send a patient to a room that has moved on. Confirm this with the clinic.
- **Screens.**
  - `/display/{id}`: full-screen dark layout, no staff navigation. Shows the title, a connection status (polite live region), the clock, "Now serving" cards (ticket, room; called tickets highlighted), recent calls, and "Enable sound".
  - `/displays`: admin list, create/edit (name, title, services, voice language, active) and key rotation.
## Verification results (2026-11-26)
- `composer test`: Pint passed, PHPStan level 7 passed, Pest passed — 363 tests, 1,257 assertions, on PostgreSQL 16, including 19 new display tests. They cover:
  - key shown once and only its hash stored;
  - admin-only management; validation;
  - unpaired access refused; generic pairing errors; pairing throttle;
  - revocation by deactivation and by key rotation;
  - channel auth signature, other-channel and bad-socket refusal;
  - snapshot fields and absence of names, patient and visit numbers and other services;
  - broadcast fan-out only to matching active devices, payload keys exactly as above;
  - no broadcast after rollback; queued (not inline) broadcasts; the screen page has no PHI or secret.
- `npm test` (Node's built-in test runner, now a CI step): 18 tests. They cover snapshot load, duplicate and stale event rejection, buffering during reconnect, stale state replaced on resync, removal of finished tickets, recent-call de-duplication, the announcement text, and announcer behaviour (silent until enabled, strictly sequential, never repeats, error and stuck-engine recovery).
- Mutation check: disabling the client's duplicate/stale filter fails 4 JS tests. Restored.
- **Live run** (real Reverb, Redis queue worker, app server, Playwright, with the browser speech engine recorded):
  - The unpaired screen showed the pairing form, and a wrong key gave the generic error.
  - After pairing, the screen went Live. The receptionist checked in 3 lab walk-ins and service staff called the first: the display showed `L-001` in 156–289 ms.
  - Call and recall were each spoken once, in order: "Ticket L, 0, 0, 1, please proceed to Laboratory window 1." No patient names were in the page. The ticket left the screen on completion.
  - Reverb was stopped, and the screen showed "Connecting…". `L-002` was called during the outage: its broadcast job failed in the queue while the staff action itself succeeded.
  - After Reverb restarted, the screen reconnected and showed `L-002` from the snapshot. `L-003` was then announced exactly once, with nothing replayed.
- Fixed during verification:
  - A throwing speech engine stopped the screen from rendering a call. Rendering now comes first and speech errors are contained.
  - "Recently called" duplicated recalled tickets.
  - Ticket numbers wrapped on the card.
## Completion
- [x] Acceptance criteria verified and implementation plan updated. CI (GitHub Actions `tests`, run 11 on `main`, 2026-11-27) passed: Pint, PHPStan, the display JS tests, migrations, the backup and restore drill and the full Pest suite (including the multi-process concurrency tests) on PostgreSQL 16.
