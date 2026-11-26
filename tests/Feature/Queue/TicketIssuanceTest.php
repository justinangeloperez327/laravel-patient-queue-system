<?php

use App\Actions\Appointments\BookAppointment;
use App\Actions\Queues\CancelTicket;
use App\Actions\Queues\IssueTicket;
use App\Actions\Visits\CheckInAppointment;
use App\Actions\Visits\CloseVisit;
use App\Enums\QueueTicketStatus;
use App\Enums\SchedulingPreference;
use App\Enums\TriageMode;
use App\Enums\VisitStatus;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\ProviderSchedule;
use App\Models\QueueTicket;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

test('a walk-in receives the first ticket in the same transaction as check-in', function () {
    $fixture = queueFixture();
    $ticket = walkInTicket($fixture);

    expect($ticket->ticket_number)->toBe('L-001')
        ->and($ticket->status)->toBe(QueueTicketStatus::Waiting)
        ->and($ticket->queue_date->toDateString())->toBe('2030-01-07')
        ->and($ticket->scheduling_preference)->toBe(SchedulingPreference::Standard)
        ->and($ticket->eligible_at->equalTo($ticket->queued_at))->toBeTrue()
        ->and($ticket->issued_by)->toBe($fixture['receptionist']->id)
        ->and($ticket->events()->sole()->event_type->value)->toBe('issued');
});

test('triage routing chooses the first queue', function (string $mode, bool $send, string $expectedPrefix) {
    $fixture = queueFixture();
    $consultation = $fixture['services']['c'];
    $consultation->forceFill(['triage_mode' => TriageMode::from($mode), 'triage_service_id' => $mode === 'none' ? null : $fixture['services']['t']->id])->save();

    expect(walkInTicket($fixture, $consultation, sendToTriage: $send)->ticket_number)->toStartWith($expectedPrefix.'-');
})->with([
    'required' => ['required', false, 'T'],
    'optional, sent' => ['optional', true, 'T'],
    'optional, not sent' => ['optional', false, 'C'],
    'none, even if asked' => ['none', true, 'C'],
]);

test('numbers run per service and reset each clinic day while history keeps its prefix', function () {
    $fixture = queueFixture();

    expect(walkInTicket($fixture)->ticket_number)->toBe('L-001')
        ->and(walkInTicket($fixture)->ticket_number)->toBe('L-002')
        ->and(walkInTicket($fixture, $fixture['services']['p'])->ticket_number)->toBe('P-001');

    $fixture['services']['l']->forceFill(['queue_prefix' => 'LB'])->save();
    $this->travelTo(CarbonImmutable::parse('2030-01-08 07:00:00', 'UTC'));

    expect(walkInTicket($fixture)->ticket_number)->toBe('LB-001')
        ->and(QueueTicket::query()->orderBy('id')->first()->ticket_number)->toBe('L-001');
});

test('the operational date follows the clinic timezone', function () {
    config(['clinic.timezone' => 'America/New_York']);
    $fixture = queueFixture();
    $this->travelTo(CarbonImmutable::parse('2030-01-08 03:00:00', 'UTC')); // 22:00 on the 7th in New York

    expect(walkInTicket($fixture)->queue_date->toDateString())->toBe('2030-01-07');
});

test('appointment check-in issues an appointment-preference ticket eligible before the slot', function () {
    $fixture = queueFixture();
    ProviderSchedule::factory()->create(['provider_id' => $fixture['doctor']->id, 'service_id' => $fixture['services']['c']->id, 'start_time' => '09:00', 'end_time' => '12:00']);
    $fixture['services']['c']->forceFill(['triage_mode' => TriageMode::Optional])->save();
    $appointment = app(BookAppointment::class)->handle($fixture['receptionist'], [
        'patient_id' => Patient::factory()->create()->id, 'service_id' => $fixture['services']['c']->id,
        'provider_id' => $fixture['doctor']->id, 'scheduled_start_at' => '2030-01-07T10:00:00+00:00',
    ]);

    $ticket = app(CheckInAppointment::class)->handle($fixture['receptionist'], $appointment)->tickets()->sole();

    expect($ticket->ticket_number)->toBe('C-001')
        ->and($ticket->scheduling_preference)->toBe(SchedulingPreference::Appointment)
        ->and($ticket->eligible_at->utc()->format('H:i'))->toBe('09:45')
        ->and($ticket->assigned_provider_id)->toBe($fixture['doctor']->id);
});

test('an appointment routed through triage gets an unassigned triage ticket', function () {
    $fixture = queueFixture();
    $appointment = Appointment::factory()->create([
        'service_id' => $fixture['services']['c']->id, 'provider_id' => $fixture['doctor']->id,
        'scheduled_start_at' => '2030-01-07 08:05:00', 'scheduled_end_at' => '2030-01-07 08:20:00',
    ]);

    $ticket = app(CheckInAppointment::class)->handle($fixture['receptionist'], $appointment)->tickets()->sole();

    expect($ticket->ticket_number)->toBe('T-001')
        ->and($ticket->assigned_provider_id)->toBeNull()
        ->and($ticket->scheduling_preference)->toBe(SchedulingPreference::Appointment)
        ->and($ticket->eligible_at->equalTo(now()))->toBeTrue(); // 08:05 − 15 min is already past
});

test('a visit holds one unresolved ticket; reception can issue a new one after cancellation', function () {
    $fixture = queueFixture();
    $ticket = walkInTicket($fixture);
    $visit = Visit::query()->findOrFail($ticket->visit_id);

    expectValidationError(fn () => app(IssueTicket::class)->handle($fixture['receptionist'], $visit, $fixture['services']['p']), 'visit');

    app(CancelTicket::class)->handle($fixture['receptionist'], $ticket, 'Wrong queue');
    $new = app(IssueTicket::class)->handle($fixture['receptionist'], $visit, $fixture['services']['p']);

    expect($new->ticket_number)->toBe('P-001')->and($new->visit_id)->toBe($visit->id);
});

test('tickets are not issued for closed visits or inactive services', function () {
    $fixture = queueFixture();
    $ticket = walkInTicket($fixture);
    $visit = Visit::query()->findOrFail($ticket->visit_id);
    app(CloseVisit::class)->handle($fixture['receptionist'], $visit, VisitStatus::LeftWithoutService, 'Left');

    expectValidationError(fn () => app(IssueTicket::class)->handle($fixture['receptionist'], $visit->refresh(), $fixture['services']['p']), 'visit');

    $fixture['services']['p']->forceFill(['is_active' => false])->save();
    expectValidationError(fn () => walkInTicket($fixture, $fixture['services']['p']), 'service_id');
    expect(Visit::query()->count())->toBe(1); // the failed walk-in rolled back its visit too
});

test('the database enforces ticket invariants', function () {
    $fixture = queueFixture();
    $ticket = walkInTicket($fixture);
    $update = fn (array $values) => fn () => DB::table('queue_tickets')->where('id', $ticket->id)->update($values);
    $insert = fn (array $overrides) => fn () => DB::table('queue_tickets')->insert([
        'visit_id' => $ticket->visit_id, 'service_id' => $ticket->service_id, 'ticket_number' => 'L-099', 'sequence_number' => 99,
        'queue_date' => '2030-01-07', 'status' => 'cancelled', 'issued_by' => $fixture['receptionist']->id,
        'queued_at' => now(), 'eligible_at' => now(), ...$overrides,
    ]);

    expectConstraintViolation($insert(['sequence_number' => 1]), 'queue_tickets_service_id_queue_date_sequence_number_unique');
    expectConstraintViolation($insert(['status' => 'waiting']), 'queue_tickets_one_unresolved_per_visit_service');
    expectConstraintViolation($insert(['ticket_number' => 'lab-1']), 'queue_tickets_number_format');
    expectConstraintViolation($update(['status' => 'called', 'called_at' => now()]), 'queue_tickets_counter_when_at_counter');
    expectConstraintViolation($update(['status' => 'called', 'counter_id' => $fixture['counters']['lab']->id]), 'queue_tickets_called_at_present');
    expectConstraintViolation($update(['status' => 'completed', 'counter_id' => $fixture['counters']['lab']->id, 'called_at' => now(), 'completed_at' => now(), 'served_by' => $fixture['serviceStaff']->id]), 'queue_tickets_started_at_present');
    expectConstraintViolation($update(['status' => 'completed', 'counter_id' => $fixture['counters']['lab']->id, 'called_at' => now(), 'started_at' => now(), 'served_by' => $fixture['serviceStaff']->id]), 'queue_tickets_completed_at_present');
    expectConstraintViolation($update(['priority' => 2]), 'queue_tickets_priority_reason');
    expectConstraintViolation($update(['priority' => 3, 'priority_reason' => 'x']), 'queue_tickets_priority_range');
    expectConstraintViolation($update(['eligible_at' => now()->subMinute()]), 'queue_tickets_eligible_after_queued');
    expectConstraintViolation($update(['status' => 'paused']), 'queue_tickets_status_valid');
});

test('a counter can hold only one called or serving ticket in the database', function () {
    $fixture = queueFixture();
    $first = walkInTicket($fixture);
    $second = walkInTicket($fixture);
    $atCounter = ['status' => 'called', 'counter_id' => $fixture['counters']['lab']->id, 'called_at' => now()];

    DB::table('queue_tickets')->where('id', $first->id)->update($atCounter);
    expectConstraintViolation(fn () => DB::table('queue_tickets')->where('id', $second->id)->update($atCounter), 'queue_tickets_one_active_per_counter');
});
