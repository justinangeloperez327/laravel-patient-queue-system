<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\ClinicTime;
use Carbon\CarbonImmutable;

/**
 * A bookable slot. Instants are UTC.
 */
final readonly class AvailableSlot
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}

    public function key(): string
    {
        return $this->start->utc()->toIso8601String();
    }

    public function localLabel(): string
    {
        $tz = ClinicTime::timezone();

        return $this->start->setTimezone($tz)->format('H:i').'–'.$this->end->setTimezone($tz)->format('H:i');
    }
}
