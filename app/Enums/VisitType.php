<?php

declare(strict_types=1);

namespace App\Enums;

enum VisitType: string
{
    case WalkIn = 'walk_in';
    case Appointment = 'appointment';

    public function label(): string
    {
        return match ($this) {
            self::WalkIn => 'Walk-in',
            self::Appointment => 'Appointment',
        };
    }
}
