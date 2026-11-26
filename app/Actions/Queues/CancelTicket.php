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
use Illuminate\Support\Facades\Validator;

/**
 * Removes a waiting, called or skipped ticket from the queue with a reason.
 * The visit stays open until reception records its disposition.
 */
final class CancelTicket
{
    use OperatesQueue;

    public function __construct(private readonly QueueEventRecorder $events) {}

    public function handle(User $actor, QueueTicket $ticket, string $reason, ?string $idempotencyKey = null): QueueTicket
    {
        Gate::forUser($actor)->authorize('cancel', $ticket);
        $key = $this->optionalKey($idempotencyKey);

        /** @var array{reason: string} $data */
        $data = Validator::make(['reason' => trim($reason)], ['reason' => ['required', 'string', 'max:255']])->validate();

        return ConstraintViolation::guard(fn (): QueueTicket => DB::transaction(function () use ($actor, $ticket, $data, $key): QueueTicket {
            $locked = $this->lockTicket($ticket);

            if (($replayed = $this->events->replay($key)) !== null) {
                return $replayed;
            }

            $this->ensureTransition($locked, QueueTicketStatus::Cancelled);
            $previous = $locked->status;
            $locked->status = QueueTicketStatus::Cancelled;
            $locked->save();

            $this->events->record($locked, QueueEventType::Cancelled, $actor, $previous, $data['reason'], idempotencyKey: $key);

            return $locked;
        }), [
            'queue_events_idempotency_key_unique' => ['ticket', __('This request was already processed.')],
        ]);
    }
}
