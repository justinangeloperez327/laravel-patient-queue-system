<?php

use App\Actions\Queues\ChangeTicketPriority;
use App\Enums\ClinicalPriority;
use App\Enums\SchedulingPreference;
use App\Models\QueueTicket;
use App\Models\Visit;
use App\Services\Queue\QueueOrdering;
use Carbon\CarbonImmutable;

/**
 * Create a waiting lab ticket directly with chosen ordering attributes.
 */
function waitingTicket(array $fixture, string $number, array $attributes = []): QueueTicket
{
    static $sequence = 100;
    $ticket = new QueueTicket;
    $ticket->visit_id = Visit::factory()->create(['requested_service_id' => $fixture['services']['l']->id])->id;
    $ticket->service_id = $fixture['services']['l']->id;
    $ticket->ticket_number = $number;
    $ticket->sequence_number = ++$sequence;
    $ticket->queue_date = CarbonImmutable::parse('2030-01-07');
    $ticket->issued_by = $fixture['receptionist']->id;
    $ticket->queued_at = $attributes['queued_at'] ?? now();
    $ticket->eligible_at = $attributes['eligible_at'] ?? $ticket->queued_at;
    unset($attributes['queued_at'], $attributes['eligible_at']);
    foreach ($attributes as $key => $value) {
        $ticket->{$key} = $value;
    }
    $ticket->save();

    return $ticket;
}

function callOrder(array $fixture, ?int $providerId = null): array
{
    return app(QueueOrdering::class)->eligible([$fixture['services']['l']->id], $providerId)->pluck('ticket_number')->all();
}

test('clinical priority comes first, then arrival', function () {
    $fixture = queueFixture();
    waitingTicket($fixture, 'L-001', ['queued_at' => now()->subMinutes(30)]);
    waitingTicket($fixture, 'L-002', ['queued_at' => now()->subMinutes(20), 'priority' => ClinicalPriority::Urgent, 'priority_reason' => 'x']);
    waitingTicket($fixture, 'L-003', ['queued_at' => now()->subMinutes(10), 'priority' => ClinicalPriority::High, 'priority_reason' => 'x']);
    waitingTicket($fixture, 'L-004', ['queued_at' => now()->subMinutes(25)]);

    expect(callOrder($fixture))->toBe(['L-002', 'L-003', 'L-001', 'L-004']);
});

test('eligible appointment tickets precede recent walk-ins but not clinical urgency', function () {
    $fixture = queueFixture();
    waitingTicket($fixture, 'L-001', ['queued_at' => now()->subMinutes(20)]);
    waitingTicket($fixture, 'L-002', ['queued_at' => now()->subMinutes(30), 'eligible_at' => now()->subMinutes(5), 'scheduling_preference' => SchedulingPreference::Appointment]);
    waitingTicket($fixture, 'L-003', ['queued_at' => now()->subMinutes(1), 'priority' => ClinicalPriority::High, 'priority_reason' => 'x']);

    expect(callOrder($fixture))->toBe(['L-003', 'L-002', 'L-001']);
});

test('a walk-in waiting beyond the safeguard competes with appointments by eligibility time', function () {
    $fixture = queueFixture();
    waitingTicket($fixture, 'L-001', ['queued_at' => now()->subMinutes(50)]); // past the 45-minute safeguard
    waitingTicket($fixture, 'L-002', ['queued_at' => now()->subMinutes(60), 'eligible_at' => now()->subMinutes(10), 'scheduling_preference' => SchedulingPreference::Appointment]);
    waitingTicket($fixture, 'L-003', ['queued_at' => now()->subMinutes(40)]); // not yet

    expect(callOrder($fixture))->toBe(['L-001', 'L-002', 'L-003']);

    config(['clinic.queue.walk_in_safeguard_minutes' => 60]);
    expect(callOrder($fixture))->toBe(['L-002', 'L-001', 'L-003']);
});

test('tickets that are not yet eligible, from other days or for another provider are not offered', function () {
    $fixture = queueFixture();
    waitingTicket($fixture, 'L-001', ['eligible_at' => now()->addMinutes(10), 'scheduling_preference' => SchedulingPreference::Appointment]);
    waitingTicket($fixture, 'L-002', ['queue_date' => CarbonImmutable::parse('2030-01-06')]);
    waitingTicket($fixture, 'L-003', ['assigned_provider_id' => $fixture['doctor']->id]);
    waitingTicket($fixture, 'L-004');

    expect(callOrder($fixture))->toBe(['L-004'])
        ->and(callOrder($fixture, $fixture['doctor']->id))->toBe(['L-003', 'L-004']);
});

test('ties are broken by sequence number for a stable order', function () {
    $fixture = queueFixture();
    waitingTicket($fixture, 'L-002', ['sequence_number' => 2]);
    waitingTicket($fixture, 'L-001', ['sequence_number' => 1]);

    expect(callOrder($fixture))->toBe(['L-001', 'L-002']);
});

test('raising priority by a clinician moves the ticket up, with a reason and an event', function () {
    $fixture = queueFixture();
    walkInTicket($fixture, $fixture['services']['t']);
    $later = walkInTicket($fixture, $fixture['services']['t']);

    expectValidationError(fn () => app(ChangeTicketPriority::class)->handle($fixture['nurse'], $later, ClinicalPriority::Urgent, ''), 'priority_reason');

    app(ChangeTicketPriority::class)->handle($fixture['nurse'], $later, ClinicalPriority::Urgent, 'Short of breath');
    openCounter($fixture['nurse'], $fixture['counters']['triage']);

    expect(callNext($fixture['nurse'], $fixture['counters']['triage'])->id)->toBe($later->id)
        ->and($later->events()->where('event_type', 'priority_changed')->sole()->metadata)->toEqual(['from' => 0, 'to' => 2]);
});
