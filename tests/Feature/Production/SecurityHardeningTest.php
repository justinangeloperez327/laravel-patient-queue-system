<?php

use App\Actions\Patients\UpdatePatientDemographics;
use App\Enums\Role;
use App\Http\Middleware\RequireTwoFactor;
use App\Livewire\Admin\AuditLogIndex;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\User;
use Livewire\Livewire;

test('staff without two-factor are sent to set it up when the clinic requires it', function () {
    config(['clinic.security.require_two_factor' => true]);
    $user = staffWithRole(Role::Receptionist);

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('security.edit'));
    $this->actingAs($user)->get(route('patients.index'))->assertRedirect(route('security.edit'));
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get(route('security.edit'))->assertOk();
    $this->actingAs($user)->post(route('logout'))->assertRedirect();
});

test('staff with confirmed two-factor, or a clinic that does not require it, pass through', function () {
    config(['clinic.security.require_two_factor' => true]);
    $enrolled = User::factory()->withRole(Role::Receptionist)->withTwoFactor()->create();
    $this->actingAs($enrolled)->get(route('dashboard'))->assertOk();

    config(['clinic.security.require_two_factor' => false]);
    $this->actingAs(staffWithRole(Role::Receptionist))->get(route('dashboard'))->assertOk();
});

test('two-factor enforcement also guards Livewire update requests', function () {
    expect(Livewire::getPersistentMiddleware())->toContain(RequireTwoFactor::class);
});

test('responses carry security headers and authenticated pages are not cached', function () {
    $guest = $this->get(route('login'));
    $guest->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'same-origin');
    expect($guest->headers->get('Cache-Control'))->not->toContain('no-store')
        ->and($guest->headers->has('Strict-Transport-Security'))->toBeFalse();

    $authenticated = $this->actingAs(receptionist())->get(route('dashboard'))
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
    expect($authenticated->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');

    $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

test('opening and changing patient demographics is audited without the values', function () {
    $receptionist = receptionist();
    $patient = Patient::factory()->create(['last_name' => 'Auditname']);

    $this->actingAs($receptionist)->get(route('patients.show', $patient))->assertOk();
    $this->actingAs($receptionist)->get(route('patients.edit', $patient))->assertOk();
    app(UpdatePatientDemographics::class)->handle($receptionist, $patient, [
        'first_name' => $patient->first_name, 'last_name' => 'Changedname', 'date_of_birth' => $patient->date_of_birth->toDateString(), 'is_active' => true,
    ]);

    $logs = AuditLog::query()->where('subject_id', $patient->id)->orderBy('id')->get();

    expect($logs->pluck('action')->all())->toBe(['patient.viewed', 'patient.viewed', 'patient.updated'])
        ->and($logs->pluck('metadata.context')->filter()->values()->all())->toBe(['record', 'edit'])
        ->and($logs->last()->metadata['fields'])->toContain('last_name')
        ->and(json_encode($logs->pluck('metadata')))->not->toContain('Auditname')->not->toContain('Changedname');
});

test('administrators review the audit log; nobody else can', function (Role $role) {
    $this->actingAs(staffWithRole($role))->get(route('audit-log'))->assertForbidden();
})->with([Role::Receptionist, Role::Nurse, Role::Doctor, Role::ServiceStaff]);

test('the audit log viewer filters by action and outcome', function () {
    $admin = admin();
    AuditLog::record($admin, 'clinical.history.view', $admin, [], 'denied');
    AuditLog::record($admin, 'report.exported', $admin);

    $this->actingAs($admin)->get(route('audit-log'))->assertOk()->assertSee('clinical.history.view')->assertSee('report.exported');

    $component = Livewire::actingAs($admin)->test(AuditLogIndex::class)->set('outcome', 'denied');
    expect($component->instance()->logs->pluck('action')->all())->toBe(['clinical.history.view']);

    $component->set('outcome', '')->set('action', 'report');
    expect($component->instance()->logs->pluck('action')->all())->toBe(['report.exported']);
});
