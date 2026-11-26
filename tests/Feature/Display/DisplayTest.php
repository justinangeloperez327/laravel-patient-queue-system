<?php

use App\Actions\Displays\SaveDisplayDevice;
use App\Actions\Queues\CompleteTicket;
use App\Actions\Queues\StartTicket;
use App\Enums\Role;
use App\Events\DisplayUpdated;
use App\Livewire\Displays\DisplayForm;
use App\Models\AuditLog;
use App\Models\DisplayDevice;
use App\Models\Patient;
use App\Models\QueueTicket;
use App\Models\Visit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

beforeEach(function () {
    config([
        'broadcasting.connections.reverb.key' => 'test-app-key',
        'broadcasting.connections.reverb.secret' => 'test-app-secret',
    ]);
});

/**
 * @return array{0: DisplayDevice, 1: string}
 */
function makeDisplay(array $fixture, array $services, array $overrides = []): array
{
    return app(SaveDisplayDevice::class)->handle($fixture['admin'] ?? admin(), [
        'name' => 'Waiting room',
        'service_ids' => array_map(fn ($service) => $service->id, $services),
        'voice_lang' => 'en-US',
        'is_active' => true,
        ...$overrides,
    ]);
}

function pairDisplay(TestCase $test, DisplayDevice $device, string $key): void
{
    $test->post(route('display.pair', $device), ['key' => $key])->assertRedirect(route('display.show', $device));
}

/**
 * A lab patient called to LAB-1 by the service staff member.
 */
function callLabPatient(array $fixture, ?Patient $patient = null): QueueTicket
{
    walkInTicket($fixture, $fixture['services']['l'], $patient);
    openCounter($fixture['serviceStaff'], $fixture['counters']['lab']);

    return callNext($fixture['serviceStaff'], $fixture['counters']['lab']);
}

test('an administrator creates a display and sees its key exactly once', function () {
    $fixture = queueFixture();

    $component = Livewire::actingAs(admin())->test(DisplayForm::class)
        ->set('name', 'Main waiting room')
        ->set('service_ids', [(string) $fixture['services']['l']->id])
        ->call('save')
        ->assertHasNoErrors();

    $key = $component->get('newKey');
    $device = DisplayDevice::query()->sole();

    expect($key)->toMatch('/^[a-z0-9]{8}-[a-z0-9]{8}-[a-z0-9]{8}$/')
        ->and($device->credential_hash)->toBe(hash('sha256', $key))
        ->and(DB::table('display_devices')->where('credential_hash', $key)->exists())->toBeFalse()
        ->and($device->toArray())->not->toHaveKey('credential_hash')
        ->and(json_encode(AuditLog::query()->get()->toArray()))->not->toContain($key);

    Livewire::actingAs(admin())->test(DisplayForm::class, ['device' => $device])->assertSet('newKey', null);
});

test('only administrators manage displays', function (Role $role) {
    $fixture = queueFixture();
    $user = staffWithRole($role);

    $this->actingAs($user)->get(route('displays.index'))->assertForbidden();
    expect(fn () => app(SaveDisplayDevice::class)->handle($user, ['name' => 'x', 'service_ids' => [$fixture['services']['l']->id], 'voice_lang' => 'en-US', 'is_active' => true]))
        ->toThrow(AuthorizationException::class);
})->with([Role::Receptionist, Role::Nurse, Role::Doctor, Role::ServiceStaff]);

test('display settings are validated', function (array $overrides, string $field) {
    $fixture = queueFixture();

    expectValidationError(fn () => makeDisplay($fixture, [$fixture['services']['l']], $overrides), $field);
})->with([
    'no services' => [['service_ids' => []], 'service_ids'],
    'unknown service' => [['service_ids' => [999999]], 'service_ids.0'],
    'bad language tag' => [['voice_lang' => 'english'], 'voice_lang'],
]);

test('an unpaired screen only sees the pairing form', function () {
    $fixture = queueFixture();
    [$device] = makeDisplay($fixture, [$fixture['services']['l']]);

    $this->get(route('display.show', $device))->assertOk()->assertSee('Pair this screen')->assertDontSee('data-channel', false);
    $this->get(route('display.snapshot', $device))->assertForbidden();
    $this->post(route('display.auth', $device), ['socket_id' => '1.1', 'channel_name' => 'private-'.$device->channelName()])->assertForbidden();
});

test('pairing needs the right key for an active device and gives one generic error', function () {
    $fixture = queueFixture();
    [$device, $key] = makeDisplay($fixture, [$fixture['services']['l']]);
    [$other] = makeDisplay($fixture, [$fixture['services']['l']]);

    $this->from(route('display.show', $device))->post(route('display.pair', $device), ['key' => 'wrong'])->assertSessionHasErrors(['key' => 'This display key is not valid.']);
    $this->post(route('display.pair', $other), ['key' => $key])->assertSessionHasErrors('key'); // key of another device

    pairDisplay($this, $device, '  '.$key.' ');
    $this->get(route('display.show', $device))->assertOk()->assertSee('data-channel="display-device.'.$device->id.'"', false);
});

test('pairing attempts are rate limited', function () {
    $fixture = queueFixture();
    [$device] = makeDisplay($fixture, [$fixture['services']['l']]);

    foreach (range(1, 5) as $attempt) {
        $this->post(route('display.pair', $device), ['key' => 'guess-'.$attempt]);
    }

    $this->post(route('display.pair', $device), ['key' => 'guess-6'])->assertStatus(429);
});

test('deactivating a display or rotating its key cuts off a paired screen immediately', function (string $revocation) {
    $fixture = queueFixture();
    [$device, $key] = makeDisplay($fixture, [$fixture['services']['l']]);
    pairDisplay($this, $device, $key);
    $this->get(route('display.snapshot', $device))->assertOk();

    $revocation === 'deactivate'
        ? app(SaveDisplayDevice::class)->handle(admin(), ['name' => $device->name, 'service_ids' => $device->serviceIds(), 'voice_lang' => 'en-US', 'is_active' => false], $device)
        : app(SaveDisplayDevice::class)->rotateKey(admin(), $device);

    $this->get(route('display.snapshot', $device))->assertForbidden();
    $this->post(route('display.auth', $device), ['socket_id' => '1.1', 'channel_name' => 'private-'.$device->channelName()])->assertForbidden();
    $this->get(route('display.show', $device))->assertSee('Pair this screen');
})->with(['deactivate', 'rotate']);

test('a paired screen is authorized for its own private channel only', function () {
    $fixture = queueFixture();
    [$device, $key] = makeDisplay($fixture, [$fixture['services']['l']]);
    [$other] = makeDisplay($fixture, [$fixture['services']['l']]);
    pairDisplay($this, $device, $key);
    $channel = 'private-'.$device->channelName();

    $this->post(route('display.auth', $device), ['socket_id' => '123.456', 'channel_name' => $channel])
        ->assertOk()
        ->assertExactJson(['auth' => 'test-app-key:'.hash_hmac('sha256', '123.456:'.$channel, 'test-app-secret')]);

    $this->post(route('display.auth', $device), ['socket_id' => '123.456', 'channel_name' => 'private-'.$other->channelName()])->assertForbidden();
    $this->post(route('display.auth', $device), ['socket_id' => 'abc', 'channel_name' => $channel])->assertForbidden();
    $this->post(route('display.auth', $other), ['socket_id' => '123.456', 'channel_name' => 'private-'.$other->channelName()])->assertForbidden();
});

test('the snapshot holds ticket numbers and rooms only, for the configured services', function () {
    $fixture = queueFixture();
    $patient = Patient::factory()->create(['first_name' => 'Uniquefirst', 'last_name' => 'Uniquelast']);
    $called = callLabPatient($fixture, $patient);
    $pharmacyTicket = walkInTicket($fixture, $fixture['services']['p']); // waiting, other service
    [$device, $key] = makeDisplay($fixture, [$fixture['services']['l']]);
    pairDisplay($this, $device, $key);

    $response = $this->get(route('display.snapshot', $device))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $json = $response->json();

    expect(array_keys($json))->toBe(['last_event_id', 'now_serving', 'recent_calls'])
        ->and($json['now_serving'])->toBe([[
            'ticket' => 'L-001', 'room' => 'LAB-1', 'status' => 'called', 'service_id' => $fixture['services']['l']->id,
            'called_at' => $called->called_at->utc()->toIso8601String(),
        ]])
        ->and(array_keys($json['recent_calls'][0]))->toBe(['id', 'type', 'ticket', 'room', 'service_id', 'at'])
        ->and($json['last_event_id'])->toBe((int) DB::table('queue_events')->max('id'));

    $body = $response->getContent();
    $visit = Visit::query()->findOrFail($called->visit_id);
    foreach (['Uniquefirst', 'Uniquelast', $patient->patient_number, $visit->visit_number, $pharmacyTicket->ticket_number] as $forbidden) {
        expect($body)->not->toContain($forbidden);
    }

    expect($device->refresh()->last_seen_at)->not->toBeNull();
});

test('queue changes are broadcast after commit with a sanitized payload to the right displays', function () {
    $fixture = queueFixture();
    [$lab] = makeDisplay($fixture, [$fixture['services']['l']]);
    [$labAndPharmacy] = makeDisplay($fixture, [$fixture['services']['l'], $fixture['services']['p']]);
    [$pharmacyOnly] = makeDisplay($fixture, [$fixture['services']['p']]);
    [$inactive] = makeDisplay($fixture, [$fixture['services']['l']], ['is_active' => false]);
    Event::fake([DisplayUpdated::class]);

    $patient = Patient::factory()->create(['first_name' => 'Uniquefirst', 'last_name' => 'Uniquelast']);
    $ticket = callLabPatient($fixture, $patient);

    Event::assertDispatchedTimes(DisplayUpdated::class, 1); // "issued" is not broadcast
    Event::assertDispatched(DisplayUpdated::class, function (DisplayUpdated $event) use ($lab, $labAndPharmacy, $ticket) {
        $channels = array_map(fn ($channel) => $channel->name, $event->broadcastOn());
        sort($channels);

        return $channels === ['private-display-device.'.$lab->id, 'private-display-device.'.$labAndPharmacy->id]
            && array_keys($event->broadcastWith()) === ['id', 'type', 'ticket', 'room', 'service_id', 'at']
            && $event->broadcastWith()['type'] === 'called'
            && $event->broadcastWith()['ticket'] === $ticket->ticket_number
            && $event->broadcastWith()['room'] === 'LAB-1'
            && $event->broadcastAs() === 'display.updated'
            && ! str_contains(json_encode($event->broadcastWith()), 'Unique');
    });

    act(CompleteTicket::class, $fixture['serviceStaff'], act(StartTicket::class, $fixture['serviceStaff'], $ticket));
    $types = collect(Event::dispatched(DisplayUpdated::class))->map(fn ($call) => $call[0]->payload['type'])->all();

    expect($types)->toBe(['called', 'started', 'completed']);
});

test('nothing is broadcast for a rolled-back change', function () {
    $fixture = queueFixture();
    makeDisplay($fixture, [$fixture['services']['l']]);
    walkInTicket($fixture);
    openCounter($fixture['serviceStaff'], $fixture['counters']['lab']);
    Event::fake([DisplayUpdated::class]);

    try {
        DB::transaction(function () use ($fixture) {
            callNext($fixture['serviceStaff'], $fixture['counters']['lab']);
            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    Event::assertNotDispatched(DisplayUpdated::class);
});

test('display broadcasts are queued, not sent inline with the staff request', function () {
    $event = new DisplayUpdated([1], ['id' => 1, 'type' => 'called', 'ticket' => 'L-001', 'room' => 'LAB-1', 'service_id' => 1, 'at' => '']);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->and($event)->not->toBeInstanceOf(ShouldBroadcastNow::class)
        ->and($event->afterCommit)->toBeTrue()
        ->and($event->tries)->toBe(3);
});

test('the screen page contains no patient data', function () {
    $fixture = queueFixture();
    callLabPatient($fixture, Patient::factory()->create(['first_name' => 'Uniquefirst', 'last_name' => 'Uniquelast']));
    [$device, $key] = makeDisplay($fixture, [$fixture['services']['l']], ['title' => 'Laboratory']);
    pairDisplay($this, $device, $key);

    $this->get(route('display.show', $device))
        ->assertOk()
        ->assertSee('Laboratory')
        ->assertSee('Enable sound')
        ->assertDontSee('Uniquefirst')
        ->assertDontSee('Uniquelast')
        ->assertDontSee('test-app-secret');
});
