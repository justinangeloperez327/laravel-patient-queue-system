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

];
