<?php

use App\Models\Department;
use App\Models\Service;
use App\Models\User;
use App\Support\ConstraintViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('the test suite runs on postgresql', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});

test('the database enforces case-insensitive unique emails', function () {
    User::factory()->create(['email' => 'staff@example.test']);

    // Bypasses application validation to prove the constraint itself.
    expectConstraintViolation(fn () => DB::table('users')->insert([
        'name' => 'Duplicate',
        'email' => 'STAFF@example.test',
        'password' => 'x',
    ]), 'users_email_lower_unique');
});

test('timestamps are stored as timestamptz in utc', function () {
    $types = collect(DB::select(
        "select column_name, data_type from information_schema.columns where table_name = 'users' and column_name in ('created_at', 'updated_at', 'email_verified_at', 'two_factor_confirmed_at')"
    ))->pluck('data_type')->unique()->values()->all();

    expect($types)->toBe(['timestamp with time zone'])
        ->and(DB::selectOne('show timezone')->TimeZone)->toBe('UTC');
});

test('new users are active by default at the database level', function () {
    DB::table('users')->insert(['name' => 'Default', 'email' => 'default@example.test', 'password' => 'x']);

    expect(DB::table('users')->where('email', 'default@example.test')->value('is_active'))->toBeTrue();
});

test('patient records enforce number format, sex values and a plausible birth date', function () {
    $row = ['first_name' => 'A', 'last_name' => 'B', 'registered_by' => User::factory()->create()->id];

    expectConstraintViolation(fn () => DB::table('patients')->insert([...$row, 'patient_number' => 'P-1', 'date_of_birth' => '1990-01-01']), 'patients_number_format');
    expectConstraintViolation(fn () => DB::table('patients')->insert([...$row, 'patient_number' => 'PT-000001', 'date_of_birth' => '1990-01-01', 'sex' => 'x']), 'patients_sex_valid');
    expectConstraintViolation(fn () => DB::table('patients')->insert([...$row, 'patient_number' => 'PT-000001', 'date_of_birth' => '1800-01-01']), 'patients_date_of_birth_plausible');

    DB::table('patients')->insert([...$row, 'patient_number' => 'PT-000001', 'date_of_birth' => '1990-01-01']);
    expectConstraintViolation(fn () => DB::table('patients')->insert([...$row, 'patient_number' => 'PT-000001', 'date_of_birth' => '1990-01-01']), 'patients_patient_number_unique');
});

test('historical master data cannot be deleted while referenced', function () {
    $service = Service::factory()->create();

    expectConstraintViolation(fn () => DB::table('departments')->where('id', $service->department_id)->delete(), 'services_department_id_foreign');
});

test('constraint violations map onto form fields', function () {
    Department::factory()->create(['code' => 'DUP']);

    expectValidationError(fn () => ConstraintViolation::guard(
        fn () => DB::transaction(fn () => DB::table('departments')->insert(['code' => 'DUP', 'name' => 'Another'])),
        ['departments_code_unique' => ['code', 'taken']],
    ), 'code');

    // Unmapped constraints are rethrown untouched.
    expect(fn () => ConstraintViolation::guard(
        fn () => DB::transaction(fn () => DB::table('departments')->insert(['code' => 'DUP', 'name' => 'Third'])),
        ['something_else' => ['code', 'x']],
    ))->toThrow(QueryException::class);
});
