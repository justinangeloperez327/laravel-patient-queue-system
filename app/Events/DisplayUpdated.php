<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A sanitized queue change pushed to the private channels of the displays
 * showing the service. Queued (a broadcast outage never breaks a staff
 * action) and dispatched only after the database commit.
 */
final class DisplayUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public bool $afterCommit = true;

    /**
     * Retry briefly through short Reverb outages. A late or retried delivery
     * is harmless: displays ignore events already covered by their snapshot.
     */
    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [2, 10];

    /**
     * @param  list<int>  $deviceIds
     * @param  array{id: int, type: string, ticket: string, room: string|null, service_id: int, at: string}  $payload
     */
    public function __construct(
        public readonly array $deviceIds,
        public readonly array $payload,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(static fn (int $id): PrivateChannel => new PrivateChannel('display-device.'.$id), $this->deviceIds);
    }

    public function broadcastAs(): string
    {
        return 'display.updated';
    }

    /**
     * @return array{id: int, type: string, ticket: string, room: string|null, service_id: int, at: string}
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
