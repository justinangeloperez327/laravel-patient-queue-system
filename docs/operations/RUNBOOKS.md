# Operational runbooks

## Start of day
1. Confirm `php artisan clinic:health` reports `ok: true` and `websocket: up`.
2. Confirm each display shows **Live**. If one shows "Connecting…", see *Display problems*.
3. Staff open their counters in **My counter**.

## End of day
- Reception closes visits still open (cancelled, or left without service).
- `queue:close-day` runs hourly and cancels unfinished tickets from earlier days; it reports any still being served.
- `appointments:mark-no-shows` marks pending bookings from earlier days.

## Staff onboarding and offboarding
- **Onboarding:** Administration → Staff → Add. The person receives a password-setup link and must enable 2FA when the clinic requires it. Assign departments, and roles on a least-privilege basis.
- **Offboarding:** set the account **inactive**. It is signed out immediately and cannot sign in. Accounts are never deleted, which keeps audit attribution. Remove department assignments, and rotate any display keys the person knew.

## Display problems
- **"Connecting…" for more than a minute:** check Reverb (`clinic:health`). The screen keeps refreshing from the server every 60 s.
- **Lost or stolen display device:** Administration → Displays → deactivate it, or rotate its key. The device is cut off at once.
- **No sound:** press **Enable sound** on the screen. Browsers require a tap after each reload.

## Changing clinic settings
- Change the **timezone** only outside opening hours. It moves the operational day: ticket numbering, appointment days and report boundaries.
- Grace, eligibility and queue parameters take effect immediately and are audited.

## Suspected breach or unauthorized access (technical containment)
The clinic's breach-response policy decides notification; this list covers containment only.
1. Preserve evidence: export the relevant audit-log range (read-only) and the server logs.
2. Contain:
   - deactivate the affected accounts;
   - rotate display keys;
   - rotate `REVERB_APP_SECRET`, the database password and the backup passphrase if exposed;
   - force sign-out of all users by rotating `APP_KEY` only with an engineering plan, because it invalidates encrypted values such as 2FA secrets.
3. Assess scope with the audit log (`clinical.history.view`, `patient.viewed`, `report.exported`).
4. Hand over to the clinic's breach-response owner with a timeline.

## Restore
See BACKUP_AND_RESTORE.md.
