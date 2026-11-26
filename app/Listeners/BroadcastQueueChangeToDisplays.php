<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\DisplayUpdated;
use App\Events\QueueTicketChanged;
use App\Models\DisplayDevice;
use App\Models\QueueEvent;
use App\Services\Display\DisplayPayload;

/**
 * Forwards committed queue changes to the displays showing the service.
 * QueueTicketChanged is itself dispatched only after commit, so displays
 * never see a change that was rolled back.
 */
final class BroadcastQueueChangeToDisplays
{
    public function handle(QueueTicketChanged $change): void
    {
        $relevant = array_map(static fn ($type) => $type->value, DisplayPayload::relevantTypes());

        if (! in_array($change->eventType, $relevant, true)) {
            return;
        }

        $deviceIds = DisplayDevice::query()->showingService($change->serviceId)->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();

        if ($deviceIds === []) {
            return;
        }

        $event = QueueEvent::query()->with(['ticket', 'counter'])->find($change->queueEventId);

        if ($event === null) {
            return;
        }

        DisplayUpdated::dispatch(array_values($deviceIds), DisplayPayload::fromEvent($event));
    }
}
