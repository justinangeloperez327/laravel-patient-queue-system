<?php

use App\Actions\Patients\RegisterPatient;
use App\Actions\Patients\UpdatePatientDemographics;
use App\Enums\Role;
use App\Livewire\Patients\PatientForm;
use App\Livewire\Patients\PatientIndex;
use App\Models\Patient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function patientInput(array $overrides = []): array
{
    return [
        'first_name' => 'Alex',
        'middle_name' => '',
        'last_name' => 'Sample',
        'date_of_birth' => '1990-04-12',
        'sex' => 'female',
        'phone' => '+1 555 0100',
        'email' => 'Alex.Sample@Example.test',
        'nationality' => '',
        'address' => '',
        'emergency_contact_name' => '',
        'emergency_contact_phone' => '',
        ...$overrides,
    ];
}

test('a receptionist registers a patient through the form', function () {
    $receptionist = receptionist();

    Livewire::actingAs($receptionist)->test(PatientForm::class)
        ->set('first_name', 'Alex')
        ->set('last_name', 'Sample')
        ->set('date_of_birth', '1990-04-12')
        ->set('phone', '+1 555 0100')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('patients.index'));

    $patient = Patient::query()->sole();

    expect($patient->patient_number)->toMatch('/^PT-\d{6}$/')
        ->and($patient->registered_by)->toBe($receptionist->id)
        ->and($patient->middle_name)->toBeNull()
        ->and($patient->is_active)->toBeTrue();
});

test('patient numbers come from a sequence and are never reused', function () {
    $receptionist = receptionist();
    $register = app(RegisterPatient::class);

    $first = $register->handle($receptionist, patientInput(['first_name' => 'One']));
    $second = $register->handle($receptionist, patientInput(['first_name' => 'Two']));

    $firstNumber = (int) substr($first->patient_number, 3);
    expect((int) substr($second->patient_number, 3))->toBe($firstNumber + 1);

    // A failed registration consumes no visible number but never causes reuse.
    expectValidationError(fn () => $register->handle($receptionist, patientInput(['first_name' => ''])), 'first_name');
    $third = $register->handle($receptionist, patientInput(['first_name' => 'Three']));

    expect(Patient::query()->pluck('patient_number')->unique())->toHaveCount(3)
        ->and((int) substr($third->patient_number, 3))->toBeGreaterThan($firstNumber + 1);
});

test('demographics are validated', function (array $overrides, string $field) {
    expectValidationError(fn () => app(RegisterPatient::class)->handle(receptionist(), patientInput($overrides)), $field);
})->with([
    'missing first name' => [['first_name' => ''], 'first_name'],
    'missing last name' => [['last_name' => ' '], 'last_name'],
    'future date of birth' => [['date_of_birth' => now()->addDays(2)->toDateString()], 'date_of_birth'],
    'implausible date of birth' => [['date_of_birth' => '1850-01-01'], 'date_of_birth'],
    'malformed date of birth' => [['date_of_birth' => '12/04/1990'], 'date_of_birth'],
    'invalid sex' => [['sex' => 'robot'], 'sex'],
    'invalid phone' => [['phone' => 'call me'], 'phone'],
    'invalid email' => [['email' => 'not-an-email'], 'email'],
]);

test('email is normalised and blank optional fields are stored as null', function () {
    $patient = app(RegisterPatient::class)->handle(receptionist(), patientInput(['nationality' => '   ']));

    expect($patient->email)->toBe('alex.sample@example.test')
        ->and($patient->nationality)->toBeNull();
});

test('a likely duplicate must be confirmed before registering', function () {
    $receptionist = receptionist();
    $existing = app(RegisterPatient::class)->handle($receptionist, patientInput());

    try {
        app(RegisterPatient::class)->handle($receptionist, patientInput(['first_name' => 'ALEX', 'last_name' => 'sample']));
        $this->fail('Expected a duplicate warning.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['duplicate'][0])->toContain($existing->patient_number);
    }

    app(RegisterPatient::class)->handle($receptionist, patientInput(['confirm_duplicate' => true]));

    expect(Patient::query()->count())->toBe(2);
});

test('a receptionist updates demographics and deactivates a patient but cannot change the number', function () {
    $receptionist = receptionist();
    $patient = Patient::factory()->create();
    $number = $patient->patient_number;

    app(UpdatePatientDemographics::class)->handle($receptionist, $patient, patientInput([
        'last_name' => 'Renamed',
        'is_active' => false,
        'patient_number' => 'PT-999999',
        'registered_by' => $receptionist->id,
    ]));

    $patient->refresh();

    expect($patient->last_name)->toBe('Renamed')
        ->and($patient->is_active)->toBeFalse()
        ->and($patient->patient_number)->toBe($number)
        ->and($patient->registered_by)->not->toBe($receptionist->id);
});

test('the edit form loads and saves an existing patient', function () {
    $patient = Patient::factory()->create(['first_name' => 'Jordan']);

    Livewire::actingAs(receptionist())->test(PatientForm::class, ['patient' => $patient])
        ->assertSet('first_name', 'Jordan')
        ->set('first_name', 'Jordy')
        ->call('save')
        ->assertHasNoErrors();

    expect($patient->refresh()->first_name)->toBe('Jordy');
});

test('patients can be searched by number, name, phone and date of birth', function () {
    $receptionist = receptionist();
    $match = Patient::factory()->create(['first_name' => 'Morgan', 'last_name' => 'Quill', 'phone' => '+15550199', 'date_of_birth' => '1977-07-07']);
    Patient::factory()->count(3)->create(['first_name' => 'Other', 'last_name' => 'Person', 'phone' => '+15550000', 'date_of_birth' => '2001-01-01']);

    foreach ([$match->patient_number, 'morgan', 'QUILL', 'morgan quill', '0199', '1977-07-07'] as $term) {
        $ids = Livewire::actingAs($receptionist)->test(PatientIndex::class)
            ->set('search', $term)
            ->instance()->patients->pluck('id')->all();

        expect($ids)->toBe([$match->id], "search: {$term}");
    }
});

test('search treats wildcard characters literally', function () {
    Patient::factory()->count(2)->create();

    $count = Livewire::actingAs(receptionist())->test(PatientIndex::class)
        ->set('search', '%')
        ->instance()->patients->total();

    expect($count)->toBe(0);
});

test('inactive patients are hidden unless requested', function () {
    $receptionist = receptionist();
    Patient::factory()->create(['is_active' => false]);
    Patient::factory()->create();

    $component = Livewire::actingAs($receptionist)->test(PatientIndex::class);
    expect($component->instance()->patients->total())->toBe(1);

    $component->set('includeInactive', true);
    expect($component->instance()->patients->total())->toBe(2);
});

test('only receptionists can reach patient screens', function (Role $role) {
    $user = staffWithRole($role);
    $patient = Patient::factory()->create();

    $this->actingAs($user)->get(route('patients.index'))->assertForbidden();
    $this->actingAs($user)->get(route('patients.create'))->assertForbidden();
    $this->actingAs($user)->get(route('patients.edit', $patient))->assertForbidden();
})->with([Role::Admin, Role::Nurse, Role::Doctor, Role::ServiceStaff]);

test('receptionists can reach patient screens', function () {
    $user = receptionist();
    $patient = Patient::factory()->create();

    $this->actingAs($user)->get(route('patients.index'))->assertOk()->assertSee($patient->patient_number);
    $this->actingAs($user)->get(route('patients.create'))->assertOk();
    $this->actingAs($user)->get(route('patients.edit', $patient))->assertOk();
});

test('patient actions authorize the acting user themselves', function () {
    $doctor = staffWithRole(Role::Doctor);

    expect(fn () => app(RegisterPatient::class)->handle($doctor, patientInput()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdatePatientDemographics::class)->handle($doctor, Patient::factory()->create(), patientInput(['is_active' => true])))
        ->toThrow(AuthorizationException::class);
});

test('contact details are hidden when a patient is serialised', function () {
    $array = Patient::factory()->create()->toArray();

    expect($array)->not->toHaveKeys(['phone', 'email', 'address', 'emergency_contact_name', 'emergency_contact_phone']);
});
