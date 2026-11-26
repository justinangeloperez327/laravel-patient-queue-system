# Threat model

Status: engineering draft for review by the clinic and a security reviewer. Not an attestation.

## Assets
- **Patient demographics:** `patients`, the visit and appointment links to them.
- **Clinical records:** `clinical_notes`, `clinical_note_amendments`, `vital_signs`.
- **Operational history:** `queue_events` and `audit_logs`, which must stay trustworthy.
- **Credentials:** staff passwords and 2FA secrets, display pairing keys, Reverb keys, `APP_KEY`, database and backup passphrases.
- **Availability** of reception, the queue and displays during clinic hours.

## Actors and trust boundaries
| Actor | Trust | Boundary |
|---|---|---|
| Staff (5 roles) | Authenticated; least privilege; department and care-relationship scope | Web session (Fortify, optional or enforced 2FA) |
| Display screens | Device key, own channel only, sanitized data | Paired session + private WebSocket channel |
| Patients and the public | Untrusted | See displays only |
| Operators (hosting) | Privileged; out of application scope | Server, database, backups |

## Threats and mitigations (STRIDE)
| Threat | Mitigation in place | Residual / to decide |
|---|---|---|
| **S**poofing a staff member | Fortify login, rate limits (login, 2FA), optional enforced TOTP 2FA (`clinic:preflight` fails without it), inactive accounts signed out mid-session, 30-minute idle sessions | Password policy is Laravel's production default (12+ characters, mixed, uncompromised); SSO not in scope |
| Spoofing a display | SHA-256-hashed one-time keys, POST pairing (throttled), session bound to the key hash, rotation and deactivation revoke at once | Physical access to an unlocked display browser |
| **T**ampering with history | Database triggers: append-only `audit_logs`, `queue_events`, amendments and vitals; signed notes immutable; restore drill verifies the triggers survive a backup | A database superuser can still disable triggers, so database credentials must be restricted |
| Tampering with queue order or bookings | Transactional actions, row locks, `FOR UPDATE SKIP LOCKED`, exclusion constraints, partial unique indexes, idempotency keys; proven by concurrency tests | — |
| **R**epudiation | Attributed audit entries for reads and writes of patient and clinical data, settings, exports and device changes; attributed queue events; signed, attributed amendments | Audit review cadence to be set by the clinic (see RUNBOOKS.md) |
| **I**nformation disclosure: public display | Display data limited to ticket, room, service and time; tested for patient-data absence; private per-device channels | Rooms named after people would leak names, so name rooms neutrally |
| Information disclosure: staff | Server-side policies on every action; care relationship required for clinical history; reception sees demographics only; exports aggregate-only; `no-store` caching; security headers | No break-glass access (policy needed) |
| Information disclosure: logs and backups | Audit metadata holds IDs only (tested); backups AES-256 encrypted, with checksums | Key custody and off-site storage are operational decisions |
| **D**enial of service | Queued broadcasts (a WebSocket outage does not break staff actions); rate limits on login, pairing, snapshot, channel auth and exports; health checks | No WAF or CDN; hosting-level protection to decide |
| **E**levation of privilege | Deny-by-default permissions, no administrator bypass, self-lockout guards, `#[Locked]` Livewire properties, persistent Livewire middleware (2FA, password confirmation) | Role assignment is an administrator power; review periodically |
| CSRF / XSS / injection | Laravel CSRF; Blade escaping; Eloquent bindings; Larastan `literal-string` on raw SQL; CSV/XLSX formula-injection guard | No Content-Security-Policy yet (Livewire and Flux need a nonce strategy); recommended follow-up |

## Out of scope / not yet done
- Penetration test by an independent party.
- Content-Security-Policy with nonces.
- Malware scanning (there are no file uploads in scope).
- SSO/SAML and hardware security keys. Passkeys were removed from the starter kit; they can be re-enabled if wanted.
