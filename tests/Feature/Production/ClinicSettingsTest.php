<?php

use App\Enums\Role;
use App\Livewire\Admin\ClinicSettingsForm;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Services\ClinicSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function settingsInput(array $overrides = []): array
{
    return [...app(ClinicSettings::class)->current(), ...$overrides];
}

test('an administrator changes clinic settings, which apply immediately and are audited', function () {
    $admin = admin();

    Livewire::actingAs($admin)->test(ClinicSettingsForm::class)
        ->set('values.clinic__timezone', 'Asia/Manila')
        ->set('values.clinic__queue__max_recalls', 2)
        ->set('values.clinic__security__require_two_factor', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(config('clinic.timezone'))->toBe('Asia/Manila')
        ->and(config('clinic.queue.max_recalls'))->toBe(2)
        ->and(config('clinic.security.require_two_factor'))->toBeTrue()
        ->and(json_decode(DB::table('settings')->where('key', 'clinic.timezone')->value('value')))->toBe('Asia/Manila');

    $log = AuditLog::query()->where('action', 'settings.updated')->sole();
    expect($log->actor_id)->toBe($admin->id)
        ->and($log->metadata['changes']['clinic.queue.max_recalls'])->toEqual(['from' => 3, 'to' => 2])
        ->and($log->metadata['changes'])->not->toHaveKey('clinic.appointments.late_grace_minutes'); // unchanged values are not logged
});

test('stored settings override configuration on the next boot', function () {
    app(ClinicSettings::class)->update(admin(), settingsInput(['clinic.appointments.late_grace_minutes' => 25]));

    config(['clinic.appointments.late_grace_minutes' => 15]); // simulate a fresh boot reading config files
    ClinicSettings::forgetCache();
    app(ClinicSettings::class)->applyToConfig();

    expect(config('clinic.appointments.late_grace_minutes'))->toBe(25)
        ->and(Appointment::lateGraceMinutes())->toBe(25);
});

test('settings are validated', function (string $key, mixed $value) {
    $admin = admin();

    expectValidationError(fn () => app(ClinicSettings::class)->update($admin, settingsInput([$key => $value])), str_replace('.', '__', $key));
    expect(AuditLog::query()->where('action', 'settings.updated')->count())->toBe(0);
})->with([
    ['clinic.timezone', 'Mars/Base'],
    ['clinic.appointments.late_grace_minutes', -1],
    ['clinic.appointments.booking_horizon_days', 0],
    ['clinic.queue.max_recalls', 99],
]);

test('only administrators manage clinic settings', function (Role $role) {
    $user = staffWithRole($role);

    $this->actingAs($user)->get(route('clinic-settings'))->assertForbidden();
    expect(fn () => app(ClinicSettings::class)->update($user, settingsInput()))->toThrow(AuthorizationException::class);
})->with([Role::Receptionist, Role::Nurse, Role::Doctor, Role::ServiceStaff]);
