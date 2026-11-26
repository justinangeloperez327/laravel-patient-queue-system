<?php

declare(strict_types=1);

namespace App\Actions\Queues;

use App\Enums\QueueTicketStatus;
use App\Models\CounterSession;
use App\Models\QueueTicket;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Shared locking and validation for queue actions. Every action locks the
 * rows it changes (FOR UPDATE) inside its transaction.
 */
trait OperatesQueue
{
    protected function lockTicket(QueueTicket $ticket): QueueTicket
    {
        /** @var QueueTicket $locked */
        $locked = QueueTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

        return $locked;
    }

    protected function ensureTransition(QueueTicket $ticket, QueueTicketStatus $next): void
    {
        if (! $ticket->status->canTransitionTo($next)) {
            throw ValidationException::withMessages([
                'ticket' => __('Ticket :number is :status and cannot become :next.', [
                    'number' => $ticket->ticket_number,
                    'status' => strtolower($ticket->status->label()),
                    'next' => strtolower($next->label()),
                ]),
            ]);
        }
    }

    /**
     * The actor must staff the counter the ticket is at.
     */
    protected function ensureAtActorsCounter(User $actor, QueueTicket $ticket): CounterSession
    {
        $session = CounterSession::query()
            ->open()
            ->where('user_id', $actor->id)
            ->when($ticket->counter_id !== null, fn ($query) => $query->where('counter_id', $ticket->counter_id))
            ->lockForUpdate()
            ->first();

        if ($session === null || $ticket->counter_id === null) {
            throw ValidationException::withMessages([
                'ticket' => __('Ticket :number is not at your counter.', ['number' => $ticket->ticket_number]),
            ]);
        }

        return $session;
    }

    protected function optionalKey(?string $idempotencyKey): ?string
    {
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9_-]{8,100}$/', $idempotencyKey) !== 1) {
            throw ValidationException::withMessages(['idempotency_key' => __('Invalid request key.')]);
        }

        return $idempotencyKey;
    }
}
