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
 * Base for actions taken by the staff member at the ticket's counter.
 */
abstract class TicketAtCounterAction
{
    use OperatesQueue;

    public function __construct(protected readonly QueueEventRecorder $events) {}

    abstract protected function ability(): string;

    /**
     * Apply the change to the locked ticket and return the event to record.
     */
    abstract protected function apply(User $actor, QueueTicket $ticket): QueueEventType;

    public function handle(User $actor, QueueTicket $ticket, ?string $idempotencyKey = null): QueueTicket
    {
        Gate::forUser($actor)->authorize($this->ability(), $ticket);
        $key = $this->optionalKey($idempotencyKey);

        return ConstraintViolation::guard(fn (): QueueTicket => DB::transaction(function () use ($actor, $ticket, $key): QueueTicket {
            $locked = $this->lockTicket($ticket);

            if (($replayed = $this->events->replay($key)) !== null) {
                return $replayed;
            }

            $this->ensureAtActorsCounter($actor, $locked);
            $previous = $locked->status;
            $type = $this->apply($actor, $locked);
            $locked->save();

            $this->events->record($locked, $type, $actor, $previous === $locked->status ? null : $previous, idempotencyKey: $key);

            return $locked;
        }), [
            'queue_events_idempotency_key_unique' => ['ticket', __('This request was already processed.')],
        ]);
    }

    protected function moveTo(QueueTicket $ticket, QueueTicketStatus $next): void
    {
        $this->ensureTransition($ticket, $next);
        $ticket->status = $next;
    }
}
