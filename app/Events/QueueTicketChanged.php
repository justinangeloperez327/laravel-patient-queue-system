<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Raised for every recorded queue event, only after the transaction
 * commits. Carries identifiers only (no patient data); phase 06 turns this
 * into sanitized public broadcasts.
 */
final class QueueTicketChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $queueEventId,
        public readonly int $ticketId,
        public readonly int $serviceId,
        public readonly ?int $counterId,
        public readonly string $eventType,
    ) {}
}
