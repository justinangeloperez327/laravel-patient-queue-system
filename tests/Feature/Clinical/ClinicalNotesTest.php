<?php

use App\Actions\Clinical\AmendClinicalNote;
use App\Actions\Clinical\SaveClinicalNote;
use App\Actions\Clinical\SignClinicalNote;
use App\Actions\Queues\TransferTicket;
use App\Enums\ClinicalNoteType;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\ClinicalNote;
use App\Models\ClinicalNoteAmendment;
use App\Models\QueueTicket;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function saveNote(User $user, QueueTicket $ticket, ClinicalNoteType $type, array $input): ClinicalNote
{
    return app(SaveClinicalNote::class)->handle($user, $ticket, $type, $input);
}

test('a nurse drafts, updates and signs a triage assessment', function () {
    $fixture = clinicalFixture();
    $draft = saveNote($fixture['nurse'], $fixture['triageTicket'], ClinicalNoteType::Triage, ['additional_notes' => 'Alert']);

    expectValidationError(fn () => app(SignClinicalNote::class)->handle($fixture['nurse'], $draft), 'chief_complaint');

    $same = saveNote($fixture['nurse'], $fixture['triageTicket'], ClinicalNoteType::Triage, ['chief_complaint' => 'Fever', 'additional_notes' => 'Alert']);
    $signed = app(SignClinicalNote::class)->handle($fixture['nurse'], $same);

    expect($same->id)->toBe($draft->id)
        ->and($signed->isSigned())->toBeTrue()
        ->and($signed->signed_at)->not->toBeNull()
        ->and(ClinicalNote::query()->count())->toBe(1);
});

test('a doctor\'s SOAP note needs complaint, assessment and plan before signing', function () {
    $fixture = clinicalFixture();
    $ticket = toConsultation($fixture);
    $draft = saveNote($fixture['doctor'], $ticket, ClinicalNoteType::Soap, ['chief_complaint' => 'Cough']);

    try {
        app(SignClinicalNote::class)->handle($fixture['doctor'], $draft);
        $this->fail('Expected validation errors.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['assessment', 'plan']);
    }

    $draft = saveNote($fixture['doctor'], $ticket, ClinicalNoteType::Soap, ['chief_complaint' => 'Cough', 'assessment' => 'URTI', 'plan' => 'Fluids']);
    expect(app(SignClinicalNote::class)->handle($fixture['doctor'], $draft)->isSigned())->toBeTrue();
});

test('signed notes are immutable in the application and the database', function () {
    $fixture = clinicalFixture();
    $note = app(SignClinicalNote::class)->handle($fixture['nurse'], saveNote($fixture['nurse'], $fixture['triageTicket'], ClinicalNoteType::Triage, ['chief_complaint' => 'Fever']));

    // A new save starts a fresh draft rather than touching the signed note.
    $fresh = saveNote($fixture['nurse'], $fixture['triageTicket'], ClinicalNoteType::Triage, ['chief_complaint' => 'Changed']);
    expect($fresh->id)->not->toBe($note->id)
        ->and($note->refresh()->chief_complaint)->toBe('Fever');

    expectValidationError(fn () => app(SaveClinicalNote::class)->update($fixture['nurse'], $note, ['chief_complaint' => 'Changed']), 'note');
    expectValidationError(fn () => app(SignClinicalNote::class)->handle($fixture['nurse'], $note), 'note');

    expect(fn () => DB::transaction(fn () => DB::table('clinical_notes')->where('id', $note->id)->update(['chief_complaint' => 'Tampered'])))->toThrow(QueryException::class, 'immutable')
        ->and(fn () => DB::transaction(fn () => DB::table('clinical_notes')->where('id', $note->id)->update(['status' => 'draft', 'signed_at' => null])))->toThrow(QueryException::class, 'immutable')
        ->and(fn () => DB::transaction(fn () => DB::table('clinical_notes')->where('id', $note->id)->delete()))->toThrow(QueryException::class, 'immutable');

    // Drafts remain editable at the database level.
    expect(DB::table('clinical_notes')->where('id', $fresh->id)->update(['chief_complaint' => 'Edited draft']))->toBe(1);
});

test('only the author edits or signs a draft', function () {
    $fixture = clinicalFixture();
    $ticket = toConsultation($fixture);
    $draft = saveNote($fixture['doctor'], $ticket, ClinicalNoteType::Soap, ['chief_complaint' => 'Cough', 'assessment' => 'URTI', 'plan' => 'Fluids']);
    $colleague = providerIn($fixture['departments']['opd']);

    expect(fn () => app(SaveClinicalNote::class)->update($colleague, $draft, ['plan' => 'Other']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SignClinicalNote::class)->handle($colleague, $draft))->toThrow(AuthorizationException::class);
});

test('note types follow role permissions', function () {
    $fixture = clinicalFixture();
    $consult = toConsultation($fixture);

    expect(fn () => saveNote($fixture['doctor'], $consult, ClinicalNoteType::Triage, ['chief_complaint' => 'x']))->toThrow(AuthorizationException::class)
        ->and(fn () => saveNote($fixture['nurse'], $consult, ClinicalNoteType::Soap, ['chief_complaint' => 'x']))->toThrow(AuthorizationException::class)
        ->and(fn () => saveNote($fixture['receptionist'], $consult, ClinicalNoteType::Nursing, ['additional_notes' => 'x']))->toThrow(AuthorizationException::class)
        ->and(fn () => saveNote($fixture['serviceStaff'], $consult, ClinicalNoteType::Nursing, ['additional_notes' => 'x']))->toThrow(AuthorizationException::class);

    $nursing = saveNote($fixture['doctor'], $consult, ClinicalNoteType::Soap, ['chief_complaint' => 'ok']);
    expect($nursing->note_type)->toBe(ClinicalNoteType::Soap);
});

test('a note can only be started for the patient being served', function () {
    $fixture = clinicalFixture();
    $other = walkInTicket($fixture, $fixture['services']['t']);

    expectValidationError(fn () => saveNote($fixture['nurse'], $other, ClinicalNoteType::Triage, ['chief_complaint' => 'x']), 'ticket');
    expectValidationError(fn () => saveNote($fixture['doctor'], $fixture['triageTicket'], ClinicalNoteType::Soap, ['chief_complaint' => 'x']), 'ticket');
});

test('an existing draft can be finished after the patient moves on', function () {
    $fixture = clinicalFixture();
    $draft = saveNote($fixture['nurse'], $fixture['triageTicket'], ClinicalNoteType::Triage, ['additional_notes' => 'Alert']);
    app(TransferTicket::class)->handle($fixture['nurse'], $fixture['triageTicket'], $fixture['services']['c']->id);

    $updated = app(SaveClinicalNote::class)->update($fixture['nurse'], $draft, ['chief_complaint' => 'Fever', 'additional_notes' => 'Alert']);

    expect(app(SignClinicalNote::class)->handle($fixture['nurse'], $updated)->isSigned())->toBeTrue();
});

test('notes must reference a ticket of the same visit', function () {
    $fixture = clinicalFixture();
    $other = walkInTicket($fixture);

    expectConstraintViolation(fn () => DB::table('clinical_notes')->insert([
        'visit_id' => $fixture['triageTicket']->visit_id, 'queue_ticket_id' => $other->id, 'author_id' => $fixture['nurse']->id, 'note_type' => 'triage',
    ]), 'clinical_notes_queue_ticket_id_visit_id_foreign');
    expectConstraintViolation(fn () => DB::table('clinical_notes')->insert([
        'visit_id' => $fixture['triageTicket']->visit_id, 'queue_ticket_id' => $fixture['triageTicket']->id, 'author_id' => $fixture['nurse']->id, 'note_type' => 'triage', 'status' => 'signed',
    ]), 'clinical_notes_signed_at_consistent');
});

test('amendments are attributed, signed, append-only and only for signed notes', function () {
    $fixture = clinicalFixture();
    $draft = saveNote($fixture['nurse'], $fixture['triageTicket'], ClinicalNoteType::Triage, ['chief_complaint' => 'Fever']);

    expectValidationError(fn () => app(AmendClinicalNote::class)->handle($fixture['nurse'], $draft, 'x', 'y'), 'note');
    expect(fn () => DB::transaction(fn () => DB::table('clinical_note_amendments')->insert([
        'clinical_note_id' => $draft->id, 'author_id' => $fixture['nurse']->id, 'amendment' => 'x', 'reason' => 'y', 'signed_at' => now(),
    ])))->toThrow(QueryException::class, 'only signed');

    $signed = app(SignClinicalNote::class)->handle($fixture['nurse'], $draft);
    expectValidationError(fn () => app(AmendClinicalNote::class)->handle($fixture['nurse'], $signed, 'More detail', ''), 'reason');

    $amendment = app(AmendClinicalNote::class)->handle($fixture['nurse'], $signed, 'Temperature recheck 37.9 °C', 'Late result');

    expect($amendment->author_id)->toBe($fixture['nurse']->id)
        ->and($amendment->signed_at)->not->toBeNull()
        ->and($signed->refresh()->chief_complaint)->toBe('Fever')
        ->and(fn () => DB::transaction(fn () => DB::table('clinical_note_amendments')->where('id', $amendment->id)->update(['amendment' => 'Tampered'])))
        ->toThrow(QueryException::class, 'append-only');
});

test('amending requires the note type permission and a care relationship', function () {
    $fixture = clinicalFixture();
    $signed = app(SignClinicalNote::class)->handle($fixture['nurse'], saveNote($fixture['nurse'], $fixture['triageTicket'], ClinicalNoteType::Triage, ['chief_complaint' => 'Fever']));
    $outsider = providerIn($fixture['departments']['lab'], Role::Nurse);

    expect(fn () => app(AmendClinicalNote::class)->handle($fixture['doctor'], $signed, 'x', 'y'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(AmendClinicalNote::class)->handle($fixture['receptionist'], $signed, 'x', 'y'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(AmendClinicalNote::class)->handle($outsider, $signed, 'x', 'y'))->toThrow(AuthorizationException::class)
        ->and(ClinicalNoteAmendment::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'clinical.note.amend')->where('outcome', 'denied')->count())->toBe(1);
});

test('audit entries never contain clinical text', function () {
    $fixture = clinicalFixture();
    $note = saveNote($fixture['nurse'], $fixture['triageTicket'], ClinicalNoteType::Triage, ['chief_complaint' => 'Very specific complaint text']);
    app(SignClinicalNote::class)->handle($fixture['nurse'], $note);

    expect(AuditLog::query()->get()->map(fn ($log) => json_encode($log->metadata))->implode(' '))
        ->not->toContain('Very specific complaint text')
        ->not->toContain('Synthetic');
});
