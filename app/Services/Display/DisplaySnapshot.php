<?php

declare(strict_types=1);

namespace App\Services\Display;

use App\Enums\QueueTicketStatus;
use App\Models\DisplayDevice;
use App\Models\QueueEvent;
use App\Models\QueueTicket;
use App\Support\ClinicTime;
use Illuminate\Support\Facades\DB;

/**
 * The authoritative state a display loads on start and after every
 * reconnect. last_event_id lets the client discard broadcasts it has
 * already reflected. Same sanitized fields as DisplayPayload.
 */
final class DisplaySnapshot
{
    /**
     * @return array{last_event_id: int, now_serving: list<array{ticket: string, room: string|null, status: string, service_id: int, called_at: string|null}>, recent_calls: list<array{id: int, type: string, ticket: string, room: string|null, service_id: int, at: string}>}
     */
    public function for(DisplayDevice $device): array
    {
        $serviceIds = $device->serviceIds();
        $today = ClinicTime::today()->toDateString();

        // Read the high-water mark first: anything newer arrives as a broadcast.
        $lastEventId = (int) DB::table('queue_events')->max('id');

        $nowServing = $serviceIds === [] ? [] : QueueTicket::query()
            ->with('counter')
            ->whereIn('service_id', $serviceIds)
            ->whereDate('queue_date', $today)
            ->inStatus([QueueTicketStatus::Called, QueueTicketStatus::Serving])
            ->orderByDesc('called_at')
            ->get()
            ->map(fn (QueueTicket $ticket): array => [
                'ticket' => $ticket->ticket_number,
                'room' => $ticket->counter?->name,
                'status' => $ticket->status->value,
                'service_id' => $ticket->service_id,
                'called_at' => $ticket->called_at?->utc()->toIso8601String(),
            ])
            ->values()
            ->all();

        $recentCalls = $serviceIds === [] ? [] : QueueEvent::query()
            ->with(['ticket', 'counter'])
            ->whereIn('event_type', ['called', 'recalled'])
            ->where('id', '<=', $lastEventId)
            ->whereHas('ticket', fn ($query) => $query->whereIn('service_id', $serviceIds)->whereDate('queue_date', $today))
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (QueueEvent $event): array => DisplayPayload::fromEvent($event))
            ->values()
            ->all();

        return [
            'last_event_id' => $lastEventId,
            'now_serving' => array_values($nowServing),
            'recent_calls' => array_values($recentCalls),
        ];
    }
}
