<?php

use App\Actions\Queues\CancelTicket;
use App\Actions\Queues\ChangeTicketPriority;
use App\Actions\Queues\CloseCounterSession;
use App\Actions\Queues\CompleteTicket;
use App\Actions\Queues\RecallTicket;
use App\Actions\Queues\RequeueTicket;
use App\Actions\Queues\SkipTicket;
use App\Actions\Queues\StartTicket;
use App\Actions\Queues\TransferTicket;
use App\Actions\Visits\CheckInAppointment;
use App\Actions\Visits\CloseVisit;
use App\Enums\AppointmentStatus;
use App\Enums\ClinicalPriority;
use App\Enums\QueueTicketStatus;
use App\Enums\Role;
use App\Enums\VisitStatus;
use App\Models\Appointment;
use App\Models\Counter;
use App\Models\QueueEvent;
use App\Models\QueueTicket;
use App\Models\User;
use App\Models\Visit;

test('the status transition table matches the queue engine document', function () {
    expect(QueueTicketStatus::Waiting->allowedTransitions())->toBe([QueueTicketStatus::Called, QueueTicketStatus::Cancelled])
        ->and(QueueTicketStatus::Called->allowedTransitions())->toBe([QueueTicketStatus::Serving, QueueTicketStatus::Skipped, QueueTicketStatus::Cancelled])
        ->and(QueueTicketStatus::Skipped->allowedTransitions())->toBe([QueueTicketStatus::Waiting, QueueTicketStatus::Cancelled])
        ->and(QueueTicketStatus::Serving->allowedTransitions())->toBe([QueueTicketStatus::Completed, QueueTicketStatus::Transferred])
        ->and(QueueTicketStatus::Completed->allowedTransitions())->toBe([])
        ->and(QueueTicketStatus::Transferred->allowedTransitions())->toBe([])
        ->and(QueueTicketStatus::Cancelled->allowedTransitions())->toBe([]);
});

test('call, start and complete a walk-in; the visit completes with its last ticket', function () {
    $fixture = queueFixture();
    $ticket = walkInTicket($fixture);
    $staff = $fixture['serviceStaff'];
    openCounter($staff, $fixture['counters']['lab']);

    $called = callNext($staff, $fixture['counters']['lab']);
    expect($called->id)->toBe($ticket->id)
        ->and($called->status)->toBe(QueueTicketStatus::Called)
        ->and($called->counter_id)->toBe($fixture['counters']['lab']->id);

    $serving = act(StartTicket::class, $staff, $called);
    expect($serving->status)->toBe(QueueTicketStatus::Serving)
        ->and($serving->served_by)->toBe($staff->id)
        ->and(Visit::query()->find($ticket->visit_id)->status)->toBe(VisitStatus::InProgress);

    $done = act(CompleteTicket::class, $staff, $serving);
    $visit = Visit::query()->find($ticket->visit_id);

    expect($done->status)->toBe(QueueTicketStatus::Completed)
        ->and($done->completed_at)->not->toBeNull()
        ->and($visit->status)->toBe(VisitStatus::Completed)
        ->and($visit->completed_at)->not->toBeNull()
        ->and(QueueTicket::query()->find($ticket->id)->events()->pluck('event_type')->map->value->all())
        ->toBe(['issued', 'called', 'started', 'completed']);
});

test('completing an appointment visit completes the appointment', function () {
    $fixture = queueFixture();
    $appointment = Appointment::factory()->create([
        'service_id' => $fixture['services']['l']->id, 'provider_id' => $fixture['serviceStaff']->id,
        'scheduled_start_at' => '2030-01-07 08:10:00', 'scheduled_end_at' => '2030-01-07 08:25:00',
    ]);
    app(CheckInAppointment::class)->handle($fixture['receptionist'], $appointment);
    openCounter($fixture['serviceStaff'], $fixture['counters']['lab']);

    $ticket = act(StartTicket::class, $fixture['serviceStaff'], callNext($fixture['serviceStaff'], $fixture['counters']['lab']));
    act(CompleteTicket::class, $fixture['serviceStaff'], $ticket);

    expect($appointment->refresh()->status)->toBe(AppointmentStatus::Completed);
});

test('a counter must be open, and cannot call while a ticket is at it', function () {
    $fixture = queueFixture();
    walkInTicket($fixture);
    walkInTicket($fixture);
    $staff = $fixture['serviceStaff'];

    expectValidationError(fn () => callNext($staff, $fixture['counters']['lab']), 'counter');

    openCounter($staff, $fixture['counters']['lab']);
    callNext($staff, $fixture['counters']['lab']);

    expectValidationError(fn () => callNext($staff, $fixture['counters']['lab']), 'counter');
});

test('an empty queue is reported', function () {
    $fixture = queueFixture();
    openCounter($fixture['serviceStaff'], $fixture['counters']['lab']);

    expectValidationError(fn () => callNext($fixture['serviceStaff'], $fixture['counters']['lab']), 'queue');
});

test('recall is an event limited by the maximum, and only for called tickets', function () {
    config(['clinic.queue.max_recalls' => 2]);
    $fixture = queueFixture();
    walkInTicket($fixture);
    $staff = $fixture['serviceStaff'];
    openCounter($staff, $fixture['counters']['lab']);
    $ticket = callNext($staff, $fixture['counters']['lab']);

    act(RecallTicket::class, $staff, $ticket);
    $ticket = act(RecallTicket::class, $staff, $ticket);

    expect($ticket->status)->toBe(QueueTicketStatus::Called)->and($ticket->recallCount())->toBe(2);
    expectValidationError(fn () => act(RecallTicket::class, $staff, $ticket), 'ticket');

    $serving = act(StartTicket::class, $staff, $ticket);
    expectValidationError(fn () => act(RecallTicket::class, $staff, $serving), 'ticket');
});

test('skip frees the counter; requeue returns the ticket behind those already waiting', function () {
    $fixture = queueFixture();
    $first = walkInTicket($fixture);
    $this->travel(1)->minutes();
    $second = walkInTicket($fixture);
    $staff = $fixture['serviceStaff'];
    openCounter($staff, $fixture['counters']['lab']);

    $skipped = act(SkipTicket::class, $staff, callNext($staff, $fixture['counters']['lab']));
    expect($skipped->id)->toBe($first->id)->and($skipped->status)->toBe(QueueTicketStatus::Skipped);

    $this->travel(1)->minutes();
    $requeued = act(RequeueTicket::class, $fixture['receptionist'], $skipped);
    expect($requeued->status)->toBe(QueueTicketStatus::Waiting)
        ->and($requeued->counter_id)->toBeNull()
        ->and($requeued->ticket_number)->toBe('L-001')
        ->and($requeued->eligible_at->equalTo(now()))->toBeTrue();

    expect(callNext($staff, $fixture['counters']['lab'])->id)->toBe($second->id);
});

test('invalid transitions are rejected', function () {
    $fixture = queueFixture();
    walkInTicket($fixture);
    $staff = $fixture['serviceStaff'];
    openCounter($staff, $fixture['counters']['lab']);
    $called = callNext($staff, $fixture['counters']['lab']);

    expectValidationError(fn () => act(CompleteTicket::class, $staff, $called), 'ticket');
    expectValidationError(fn () => act(RequeueTicket::class, $staff, $called), 'ticket');
    $serving = act(StartTicket::class, $staff, $called);
    expectValidationError(fn () => act(SkipTicket::class, $staff, $serving), 'ticket');
    expectValidationError(fn () => app(CancelTicket::class)->handle($fixture['receptionist'], $serving, 'x'), 'ticket');
    $done = act(CompleteTicket::class, $staff, $serving);
    expectValidationError(fn () => act(StartTicket::class, $staff, $done), 'ticket');
});

test('only the staff member at the ticket\'s counter can act on it', function () {
    $fixture = queueFixture();
    walkInTicket($fixture);
    openCounter($fixture['serviceStaff'], $fixture['counters']['lab']);
    $ticket = callNext($fixture['serviceStaff'], $fixture['counters']['lab']);

    $colleague = providerIn($fixture['departments']['lab'], Role::ServiceStaff);
    expectValidationError(fn () => act(StartTicket::class, $colleague, $ticket), 'ticket');

    openCounter($colleague, Counter::factory()->create(['department_id' => $fixture['departments']['lab']->id]));
    expectValidationError(fn () => act(StartTicket::class, $colleague, $ticket), 'ticket');
});

test('transfer keeps the visit and clinical priority', function () {
    $fixture = queueFixture();
    walkInTicket($fixture, $fixture['services']['c']);
    $nurse = $fixture['nurse'];
    openCounter($nurse, $fixture['counters']['triage']);
    $called = callNext($nurse, $fixture['counters']['triage']);
    app(ChangeTicketPriority::class)->handle($nurse, $called, ClinicalPriority::High, 'Chest pain');
    $serving = act(StartTicket::class, $nurse, $called);

    $next = app(TransferTicket::class)->handle($nurse, $serving, $fixture['services']['c']->id, 'Vitals taken');
    $old = QueueTicket::query()->find($serving->id);

    expect($old->status)->toBe(QueueTicketStatus::Transferred)
        ->and($old->completed_at)->not->toBeNull()
        ->and($next->ticket_number)->toBe('C-001')
        ->and($next->visit_id)->toBe($old->visit_id)
        ->and($next->transferred_from_id)->toBe($old->id)
        ->and($next->status)->toBe(QueueTicketStatus::Waiting)
        ->and($next->priority)->toBe(ClinicalPriority::High)
        ->and($next->priority_reason)->toBe('Chest pain')
        ->and($old->events()->get()->last()->metadata['result_ticket_id'])->toBe($next->id);
});

test('a failed transfer changes nothing', function () {
    $fixture = queueFixture();
    walkInTicket($fixture, $fixture['services']['c']);
    $nurse = $fixture['nurse'];
    openCounter($nurse, $fixture['counters']['triage']);
    $serving = act(StartTicket::class, $nurse, callNext($nurse, $fixture['counters']['triage']));
    $fixture['services']['l']->forceFill(['is_active' => false])->save();
    $before = [QueueTicket::query()->count(), QueueEvent::query()->count()];

    expectValidationError(fn () => app(TransferTicket::class)->handle($nurse, $serving, $fixture['services']['l']->id), 'service_id');
    expectValidationError(fn () => app(TransferTicket::class)->handle($nurse, $serving, $fixture['services']['t']->id), 'service_id');

    expect(QueueTicket::query()->find($serving->id)->status)->toBe(QueueTicketStatus::Serving)
        ->and([QueueTicket::query()->count(), QueueEvent::query()->count()])->toBe($before);
});

test('the documented journey T → C → L → C → P stays in one visit', function () {
    $fixture = queueFixture();
    $first = walkInTicket($fixture, $fixture['services']['c']);
    $hop = function (User $user, string $counter, ?string $toService) use ($fixture) {
        openCounter($user, $fixture['counters'][$counter]);
        $serving = act(StartTicket::class, $user, callNext($user, $fixture['counters'][$counter]));
        $result = $toService === null
            ? act(CompleteTicket::class, $user, $serving)
            : app(TransferTicket::class)->handle($user, $serving, $fixture['services'][$toService]->id);
        app(CloseCounterSession::class)->handle($user);

        return $result;
    };

    $hop($fixture['nurse'], 'triage', 'c');
    $hop($fixture['doctor'], 'room1', 'l');
    $hop($fixture['serviceStaff'], 'lab', 'c');
    $hop($fixture['doctor'], 'room2', 'p');
    $hop($fixture['serviceStaff'], 'pharmacy', null);

    $tickets = QueueTicket::query()->where('visit_id', $first->visit_id)->orderBy('id')->get();

    expect($tickets->pluck('ticket_number')->all())->toBe(['T-001', 'C-001', 'L-001', 'C-002', 'P-001'])
        ->and($tickets->pluck('status')->map->value->all())->toBe(['transferred', 'transferred', 'transferred', 'transferred', 'completed'])
        ->and(Visit::query()->find($first->visit_id)->status)->toBe(VisitStatus::Completed);
});

test('cancel needs a reason and leaves the visit open for an explicit disposition', function () {
    $fixture = queueFixture();
    $ticket = walkInTicket($fixture);

    expectValidationError(fn () => app(CancelTicket::class)->handle($fixture['receptionist'], $ticket, ' '), 'reason');

    $cancelled = app(CancelTicket::class)->handle($fixture['receptionist'], $ticket, 'Patient left the queue');

    expect($cancelled->status)->toBe(QueueTicketStatus::Cancelled)
        ->and($cancelled->events()->get()->last()->remarks)->toBe('Patient left the queue')
        ->and(Visit::query()->find($ticket->visit_id)->status)->toBe(VisitStatus::CheckedIn);
});

test('closing a visit cancels waiting or skipped tickets but not one at a counter', function () {
    $fixture = queueFixture();
    $ticket = walkInTicket($fixture);
    $visit = Visit::query()->findOrFail($ticket->visit_id);
    $close = app(CloseVisit::class);
    openCounter($fixture['serviceStaff'], $fixture['counters']['lab']);
    $called = callNext($fixture['serviceStaff'], $fixture['counters']['lab']);

    expectValidationError(fn () => $close->handle($fixture['receptionist'], $visit, VisitStatus::LeftWithoutService, 'Left'), 'status');

    act(SkipTicket::class, $fixture['serviceStaff'], $called);
    $close->handle($fixture['receptionist'], $visit, VisitStatus::LeftWithoutService, 'Left');

    expect(QueueTicket::query()->find($ticket->id)->status)->toBe(QueueTicketStatus::Cancelled)
        ->and($visit->refresh()->status)->toBe(VisitStatus::LeftWithoutService);
});

test('a visit already in service can be closed only as left without service', function () {
    $fixture = queueFixture();
    walkInTicket($fixture, $fixture['services']['c']);
    $nurse = $fixture['nurse'];
    openCounter($nurse, $fixture['counters']['triage']);
    $serving = act(StartTicket::class, $nurse, callNext($nurse, $fixture['counters']['triage']));
    $next = app(TransferTicket::class)->handle($nurse, $serving, $fixture['services']['c']->id);
    $visit = Visit::query()->findOrFail($next->visit_id);

    expectValidationError(fn () => app(CloseVisit::class)->handle($fixture['receptionist'], $visit, VisitStatus::Cancelled, 'x'), 'status');

    app(CloseVisit::class)->handle($fixture['receptionist'], $visit, VisitStatus::LeftWithoutService, 'Could not wait');
    expect($visit->refresh()->status)->toBe(VisitStatus::LeftWithoutService)
        ->and($next->refresh()->status)->toBe(QueueTicketStatus::Cancelled);
});
