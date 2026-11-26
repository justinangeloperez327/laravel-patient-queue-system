<?php

use App\Actions\Clinical\RecordVitalSigns;
use App\Actions\Clinical\SaveClinicalNote;
use App\Actions\Clinical\SignClinicalNote;
use App\Actions\Queues\CompleteTicket;
use App\Actions\Queues\TransferTicket;
use App\Enums\ClinicalNoteType;
use App\Enums\ClinicalPriority;
use App\Enums\Role;
use App\Livewire\Clinical\ConsultationEncounter;
use App\Livewire\Clinical\PatientRecord;
use App\Livewire\Clinical\TriageEncounter;
use App\Models\AuditLog;
use App\Models\ClinicalNote;
use App\Models\QueueTicket;
use App\Models\VitalSign;
use App\Services\Clinical\ClinicalAccess;
use Livewire\Livewire;

function deniedRecordViews(): int
{
    return AuditLog::query()->where('action', 'clinical.history.view')->where('outcome', 'denied')->count();
}

test('care relationships follow open visits and department tickets', function () {
    $fixture = clinicalFixture();
    $access = app(ClinicalAccess::class);
    $patient = $fixture['patient'];

    expect($access->hasCareRelationship($fixture['nurse'], $patient))->toBeTrue()   // triage ticket in NUR
        ->and($access->hasCareRelationship($fixture['doctor'], $patient))->toBeFalse() // no OPD ticket yet
        ->and($access->hasCareRelationship($fixture['receptionist'], $patient))->toBeFalse()
        ->and($access->hasCareRelationship($fixture['serviceStaff'], $patient))->toBeFalse();

    $consult = toConsultation($fixture);
    expect($access->hasCareRelationship($fixture['doctor'], $patient))->toBeTrue();

    act(CompleteTicket::class, $fixture['doctor'], $consult); // visit completes
    expect($access->hasCareRelationship($fixture['doctor'], $patient))->toBeFalse()
        ->and($access->hasCareRelationship($fixture['nurse'], $patient))->toBeFalse();
});

test('inactive clinicians lose access', function () {
    $fixture = clinicalFixture();
    $fixture['nurse']->forceFill(['is_active' => false])->save();

    expect(app(ClinicalAccess::class)->hasCareRelationship($fixture['nurse'], $fixture['patient']))->toBeFalse();
});

test('clinicians in a care relationship see history, and the read is audited', function () {
    $fixture = clinicalFixture();
    $note = app(SaveClinicalNote::class)->handle($fixture['nurse'], $fixture['triageTicket'], ClinicalNoteType::Triage, ['chief_complaint' => 'Persistent fever']);
    app(SignClinicalNote::class)->handle($fixture['nurse'], $note);

    $this->actingAs($fixture['nurse'])->get(route('patients.show', $fixture['patient']))
        ->assertOk()
        ->assertSee('Persistent fever');

    expect(AuditLog::query()->where('action', 'clinical.history.view')->where('outcome', 'success')->where('actor_id', $fixture['nurse']->id)->where('subject_id', $fixture['patient']->id)->count())->toBe(1);
});

test('reception sees demographics but no clinical content', function () {
    $fixture = clinicalFixture();
    $note = app(SaveClinicalNote::class)->handle($fixture['nurse'], $fixture['triageTicket'], ClinicalNoteType::Triage, ['chief_complaint' => 'Persistent fever']);
    app(SignClinicalNote::class)->handle($fixture['nurse'], $note);
    app(RecordVitalSigns::class)->handle($fixture['nurse'], $fixture['triageTicket'], ['heart_rate' => 111]);

    $this->actingAs($fixture['receptionist'])->get(route('patients.show', $fixture['patient']))
        ->assertOk()
        ->assertSee($fixture['patient']->patient_number)
        ->assertDontSee('Persistent fever')
        ->assertDontSee('Vital signs')
        ->assertSee('Clinical history is only visible');
});

test('everyone else is refused the record, and each refusal is audited', function () {
    $fixture = clinicalFixture();
    $outsider = providerIn($fixture['departments']['lab'], Role::Nurse);

    foreach ([$fixture['serviceStaff'], admin(), $fixture['doctor'], $outsider] as $user) {
        $before = deniedRecordViews();
        $this->actingAs($user)->get(route('patients.show', $fixture['patient']))->assertForbidden();
        expect(deniedRecordViews())->toBe($before + 1);
    }
});

test('the encounter pages are limited to clinicians and only show the patient being served', function () {
    $fixture = clinicalFixture();

    $this->actingAs($fixture['receptionist'])->get(route('workspace.triage'))->assertForbidden();
    $this->actingAs($fixture['receptionist'])->get(route('workspace.consultation'))->assertForbidden();
    $this->actingAs($fixture['serviceStaff'])->get(route('workspace.triage'))->assertForbidden();
    $this->actingAs($fixture['serviceStaff'])->get(route('workspace.consultation'))->assertForbidden();
    $this->actingAs($fixture['doctor'])->get(route('workspace.triage'))->assertForbidden();

    $this->actingAs($fixture['doctor'])->get(route('workspace.consultation'))->assertOk()->assertSee('You are not serving a patient')->assertDontSee('Synthetic');
    $this->actingAs($fixture['nurse'])->get(route('workspace.triage'))->assertOk()->assertSee('Synthetic')->assertSee($fixture['triageTicket']->ticket_number);

    expect(AuditLog::query()->where('action', 'clinical.history.view')->where('actor_id', $fixture['nurse']->id)->value('metadata'))->toEqual(['context' => 'encounter']);
});

test('triage records vitals and an assessment, then routes on with clinical priority', function () {
    $fixture = clinicalFixture();

    Livewire::actingAs($fixture['nurse'])->test(TriageEncounter::class)
        ->set('vitals', ['temperature_celsius' => '39.1', 'heart_rate' => '104'])
        ->call('recordVitals')->assertHasNoErrors()
        ->set('note', ['chief_complaint' => 'Fever', 'additional_notes' => 'Flushed'])
        ->call('signNote')->assertHasNoErrors()
        ->set('priority', ClinicalPriority::High->value)
        ->call('sendOn')->assertHasErrors(['priority_reason'])
        ->set('priorityReason', 'Febrile and tachycardic')
        ->call('sendOn')->assertHasNoErrors()->assertRedirect(route('workspace'));

    $next = QueueTicket::query()->where('transferred_from_id', $fixture['triageTicket']->id)->sole();

    expect($next->ticket_number)->toBe('C-001')
        ->and($next->priority)->toBe(ClinicalPriority::High)
        ->and($next->priority_reason)->toBe('Febrile and tachycardic')
        ->and($next->events()->where('event_type', 'priority_changed')->sole()->user_id)->toBe($fixture['nurse']->id);
});

test('the consultation page saves and signs a SOAP note', function () {
    $fixture = clinicalFixture();
    toConsultation($fixture);

    Livewire::actingAs($fixture['doctor'])->test(ConsultationEncounter::class)
        ->set('note', ['chief_complaint' => 'Cough'])
        ->call('saveNote')->assertHasNoErrors()
        ->call('signNote')->assertHasErrors(['assessment', 'plan'])
        ->set('note', ['chief_complaint' => 'Cough', 'assessment' => 'Bronchitis', 'plan' => 'Review in 1 week'])
        ->call('signNote')->assertHasNoErrors();

    expect(ClinicalNote::query()->where('note_type', 'soap')->sole()->isSigned())->toBeTrue();
});

test('the encounter stops showing a patient once they leave the clinician', function () {
    $fixture = clinicalFixture();
    $component = Livewire::actingAs($fixture['nurse'])->test(TriageEncounter::class)->assertSee('Synthetic');

    app(TransferTicket::class)->handle($fixture['nurse'], $fixture['triageTicket'], $fixture['services']['c']->id);

    $component->call('$refresh')->assertSee('You are not serving a patient');
    $component->set('vitals', ['heart_rate' => '80'])->call('recordVitals')->assertStatus(409);
    expect(VitalSign::query()->count())->toBe(0);
});

test('the patient record amends a signed note', function () {
    $fixture = clinicalFixture();
    $note = app(SignClinicalNote::class)->handle($fixture['nurse'], app(SaveClinicalNote::class)->handle($fixture['nurse'], $fixture['triageTicket'], ClinicalNoteType::Triage, ['chief_complaint' => 'Fever']));

    Livewire::actingAs($fixture['nurse'])->test(PatientRecord::class, ['patient' => $fixture['patient']])
        ->call('startAmending', $note->id)
        ->set('amendment', 'Allergy to penicillin noted')
        ->set('amendmentReason', 'Omitted')
        ->call('amend')->assertHasNoErrors()
        ->assertSee('Allergy to penicillin noted');
});
