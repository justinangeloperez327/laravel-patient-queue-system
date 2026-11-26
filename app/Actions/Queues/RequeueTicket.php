<?php

declare(strict_types=1);

namespace App\Actions\Queues;

use App\Enums\QueueEventType;
use App\Enums\QueueTicketStatus;
use App\Models\QueueTicket;
use App\Models\User;
use App\Services\Queue\QueueEventRecorder;
use App\Support\ConstraintViolation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Returns a skipped ticket to the queue. Requeue ordering: the ticket keeps
 * its number, clinical priority and scheduling preference, and becomes
 * eligible again from now (it rejoins behind those already waiting in its
 * class rather than jumping the queue).
 */
final class RequeueTicket
{
    use OperatesQueue;

    public function __construct(private readonly QueueEventRecorder $events) {}

    public function handle(User $actor, QueueTicket $ticket, ?string $idempotencyKey = null): QueueTicket
    {
        Gate::forUser($actor)->authorize('requeue', $ticket);
        $key = $this->optionalKey($idempotencyKey);

        return ConstraintViolation::guard(fn (): QueueTicket => DB::transaction(function () use ($actor, $ticket, $key): QueueTicket {
            $locked = $this->lockTicket($ticket);

            if (($replayed = $this->events->replay($key)) !== null) {
                return $replayed;
            }

            $this->ensureTransition($locked, QueueTicketStatus::Waiting);
            $previous = $locked->status;
            $locked->status = QueueTicketStatus::Waiting;
            $locked->counter_id = null;
            $locked->eligible_at = now();
            $locked->save();

            $this->events->record($locked, QueueEventType::Requeued, $actor, $previous, idempotencyKey: $key);

            return $locked;
        }), [
            'queue_events_idempotency_key_unique' => ['ticket', __('This request was already processed.')],
        ]);
    }
}
