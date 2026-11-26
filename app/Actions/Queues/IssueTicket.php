<?php

declare(strict_types=1);

namespace App\Actions\Queues;

use App\Models\QueueTicket;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\Queue\TicketIssuer;
use App\Services\Queue\TriageRouting;
use App\Support\ConstraintViolation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Reception issues a further ticket for an open visit that has none in
 * progress (e.g. after a cancelled ticket). Check-in issues the first
 * ticket automatically.
 */
final class IssueTicket
{
    public function __construct(
        private readonly TicketIssuer $issuer,
        private readonly TriageRouting $routing,
    ) {}

    public function handle(User $actor, Visit $visit, Service $service, bool $sendToTriage = false): QueueTicket
    {
        Gate::forUser($actor)->authorize('create', QueueTicket::class);

        return ConstraintViolation::guard(fn (): QueueTicket => DB::transaction(function () use ($actor, $visit, $service, $sendToTriage): QueueTicket {
            /** @var Visit $locked */
            $locked = Visit::query()->whereKey($visit->id)->lockForUpdate()->firstOrFail();

            return $this->issuer->issue($actor, $locked, $this->routing->firstService($service, $sendToTriage));
        }), [
            'queue_tickets_one_unresolved_per_visit_service' => ['visit', __('This visit already has a ticket for that service.')],
        ]);
    }
}
