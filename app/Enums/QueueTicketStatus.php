<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Queue ticket lifecycle (docs/QUEUE_ENGINE.md).
 *
 * waiting → called | cancelled
 * called → serving | skipped | cancelled
 * skipped → waiting | cancelled
 * serving → completed | transferred
 * Recall is an event, not a transition.
 */
enum QueueTicketStatus: string
{
    case Waiting = 'waiting';
    case Called = 'called';
    case Serving = 'serving';
    case Skipped = 'skipped';
    case Completed = 'completed';
    case Transferred = 'transferred';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Waiting',
            self::Called => 'Called',
            self::Serving => 'Serving',
            self::Skipped => 'Skipped',
            self::Completed => 'Completed',
            self::Transferred => 'Transferred',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Waiting => 'zinc',
            self::Called => 'amber',
            self::Serving => 'blue',
            self::Skipped => 'orange',
            self::Completed, self::Transferred => 'green',
            self::Cancelled => 'red',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Waiting => [self::Called, self::Cancelled],
            self::Called => [self::Serving, self::Skipped, self::Cancelled],
            self::Skipped => [self::Waiting, self::Cancelled],
            self::Serving => [self::Completed, self::Transferred],
            self::Completed, self::Transferred, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /**
     * Tickets still in the queue or at a counter (one per visit/service).
     *
     * @return list<self>
     */
    public static function unresolved(): array
    {
        return [self::Waiting, self::Called, self::Serving, self::Skipped];
    }

    /**
     * Tickets a patient is currently queued or being served with.
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Waiting, self::Called, self::Serving];
    }

    /**
     * @param  list<self>  $statuses
     * @return list<string>
     */
    public static function valuesOf(array $statuses): array
    {
        return array_map(static fn (self $status): string => $status->value, $statuses);
    }
}
