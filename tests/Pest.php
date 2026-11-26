<?php

use App\Enums\Role;
use App\Models\Department;
use App\Models\StaffAssignment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests run against PostgreSQL (see phpunit.xml) so that database
| constraints, locking and timestamptz behaviour match production.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function staffWithRole(Role ...$roles): User
{
    return User::factory()->withRole(...$roles)->create();
}

function admin(): User
{
    return staffWithRole(Role::Admin);
}

function receptionist(): User
{
    return staffWithRole(Role::Receptionist);
}

/**
 * An active provider (doctor by default) assigned to the given departments.
 */
function providerIn(Department $department, Role $role = Role::Doctor): User
{
    $user = staffWithRole($role);
    StaffAssignment::factory()->create(['user_id' => $user->id, 'department_id' => $department->id]);

    return $user;
}

/**
 * Assert that a callback throws a validation error for the given field.
 */
function expectValidationError(callable $callback, string $field): void
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);

        return;
    }

    test()->fail("Expected a validation error for [{$field}].");
}

/**
 * Assert that a statement violates a database constraint. The statement runs
 * in a savepoint so the aborted statement does not poison the surrounding
 * test transaction (PostgreSQL aborts the whole transaction otherwise).
 */
function expectConstraintViolation(callable $statement, string $constraint): void
{
    try {
        DB::transaction(fn () => $statement());
    } catch (QueryException $exception) {
        expect((string) ($exception->errorInfo[0] ?? ''))->toStartWith('23')
            ->and($exception->getMessage())->toContain('"'.$constraint.'"');

        return;
    }

    test()->fail("Expected constraint [{$constraint}] to be violated.");
}
