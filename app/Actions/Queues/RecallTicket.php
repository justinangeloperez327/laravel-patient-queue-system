<?php

declare(strict_types=1);

namespace App\Actions\Queues;

use App\Enums\QueueEventType;
use App\Enums\QueueTicketStatus;
use App\Models\QueueTicket;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Announces a called ticket again. Recall is an event, not a transition,
 * and is limited to clinic.queue.max_recalls.
 */
final class RecallTicket extends TicketAtCounterAction
{
    protected function ability(): string
    {
        return 'operate';
    }

    protected function apply(User $actor, QueueTicket $ticket): QueueEventType
    {
        if ($ticket->status !== QueueTicketStatus::Called) {
            throw ValidationException::withMessages(['ticket' => __('Only a called ticket can be recalled.')]);
        }

        $max = config('clinic.queue.max_recalls');
        $max = is_int($max) ? $max : 3;

        if ($ticket->recallCount() >= $max) {
            throw ValidationException::withMessages([
                'ticket' => __(':number was already recalled :max times. Skip it or start service.', ['number' => $ticket->ticket_number, 'max' => $max]),
            ]);
        }

        return QueueEventType::Recalled;
    }
}
