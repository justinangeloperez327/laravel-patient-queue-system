<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Clinic Timezone
    |--------------------------------------------------------------------------
    |
    | All timestamps are persisted in UTC (config/app.php). This timezone is
    | used only to derive the clinic's local operational date, e.g. for the
    | daily reset of per-service queue numbering.
    |
    */

    'timezone' => env('CLINIC_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Appointment Rules
    |--------------------------------------------------------------------------
    |
    | Proposed defaults from docs/QUEUE_ENGINE.md; NOT yet approved by the
    | clinic. Check-in is allowed on the appointment's local date until
    | start + late grace. After the grace period the booking may be marked
    | as a no-show (the patient can still be registered as a walk-in).
    | early_eligibility_minutes is consumed by the queue engine (phase 04).
    |
    */

    'appointments' => [
        'late_grace_minutes' => (int) env('CLINIC_LATE_GRACE_MINUTES', 15),
        'early_eligibility_minutes' => (int) env('CLINIC_EARLY_ELIGIBILITY_MINUTES', 15),
        'booking_horizon_days' => (int) env('CLINIC_BOOKING_HORIZON_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Rules
    |--------------------------------------------------------------------------
    |
    | Proposed defaults from docs/QUEUE_ENGINE.md; NOT yet approved by the
    | clinic. A walk-in waiting longer than the safeguard is ordered together
    | with appointment tickets. A called ticket may be recalled at most
    | max_recalls times before it must be skipped or started.
    |
    */

    'queue' => [
        'walk_in_safeguard_minutes' => (int) env('CLINIC_WALK_IN_SAFEGUARD_MINUTES', 45),
        'max_recalls' => (int) env('CLINIC_MAX_RECALLS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    |
    | require_two_factor: staff must confirm TOTP two-factor authentication
    | before using the application (docs/SECURITY.md). Recommended on in
    | production; `php artisan clinic:preflight` fails when it is off.
    |
    */

    'security' => [
        'require_two_factor' => (bool) env('CLINIC_REQUIRE_TWO_FACTOR', false),
    ],

];
