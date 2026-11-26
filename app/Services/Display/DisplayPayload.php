<?php

declare(strict_types=1);

namespace App\Services\Display;

use App\Enums\QueueEventType;
use App\Models\QueueEvent;

/**
 * The only shape of data that ever leaves the server for a public display:
 * an event id (for de-duplication), what happened, the ticket number and the
 * room. Never names, dates of birth, visit details or clinical data
 * (docs/SECURITY.md).
 */
final class DisplayPayload
{
    /**
     * Queue events that change what a display shows.
     *
     * @return list<QueueEventType>
     */
    public static function relevantTypes(): array
    {
        return [
            QueueEventType::Called,
            QueueEventType::Recalled,
            QueueEventType::Started,
            QueueEventType::Skipped,
            QueueEventType::Completed,
            QueueEventType::Transferred,
            QueueEventType::Cancelled,
            QueueEventType::Requeued,
        ];
    }

    /**
     * @return array{id: int, type: string, ticket: string, room: string|null, service_id: int, at: string}
     */
    public static function fromEvent(QueueEvent $event): array
    {
        $ticket = $event->ticket;

        return [
            'id' => $event->id,
            'type' => $event->event_type->value,
            'ticket' => $ticket->ticket_number,
            'room' => $event->counter?->name,
            'service_id' => $ticket->service_id,
            'at' => $event->created_at->utc()->toIso8601String(),
        ];
    }
}
