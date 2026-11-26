<?php

use App\Actions\Queues\CloseCounterSession;
use App\Actions\Queues\CompleteTicket;
use App\Actions\Queues\SkipTicket;
use App\Actions\Queues\StartTicket;
use App\Actions\Queues\TransferTicket;
use App\Events\QueueTicketChanged;
use App\Livewire\Queues\QueueBoard;
use App\Livewire\Queues\Workspace;
use App\Models\CounterSession;
use App\Models\QueueEvent;
use App\Models\QueueTicket;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

test('every transition records previous and new status', function () {
    $fixture = queueFixture();
    $ticket = walkInTicket($fixture);
    $staff = $fixture['serviceStaff'];
    openCounter($staff, $fixture['counters']['lab']);
    act(CompleteTicket::class, $staff, act(StartTicket::class, $staff, callNext($staff, $fixture['counters']['lab'])));

    $events = QueueEvent::query()->where('queue_ticket_id', $ticket->id)->orderBy('id')->get()
        ->map(fn (QueueEvent $e) => [$e->event_type->value, $e->previous_status?->value, $e->new_status?->value, $e->user_id])->all();

    expect($events)->toBe([
        ['issued', null, 'waiting', $fixture['receptionist']->id],
        ['called', 'waiting', 'called', $staff->id],
        ['started', 'called', 'serving', $staff->id],
        ['completed', 'serving', 'completed', $staff->id],
    ]);
});

test('queue events are append-only', function () {
    $fixture = queueFixture();
    $event = QueueEvent::query()->where('queue_ticket_id', walkInTicket($fixture)->id)->sole();

    expect(fn () => DB::transaction(fn () => DB::table('queue_events')->where('id', $event->id)->update(['remarks' => 'tampered'])))
        ->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::transaction(fn () => DB::table('queue_events')->where('id', $event->id)->delete()))
        ->toThrow(QueryException::class, 'append-only');
});

test('the same idempotency key is applied once', function () {
    $fixture = queueFixture();
    walkInTicket($fixture);
    walkInTicket($fixture);
    $staff = $fixture['serviceStaff'];
    openCounter($staff, $fixture['counters']['lab']);

    $first = callNext($staff, $fixture['counters']['lab'], 'double-click-key-1');
    $again = callNext($staff, $fixture['counters']['lab'], 'double-click-key-1');

    expect($again->id)->toBe($first->id)
        ->and(QueueEvent::query()->where('event_type', 'called')->count())->toBe(1);

    $serving = act(StartTicket::class, $staff, $first, 'start-key-0001');
    $next = app(TransferTicket::class)->handle($staff, $serving, $fixture['services']['p']->id, null, 'transfer-key-01');
    $replayed = app(TransferTicket::class)->handle($staff, $serving, $fixture['services']['p']->id, null, 'transfer-key-01');

    expect($replayed->id)->toBe($next->id)
        ->and(QueueTicket::query()->where('service_id', $fixture['services']['p']->id)->count())->toBe(1);

    expectValidationError(fn () => callNext($staff, $fixture['counters']['lab'], 'bad key!'), 'idempotency_key');
});

test('change events are dispatched only after commit and carry no patient data', function () {
    $fixture = queueFixture();
    Event::fake([QueueTicketChanged::class]);

    try {
        DB::transaction(function () use ($fixture) {
            walkInTicket($fixture);
            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    Event::assertNotDispatched(QueueTicketChanged::class);

    $ticket = walkInTicket($fixture);

    Event::assertDispatched(QueueTicketChanged::class, function (QueueTicketChanged $event) use ($ticket) {
        return $event->ticketId === $ticket->id
            && $event->eventType === 'issued'
            && array_keys(get_object_vars($event)) === ['queueEventId', 'ticketId', 'serviceId', 'counterId', 'eventType'];
    });
});

test('the end-of-day command cancels unfinished tickets from earlier days only', function () {
    $fixture = queueFixture();
    $waiting = walkInTicket($fixture);
    $skipped = walkInTicket($fixture);
    $serving = walkInTicket($fixture, $fixture['services']['p']);
    $staff = $fixture['serviceStaff'];
    openCounter($staff, $fixture['counters']['lab']);
    callNext($staff, $fixture['counters']['lab']); // waiting ticket is first: call and skip it
    act(SkipTicket::class, $staff, QueueTicket::query()->find($waiting->id));
    app(CloseCounterSession::class)->handle($staff);
    openCounter($staff, $fixture['counters']['pharmacy']);
    act(StartTicket::class, $staff, callNext($staff, $fixture['counters']['pharmacy']));

    $this->artisan('queue:close-day')->expectsOutputToContain('Cancelled 0')->assertSuccessful();

    $this->travelTo(CarbonImmutable::parse('2030-01-08 06:00:00', 'UTC'));
    $today = walkInTicket($fixture);

    $this->artisan('queue:close-day')
        ->expectsOutputToContain('Cancelled 2')
        ->expectsOutputToContain('P-001')
        ->assertSuccessful();

    expect($waiting->refresh()->status->value)->toBe('cancelled')
        ->and($skipped->refresh()->status->value)->toBe('cancelled')
        ->and($serving->refresh()->status->value)->toBe('serving')
        ->and($today->refresh()->status->value)->toBe('waiting')
        ->and($skipped->events()->get()->last()->remarks)->toBe('End of day');
});

test('the workspace drives a counter from opening to completion', function () {
    $fixture = queueFixture();
    $ticket = walkInTicket($fixture);
    $staff = $fixture['serviceStaff'];

    $component = Livewire::actingAs($staff)->test(Workspace::class)
        ->call('openCounter', $fixture['counters']['lab']->id)
        ->assertSee($ticket->ticket_number);

    $key = $component->get('requestKey');
    $component->call('callNext')->assertHasNoErrors()->assertSee('Called L-001');
    expect($component->get('requestKey'))->not->toBe($key);

    $component->call('recall')->assertHasNoErrors()
        ->call('start')->assertHasNoErrors()
        ->call('complete')->assertHasNoErrors()
        ->call('closeCounter')->assertHasNoErrors();

    expect($ticket->refresh()->status->value)->toBe('completed')
        ->and(CounterSession::query()->open()->count())->toBe(0);
});

test('the board requeues and cancels', function () {
    $fixture = queueFixture();
    $ticket = walkInTicket($fixture);
    openCounter($fixture['serviceStaff'], $fixture['counters']['lab']);
    act(SkipTicket::class, $fixture['serviceStaff'], callNext($fixture['serviceStaff'], $fixture['counters']['lab']));

    Livewire::actingAs($fixture['receptionist'])->test(QueueBoard::class)
        ->assertSee('L-001')
        ->call('requeue', $ticket->id)->assertHasNoErrors()
        ->call('editTicket', $ticket->id, 'cancel')
        ->set('ticketReason', 'Went home')
        ->call('saveTicketEdit')->assertHasNoErrors();

    expect($ticket->refresh()->status->value)->toBe('cancelled');
});
