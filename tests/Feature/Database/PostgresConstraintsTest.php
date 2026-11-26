<?php

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

test('the test suite runs on postgresql', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});

test('the database enforces case-insensitive unique emails', function () {
    User::factory()->create(['email' => 'staff@example.test']);

    // Bypasses application validation to prove the constraint itself.
    expect(fn () => DB::table('users')->insert([
        'name' => 'Duplicate',
        'email' => 'STAFF@example.test',
        'password' => 'x',
    ]))->toThrow(UniqueConstraintViolationException::class);
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
