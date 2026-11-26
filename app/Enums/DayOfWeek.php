<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ISO-8601 weekday numbers (Monday = 1 … Sunday = 7), as used by Carbon::isoWeekday().
 */
enum DayOfWeek: int
{
    case Monday = 1;
    case Tuesday = 2;
    case Wednesday = 3;
    case Thursday = 4;
    case Friday = 5;
    case Saturday = 6;
    case Sunday = 7;

    public function label(): string
    {
        return ucfirst(strtolower($this->name));
    }
}
