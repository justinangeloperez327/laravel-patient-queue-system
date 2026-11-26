<?php

declare(strict_types=1);

namespace App\Enums;

enum QueueEventType: string
{
    case Issued = 'issued';
    case Called = 'called';
    case Recalled = 'recalled';
    case Started = 'started';
    case Skipped = 'skipped';
    case Requeued = 'requeued';
    case Completed = 'completed';
    case Transferred = 'transferred';
    case Cancelled = 'cancelled';
    case PriorityChanged = 'priority_changed';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
