<?php

declare(strict_types=1);

namespace App\Actions\Queues;

use App\Enums\QueueEventType;
use App\Enums\QueueTicketStatus;
use App\Models\QueueTicket;
use App\Models\User;

/**
 * The called patient did not come forward. Frees the counter; the ticket
 * keeps the counter for history and can be requeued or cancelled.
 */
final class SkipTicket extends TicketAtCounterAction
{
    protected function ability(): string
    {
        return 'operate';
    }

    protected function apply(User $actor, QueueTicket $ticket): QueueEventType
    {
        $this->moveTo($ticket, QueueTicketStatus::Skipped);

        return QueueEventType::Skipped;
    }
}
