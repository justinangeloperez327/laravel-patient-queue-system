<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Allocates the next per-service sequence number for a clinic-local queue
 * date. The upsert takes a row lock on the (service, date) counter, so
 * concurrent issuers receive distinct consecutive numbers; the unique
 * (service_id, queue_date, sequence_number) constraint is the backstop.
 */
final class QueueSequenceAllocator
{
    public function next(Service $service, CarbonImmutable $queueDate): int
    {
        $row = DB::selectOne(
            <<<'SQL'
                INSERT INTO queue_sequences (service_id, queue_date, last_number, created_at, updated_at)
                VALUES (?, ?, 1, now(), now())
                ON CONFLICT (service_id, queue_date)
                DO UPDATE SET last_number = queue_sequences.last_number + 1, updated_at = now()
                RETURNING last_number
                SQL,
            [$service->id, $queueDate->toDateString()],
        );

        return (int) $row->last_number;
    }

    public static function format(string $prefix, int $sequence): string
    {
        return sprintf('%s-%03d', $prefix, $sequence);
    }
}
