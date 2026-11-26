<?php

declare(strict_types=1);

namespace App\Actions\Queues;

use App\Enums\QueueEventType;
use App\Enums\QueueTicketStatus;
use App\Enums\VisitStatus;
use App\Models\QueueTicket;
use App\Models\User;
use App\Models\Visit;

/**
 * The patient is at the counter: service starts. The visit moves to
 * in progress on its first started ticket.
 */
final class StartTicket extends TicketAtCounterAction
{
    protected function ability(): string
    {
        return 'operate';
    }

    protected function apply(User $actor, QueueTicket $ticket): QueueEventType
    {
        $this->moveTo($ticket, QueueTicketStatus::Serving);
        $ticket->started_at = now();
        $ticket->served_by = $actor->id;

        /** @var Visit $visit */
        $visit = Visit::query()->whereKey($ticket->visit_id)->lockForUpdate()->firstOrFail();

        if ($visit->status === VisitStatus::CheckedIn) {
            $visit->status = VisitStatus::InProgress;
            $visit->save();
        }

        return QueueEventType::Started;
    }
}
