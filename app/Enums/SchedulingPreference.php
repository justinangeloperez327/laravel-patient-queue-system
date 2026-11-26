<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Scheduling preference for queue ordering. Appointment tickets (the first
 * ticket of an appointment visit) are preferred over walk-ins once
 * eligible; walk-ins waiting longer than the safeguard join them.
 */
enum SchedulingPreference: string
{
    case Standard = 'standard';
    case Appointment = 'appointment';
}
