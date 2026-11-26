<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Enums\QueueTicketStatus;
use App\Enums\SchedulingPreference;
use App\Models\QueueTicket;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stable call order for waiting tickets (docs/QUEUE_ENGINE.md):
 *
 * 1. clinical priority (urgent first) — never overridden by scheduling;
 * 2. scheduling class: appointment tickets, and walk-ins that have waited
 *    longer than the safeguard, before other walk-ins (prevents both
 *    appointment slippage and walk-in starvation);
 * 3. eligibility time (arrival, appointment window or requeue time);
 * 4. sequence number, then id, for a total, stable order.
 */
final class QueueOrdering
{
    /**
     * Waiting tickets of today that are eligible now, in call order.
     *
     * @param  list<int>  $serviceIds
     * @return Builder<QueueTicket>
     */
    public function eligible(array $serviceIds, ?int $providerId = null, ?CarbonImmutable $now = null): Builder
    {
        $now ??= CarbonImmutable::now();

        return $this->ordered(
            QueueTicket::query()
                ->whereIn('service_id', $serviceIds)
                ->where('status', QueueTicketStatus::Waiting->value)
                ->whereDate('queue_date', ClinicTime::today()->toDateString())
                ->where('eligible_at', '<=', $now)
                // Appointment tickets assigned to a provider are only offered to that provider.
                ->where(fn (Builder $query) => $query->whereNull('assigned_provider_id')->when(
                    $providerId !== null,
                    fn (Builder $query) => $query->orWhere('assigned_provider_id', $providerId),
                )),
            $now,
        );
    }

    /**
     * @param  Builder<QueueTicket>  $query
     * @return Builder<QueueTicket>
     */
    public function ordered(Builder $query, ?CarbonImmutable $now = null): Builder
    {
        $now ??= CarbonImmutable::now();
        $safeguard = config('clinic.queue.walk_in_safeguard_minutes');
        $cutoff = $now->subMinutes(is_int($safeguard) ? $safeguard : 45);

        return $query
            ->orderByDesc('priority')
            ->orderByRaw(
                'CASE WHEN scheduling_preference = ? OR eligible_at <= ? THEN 0 ELSE 1 END',
                [SchedulingPreference::Appointment->value, $cutoff],
            )
            ->orderBy('eligible_at')
            ->orderBy('sequence_number')
            ->orderBy('id');
    }
}
