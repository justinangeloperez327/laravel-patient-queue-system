<?php

use App\Actions\Clinical\RecordVitalSigns;
use App\Actions\Queues\TransferTicket;
use App\Models\AuditLog;
use App\Models\QueueTicket;
use App\Models\User;
use App\Models\VitalSign;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function recordVitals(array $fixture, array $input, ?User $user = null, ?QueueTicket $ticket = null): VitalSign
{
    return app(RecordVitalSigns::class)->handle($user ?? $fixture['nurse'], $ticket ?? $fixture['triageTicket'], $input);
}

test('the serving nurse records vital signs for the visit', function () {
    $fixture = clinicalFixture();
    $vitals = recordVitals($fixture, ['systolic_bp' => 128, 'diastolic_bp' => 82, 'temperature_celsius' => '38.4', 'oxygen_saturation' => 97, 'weight_kg' => '70.25', 'remarks' => '']);

    expect($vitals->visit_id)->toBe($fixture['triageTicket']->visit_id)
        ->and($vitals->queue_ticket_id)->toBe($fixture['triageTicket']->id)
        ->and($vitals->recorded_by)->toBe($fixture['nurse']->id)
        ->and($vitals->temperature_celsius)->toBe('38.4')
        ->and($vitals->remarks)->toBeNull()
        ->and(AuditLog::query()->where('action', 'clinical.vitals.recorded')->sole()->metadata)->toEqual(['visit_id' => $vitals->visit_id, 'ticket_id' => $vitals->queue_ticket_id]);
});

test('measurements are validated against their units', function (array $input, string $field) {
    $fixture = clinicalFixture();

    expectValidationError(fn () => recordVitals($fixture, $input), $field);
})->with([
    'systolic too high' => [['systolic_bp' => 320, 'diastolic_bp' => 80], 'systolic_bp'],
    'diastolic above systolic' => [['systolic_bp' => 100, 'diastolic_bp' => 120], 'diastolic_bp'],
    'blood pressure needs both values' => [['systolic_bp' => 120], 'diastolic_bp'],
    'heart rate' => [['heart_rate' => 300], 'heart_rate'],
    'respiratory rate' => [['respiratory_rate' => 2], 'respiratory_rate'],
    'temperature in Fahrenheit' => [['temperature_celsius' => 101.3], 'temperature_celsius'],
    'temperature precision' => [['temperature_celsius' => '37.25'], 'temperature_celsius'],
    'oxygen saturation' => [['oxygen_saturation' => 101], 'oxygen_saturation'],
    'weight' => [['weight_kg' => 0], 'weight_kg'],
    'height in metres' => [['height_cm' => 1.75], 'height_cm'],
    'pain score' => [['pain_score' => 11], 'pain_score'],
    'nothing measured' => [['remarks' => 'refused'], 'vitals'],
]);

test('only the clinician serving the ticket can record vitals', function () {
    $fixture = clinicalFixture();

    expectValidationError(fn () => recordVitals($fixture, ['heart_rate' => 80], $fixture['doctor']), 'ticket');

    $next = app(TransferTicket::class)->handle($fixture['nurse'], $fixture['triageTicket'], $fixture['services']['c']->id);
    expectValidationError(fn () => recordVitals($fixture, ['heart_rate' => 80]), 'ticket'); // no longer serving
    expectValidationError(fn () => recordVitals($fixture, ['heart_rate' => 80], ticket: $next), 'ticket'); // waiting, not served by the nurse
});

test('reception, service staff and administrators cannot record vitals', function () {
    $fixture = clinicalFixture();

    foreach ([$fixture['receptionist'], $fixture['serviceStaff'], admin()] as $user) {
        expect(fn () => recordVitals($fixture, ['heart_rate' => 80], $user))->toThrow(AuthorizationException::class);
    }
});

test('the database enforces units and keeps vitals append-only', function () {
    $fixture = clinicalFixture();
    $vitals = recordVitals($fixture, ['heart_rate' => 80]);
    $row = fn (array $values) => fn () => DB::table('vital_signs')->insert([
        'visit_id' => $vitals->visit_id, 'queue_ticket_id' => $vitals->queue_ticket_id, 'recorded_by' => $fixture['nurse']->id, 'recorded_at' => now(), ...$values,
    ]);

    expectConstraintViolation($row(['temperature_celsius' => 99.5]), 'vital_signs_temperature_range');
    expectConstraintViolation($row(['systolic_bp' => 100, 'diastolic_bp' => 110]), 'vital_signs_bp_order');
    expectConstraintViolation($row(['systolic_bp' => 100]), 'vital_signs_bp_pair');
    expectConstraintViolation($row(['remarks' => 'nothing']), 'vital_signs_not_empty');
    expectConstraintViolation($row(['oxygen_saturation' => 120]), 'vital_signs_oxygen_saturation_range');

    expect(fn () => DB::transaction(fn () => DB::table('vital_signs')->where('id', $vitals->id)->update(['heart_rate' => 90])))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::transaction(fn () => DB::table('vital_signs')->where('id', $vitals->id)->delete()))->toThrow(QueryException::class, 'append-only');
});

test('vitals must reference a ticket of the same visit', function () {
    $fixture = clinicalFixture();
    $other = walkInTicket($fixture);

    expectConstraintViolation(fn () => DB::table('vital_signs')->insert([
        'visit_id' => $fixture['triageTicket']->visit_id, 'queue_ticket_id' => $other->id,
        'recorded_by' => $fixture['nurse']->id, 'recorded_at' => now(), 'heart_rate' => 80,
    ]), 'vital_signs_queue_ticket_id_visit_id_foreign');
});
