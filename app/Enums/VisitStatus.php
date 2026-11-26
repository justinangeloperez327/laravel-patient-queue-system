<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Visit lifecycle (docs/WORKFLOW.md). in_progress and completed are driven by
 * the queue engine (phase 04).
 *
 * checked_in → in_progress | cancelled | left_without_service
 * in_progress → completed | left_without_service
 */
enum VisitStatus: string
{
    case CheckedIn = 'checked_in';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case LeftWithoutService = 'left_without_service';

    public function label(): string
    {
        return match ($this) {
            self::CheckedIn => 'Checked in',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::LeftWithoutService => 'Left without service',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::CheckedIn => 'amber',
            self::InProgress => 'blue',
            self::Completed => 'green',
            self::Cancelled, self::LeftWithoutService => 'red',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::CheckedIn => [self::InProgress, self::Cancelled, self::LeftWithoutService],
            self::InProgress => [self::Completed, self::LeftWithoutService],
            self::Completed, self::Cancelled, self::LeftWithoutService => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isOpen(): bool
    {
        return $this === self::CheckedIn || $this === self::InProgress;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
