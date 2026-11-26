<?php

use App\Actions\Queues\CancelTicket;
use App\Actions\Queues\ChangeTicketPriority;
use App\Actions\Queues\CloseCounterSession;
use App\Actions\Queues\IssueTicket;
use App\Actions\Queues\RequeueTicket;
use App\Enums\ClinicalPriority;
use App\Livewire\Queues\QueueBoard;
use App\Models\Visit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('counters can only be opened by queue staff assigned to the department', function () {
    $fixture = queueFixture();

    expect(fn () => openCounter($fixture['nurse'], $fixture['counters']['lab']))->toThrow(AuthorizationException::class)
        ->and(fn () => openCounter($fixture['receptionist'], $fixture['counters']['lab']))->toThrow(AuthorizationException::class)
        ->and(fn () => openCounter(admin(), $fixture['counters']['lab']))->toThrow(AuthorizationException::class);

    $fixture['counters']['lab']->forceFill(['is_active' => false])->save();
    expect(fn () => openCounter($fixture['serviceStaff'], $fixture['counters']['lab']))->toThrow(AuthorizationException::class);
});

test('one open session per counter and per staff member', function () {
    $fixture = queueFixture();
    $colleague = providerIn($fixture['departments']['opd']);
    $session = openCounter($fixture['doctor'], $fixture['counters']['room1']);

    expect(openCounter($fixture['doctor'], $fixture['counters']['room1'])->id)->toBe($session->id); // idempotent
    expectValidationError(fn () => openCounter($colleague, $fixture['counters']['room1']), 'counter');
    expectValidationError(fn () => openCounter($fixture['doctor'], $fixture['counters']['room2']), 'counter');

    app(CloseCounterSession::class)->handle($fixture['doctor']);
    expect(openCounter($colleague, $fixture['counters']['room1'])->user_id)->toBe($colleague->id);
});

test('the database allows one open session per counter and per user', function () {
    $fixture = queueFixture();
    openCounter($fixture['doctor'], $fixture['counters']['room1']);
    $row = fn (array $overrides) => fn () => DB::table('counter_sessions')->insert(['opened_at' => now(), ...$overrides]);

    expectConstraintViolation($row(['counter_id' => $fixture['counters']['room1']->id, 'user_id' => $fixture['nurse']->id]), 'counter_sessions_one_open_per_counter');
    expectConstraintViolation($row(['counter_id' => $fixture['counters']['room2']->id, 'user_id' => $fixture['doctor']->id]), 'counter_sessions_one_open_per_user');
});

test('a counter cannot close while a ticket is at it', function () {
    $fixture = queueFixture();
    walkInTicket($fixture);
    openCounter($fixture['serviceStaff'], $fixture['counters']['lab']);
    callNext($fixture['serviceStaff'], $fixture['counters']['lab']);

    expectValidationError(fn () => app(CloseCounterSession::class)->handle($fixture['serviceStaff']), 'counter');
});

test('reception may requeue and cancel any ticket; staff only within their departments', function () {
    $fixture = queueFixture();
    $lab = walkInTicket($fixture);

    expect(fn () => app(CancelTicket::class)->handle($fixture['nurse'], $lab, 'x'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RequeueTicket::class)->handle($fixture['nurse'], $lab))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CancelTicket::class)->handle(admin(), $lab, 'x'))->toThrow(AuthorizationException::class);

    expect(app(CancelTicket::class)->handle($fixture['serviceStaff'], $lab, 'Duplicate')->status->value)->toBe('cancelled');
    $other = walkInTicket($fixture);
    expect(app(CancelTicket::class)->handle($fixture['receptionist'], $other, 'Left')->status->value)->toBe('cancelled');
});

test('clinical priority is set only by clinicians within scope', function () {
    $fixture = queueFixture();
    $triage = walkInTicket($fixture, $fixture['services']['t']);

    foreach ([$fixture['receptionist'], $fixture['serviceStaff'], $fixture['doctor'], admin()] as $user) {
        expect(fn () => app(ChangeTicketPriority::class)->handle($user, $triage, ClinicalPriority::High, 'x'))->toThrow(AuthorizationException::class);
    }

    expect(app(ChangeTicketPriority::class)->handle($fixture['nurse'], $triage, ClinicalPriority::High, 'Fever')->priority)->toBe(ClinicalPriority::High);
});

test('only reception issues tickets', function () {
    $fixture = queueFixture();
    $visit = Visit::query()->findOrFail(walkInTicket($fixture)->visit_id);

    expect(fn () => app(IssueTicket::class)->handle($fixture['serviceStaff'], $visit, $fixture['services']['p']))->toThrow(AuthorizationException::class);
});

test('queue screens are limited to reception and queue staff', function () {
    $fixture = queueFixture();

    $this->actingAs($fixture['receptionist'])->get(route('queues.index'))->assertOk();
    $this->actingAs($fixture['receptionist'])->get(route('workspace'))->assertForbidden();
    $this->actingAs($fixture['nurse'])->get(route('queues.index'))->assertOk();
    $this->actingAs($fixture['nurse'])->get(route('workspace'))->assertOk()->assertSee('TRIAGE-1')->assertDontSee('ROOM-1');
    $this->actingAs(admin())->get(route('queues.index'))->assertForbidden();
    $this->actingAs(admin())->get(route('workspace'))->assertForbidden();
});

test('the board shows staff only their departments\' services', function () {
    $fixture = queueFixture();
    walkInTicket($fixture);

    $nurseServices = Livewire::actingAs($fixture['nurse'])->test(QueueBoard::class)->instance()->services->pluck('queue_prefix')->all();
    $receptionServices = Livewire::actingAs($fixture['receptionist'])->test(QueueBoard::class)->instance()->services->pluck('queue_prefix')->all();

    expect($nurseServices)->toBe(['T'])
        ->and($receptionServices)->toBe(['C', 'L', 'P', 'T']);
});
