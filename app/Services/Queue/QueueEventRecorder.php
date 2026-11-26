<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Enums\QueueEventType;
use App\Enums\QueueTicketStatus;
use App\Events\QueueTicketChanged;
use App\Models\QueueEvent;
use App\Models\QueueTicket;
use App\Models\User;

/**
 * Writes the append-only queue event for every transition, recall and
 * priority change, then raises QueueTicketChanged after commit.
 */
final class QueueEventRecorder
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        QueueTicket $ticket,
        QueueEventType $type,
        ?User $actor,
        ?QueueTicketStatus $previous,
        ?string $remarks = null,
        array $metadata = [],
        ?string $idempotencyKey = null,
    ): QueueEvent {
        if ($idempotencyKey !== null) {
            $metadata['idempotency_key'] = $idempotencyKey;
        }

        $event = new QueueEvent;
        $event->queue_ticket_id = $ticket->id;
        $event->user_id = $actor?->id;
        $event->event_type = $type;
        $event->previous_status = $previous;
        $event->new_status = $ticket->status;
        $event->counter_id = $ticket->counter_id;
        $event->remarks = $remarks === null || trim($remarks) === '' ? null : trim($remarks);
        $event->metadata = $metadata;
        $event->save();

        QueueTicketChanged::dispatch($event->id, $ticket->id, $ticket->service_id, $ticket->counter_id, $type->value);

        return $event;
    }

    /**
     * The ticket produced by an earlier request with the same idempotency key.
     */
    public function replay(?string $idempotencyKey): ?QueueTicket
    {
        if ($idempotencyKey === null) {
            return null;
        }

        $event = QueueEvent::query()
            ->whereRaw("metadata->>'idempotency_key' = ?", [$idempotencyKey])
            ->first();

        if ($event === null) {
            return null;
        }

        $resultId = $event->metadata['result_ticket_id'] ?? $event->queue_ticket_id;

        return QueueTicket::query()->whereKey(is_numeric($resultId) ? (int) $resultId : $event->queue_ticket_id)->first();
    }
}
