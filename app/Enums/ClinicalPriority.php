<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Clinical urgency, assigned only by authorized clinicians. Distinct from
 * scheduling preference (appointment vs walk-in). Emergencies bypass the
 * queue under clinic protocol and are not modelled here.
 */
enum ClinicalPriority: int
{
    case Routine = 0;
    case High = 1;
    case Urgent = 2;

    public function label(): string
    {
        return match ($this) {
            self::Routine => 'Routine',
            self::High => 'High',
            self::Urgent => 'Urgent',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Routine => 'zinc',
            self::High => 'amber',
            self::Urgent => 'red',
        };
    }
}
