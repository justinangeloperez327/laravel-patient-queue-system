<?php

use App\Enums\Role;
use App\Models\Patient;
use App\Models\User;
use App\Support\Readiness\PreflightChecks;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function productionConfig(): void
{
    config([
        'app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://clinic.example',
        'session.secure' => true, 'session.encrypt' => true, 'session.lifetime' => 30,
        'queue.default' => 'redis', 'cache.default' => 'redis', 'session.driver' => 'redis',
        'mail.default' => 'smtp', 'clinic.security.require_two_factor' => true,
        'broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'k', 'broadcasting.connections.reverb.secret' => 's',
        'database.connections.pgsql.sslmode' => 'require',
    ]);
}

function checkStatus(array $results, string $check): string
{
    return collect($results)->firstWhere('check', $check)['status'];
}

test('a production-like setup has no blocking issues but still lists manual sign-offs', function () {
    productionConfig();
    User::factory()->withRole(Role::Admin)->withTwoFactor()->create(['email' => 'admin@clinic.example']);

    $results = app(PreflightChecks::class)->run();
    $blocking = collect($results)->where('status', 'fail')->pluck('check')->all();

    expect($blocking)->toBe([])
        ->and(collect($results)->where('status', 'manual')->count())->toBe(5);

    $this->artisan('clinic:preflight')->assertSuccessful();
});

test('unsafe configuration and data are blocking', function () {
    productionConfig();
    config(['app.debug' => true, 'session.secure' => false, 'clinic.security.require_two_factor' => false]);
    User::factory()->withRole(Role::Admin)->create(['email' => 'admin@example.test']); // demo address, no 2FA

    $results = app(PreflightChecks::class)->run();

    foreach (['Debug mode off', 'Secure session cookies', 'Two-factor authentication required', 'No demo accounts', 'Administrators use two-factor'] as $check) {
        expect(checkStatus($results, $check))->toBe('fail', $check);
    }

    $this->artisan('clinic:preflight')->assertFailed();
});

test('a missing immutability trigger is blocking', function () {
    productionConfig();
    User::factory()->withRole(Role::Admin)->withTwoFactor()->create(['email' => 'admin@clinic.example']);
    DB::statement('drop trigger vital_signs_no_update_or_delete on vital_signs');

    $result = collect(app(PreflightChecks::class)->run())->firstWhere('check', 'Immutability triggers installed');

    expect($result['status'])->toBe('fail')->and($result['detail'])->toContain('vital_signs_no_update_or_delete');
});

test('the preflight JSON output is machine-readable', function () {
    $this->artisan('clinic:preflight', ['--json' => true])->expectsOutputToContain('"checks"')->assertFailed(); // test env is not production
});

test('the health command reports components as JSON without patient data', function () {
    Patient::factory()->create(['last_name' => 'Healthname']);

    $exitCode = Artisan::call('clinic:health');
    $report = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($report['ok'])->toBeTrue()
        ->and($report['checks'])->toMatchArray(['database' => 'up', 'redis' => 'up', 'failed_jobs' => 0])
        ->and(array_keys($report['checks']))->toBe(['database', 'redis', 'queue_backlog', 'failed_jobs', 'websocket'])
        ->and(json_encode($report))->not->toContain('Healthname');
});

test('every database safeguard the application relies on is installed', function () {
    $triggers = collect(DB::select('select tgname from pg_trigger where not tgisinternal'))->pluck('tgname')->all();
    $constraints = collect(DB::select('select conname from pg_constraint'))->pluck('conname')
        ->merge(collect(DB::select("select indexname from pg_indexes where schemaname = 'public'"))->pluck('indexname'))->all();

    expect($triggers)->toContain(...PreflightChecks::REQUIRED_TRIGGERS);

    foreach ([
        'users_email_lower_unique', 'departments_code_format', 'services_triage_target_matches_mode', 'counter_services_service_id_department_id_foreign',
        'provider_schedules_no_overlap', 'patients_number_format', 'appointments_provider_no_overlap', 'appointments_patient_no_overlap',
        'visits_one_open_per_patient', 'visits_appointment_id_patient_id_foreign', 'queue_tickets_one_unresolved_per_visit_service',
        'queue_tickets_one_active_per_counter', 'queue_events_idempotency_key_unique', 'counter_sessions_one_open_per_counter',
        'clinical_notes_queue_ticket_id_visit_id_foreign', 'vital_signs_temperature_range', 'display_devices_credential_hash_format',
    ] as $name) {
        expect($constraints)->toContain($name);
    }

    expect(DB::selectOne("select count(*) as n from pg_extension where extname = 'btree_gist'")->n)->toBe(1);
});
