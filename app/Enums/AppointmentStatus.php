<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Appointment lifecycle (docs/WORKFLOW.md).
 *
 * scheduled → confirmed | checked_in | cancelled | no_show
 * confirmed → checked_in | cancelled | no_show
 * checked_in → completed (follows visit completion) | cancelled (visit cancelled)
 * completed, cancelled, no_show are terminal.
 */
enum AppointmentStatus: string
{
    case Scheduled = 'scheduled';
    case Confirmed = 'confirmed';
    case CheckedIn = 'checked_in';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Confirmed => 'Confirmed',
            self::CheckedIn => 'Checked in',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::NoShow => 'No-show',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Scheduled => 'zinc',
            self::Confirmed => 'blue',
            self::CheckedIn => 'amber',
            self::Completed => 'green',
            self::Cancelled, self::NoShow => 'red',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Scheduled => [self::Confirmed, self::CheckedIn, self::Cancelled, self::NoShow],
            self::Confirmed => [self::CheckedIn, self::Cancelled, self::NoShow],
            self::CheckedIn => [self::Completed, self::Cancelled],
            self::Completed, self::Cancelled, self::NoShow => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /**
     * Pending bookings may still be rescheduled, cancelled or checked in.
     */
    public function isPending(): bool
    {
        return $this === self::Scheduled || $this === self::Confirmed;
    }

    /**
     * Statuses that occupy the provider's and patient's time (exclusion constraints).
     *
     * @return list<self>
     */
    public static function occupying(): array
    {
        return [self::Scheduled, self::Confirmed, self::CheckedIn, self::Completed];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
