<?php

use App\Actions\Queues\CallNextTicket;
use App\Actions\Queues\OpenCounterSession;
use App\Actions\Queues\StartTicket;
use App\Actions\Visits\RegisterWalkIn;
use App\Enums\QueueTicketStatus;
use App\Enums\Role;
use App\Models\Counter;
use App\Models\Department;
use App\Models\Patient;
use App\Models\QueueEvent;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Models\StaffAssignment;
use App\Models\User;
use Tests\Concurrency\Harness;

/*
| Separate PHP processes (separate PostgreSQL connections) race on committed
| data to prove there are no duplicate claims and that transfers are atomic.
*/

beforeEach(fn () => Harness::resetDatabase());
afterEach(fn () => Harness::resetDatabase());

const COUNTERS = 4;

/**
 * One department with service L, COUNTERS counters each staffed by its own
 * open session, and $waiting walk-in tickets.
 *
 * @return array{receptionist: User, service: Service, counters: list<Counter>, staff: list<User>}
 */
function queueRaceFixture(int $waiting): array
{
    $department = Department::factory()->create();
    $service = Service::factory()->create(['department_id' => $department->id, 'queue_prefix' => 'L']);
    $receptionist = User::factory()->withRole(Role::Receptionist)->create();
    $counters = [];
    $staff = [];

    for ($i = 0; $i < COUNTERS; $i++) {
        $counter = Counter::factory()->create(['department_id' => $department->id]);
        $counter->services()->attach($service->id, ['department_id' => $department->id]);
        $user = User::factory()->withRole(Role::ServiceStaff)->create();
        StaffAssignment::factory()->create(['user_id' => $user->id, 'department_id' => $department->id]);
        app(OpenCounterSession::class)->handle($user, $counter);
        $counters[] = $counter;
        $staff[] = $user;
    }

    foreach (Patient::factory()->count($waiting)->create(['registered_by' => $receptionist->id]) as $patient) {
        app(RegisterWalkIn::class)->handle($receptionist, ['patient_id' => $patient->id, 'service_id' => $service->id]);
    }

    return compact('receptionist', 'service', 'counters', 'staff');
}

test('counters calling at the same instant never claim the same ticket', function () {
    $fixture = queueRaceFixture(waiting: COUNTERS);

    $results = Harness::race('call-next', array_map(
        fn (int $i) => ['actor_id' => $fixture['staff'][$i]->id, 'counter_id' => $fixture['counters'][$i]->id],
        range(0, COUNTERS - 1),
    ));

    $claimed = collect($results)->where('ok', true)->pluck('id');

    expect($claimed)->toHaveCount(COUNTERS)
        ->and($claimed->unique())->toHaveCount(COUNTERS)
        ->and(QueueTicket::query()->where('status', QueueTicketStatus::Called->value)->pluck('counter_id')->unique())->toHaveCount(COUNTERS)
        ->and(QueueEvent::query()->where('event_type', 'called')->count())->toBe(COUNTERS);
});

test('when fewer patients wait than counters call, each patient is called exactly once', function () {
    $fixture = queueRaceFixture(waiting: 1);

    $results = Harness::race('call-next', array_map(
        fn (int $i) => ['actor_id' => $fixture['staff'][$i]->id, 'counter_id' => $fixture['counters'][$i]->id],
        range(0, COUNTERS - 1),
    ));

    $failures = collect($results)->where('ok', false);

    expect(collect($results)->where('ok', true))->toHaveCount(1)
        ->and($failures->every(fn (array $r) => array_key_exists('queue', $r['errors'] ?? [])))->toBeTrue()
        ->and(QueueEvent::query()->where('event_type', 'called')->count())->toBe(1);
});

test('one counter hammered with different requests holds at most one called ticket', function () {
    $fixture = queueRaceFixture(waiting: COUNTERS);
    $counter = $fixture['counters'][0];
    $user = $fixture['staff'][0];

    $results = Harness::race('call-next', array_map(
        fn (int $i) => ['actor_id' => $user->id, 'counter_id' => $counter->id, 'key' => "distinct-key-{$i}"],
        range(0, COUNTERS - 1),
    ));

    expect(collect($results)->where('ok', true))->toHaveCount(1)
        ->and(QueueTicket::query()->where('counter_id', $counter->id)->where('status', 'called')->count())->toBe(1);
});

test('a double-submitted call (same idempotency key) is applied once and returns the same ticket', function () {
    $fixture = queueRaceFixture(waiting: COUNTERS);

    $results = Harness::race('call-next', array_fill(0, COUNTERS, [
        'actor_id' => $fixture['staff'][0]->id, 'counter_id' => $fixture['counters'][0]->id, 'key' => 'same-double-click',
    ]));

    expect(collect($results)->where('ok', true))->toHaveCount(COUNTERS)
        ->and(collect($results)->pluck('id')->unique())->toHaveCount(1)
        ->and(QueueEvent::query()->where('event_type', 'called')->count())->toBe(1);
});

test('concurrent transfers of one ticket produce exactly one next ticket', function () {
    $fixture = queueRaceFixture(waiting: 1);
    $pharmacy = Service::factory()->create(['department_id' => $fixture['service']->department_id, 'queue_prefix' => 'P']);
    $ticket = app(CallNextTicket::class)->handle($fixture['staff'][0], $fixture['counters'][0]);
    $serving = app(StartTicket::class)->handle($fixture['staff'][0], $ticket);

    $results = Harness::race('transfer', array_map(
        fn (int $i) => ['actor_id' => $fixture['staff'][0]->id, 'ticket_id' => $serving->id, 'service_id' => $pharmacy->id, 'key' => "transfer-{$i}-key"],
        range(0, COUNTERS - 1),
    ));

    expect(collect($results)->where('ok', true))->toHaveCount(1)
        ->and(QueueTicket::query()->where('service_id', $pharmacy->id)->count())->toBe(1)
        ->and(QueueTicket::query()->find($serving->id)->status)->toBe(QueueTicketStatus::Transferred)
        ->and(QueueTicket::query()->where('visit_id', $serving->visit_id)->whereIn('status', ['waiting', 'called', 'serving', 'skipped'])->count())->toBe(1);
});
