<?php

declare(strict_types=1);

namespace App\Actions\Clinical;

use App\Enums\ClinicalNoteStatus;
use App\Enums\ClinicalNoteType;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\ClinicalNote;
use App\Models\QueueTicket;
use App\Models\User;
use App\Services\Clinical\ClinicalAccess;
use App\Support\ConstraintViolation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates the actor's draft note of a type for the ticket being
 * served. A draft can be edited only by its author; signed notes cannot be
 * edited at all (application rule and database trigger).
 */
final class SaveClinicalNote
{
    public function __construct(private readonly ClinicalAccess $access) {}

    /**
     * @param  array<string, mixed>  $input  chief_complaint, subjective, objective, assessment, plan, additional_notes
     */
    public function handle(User $actor, QueueTicket $ticket, ClinicalNoteType $type, array $input): ClinicalNote
    {
        Gate::forUser($actor)->authorize($type->writePermission()->value);

        if ($type === ClinicalNoteType::Triage) {
            Gate::forUser($actor)->authorize(Permission::PerformTriage->value);
        }

        $fields = ['chief_complaint', 'subjective', 'objective', 'assessment', 'plan', 'additional_notes'];
        $input = array_intersect_key($input, array_flip($fields));
        $input = array_map(fn (mixed $value): mixed => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, $input);

        /** @var array<string, string|null> $data */
        $data = Validator::make($input, array_fill_keys($fields, ['nullable', 'string', 'max:10000']))->validate();

        return ConstraintViolation::guard(fn (): ClinicalNote => DB::transaction(function () use ($actor, $ticket, $type, $data): ClinicalNote {
            $locked = QueueTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            /** @var ClinicalNote|null $note */
            $note = ClinicalNote::query()
                ->where('queue_ticket_id', $locked->id)
                ->where('author_id', $actor->id)
                ->where('note_type', $type->value)
                ->where('status', ClinicalNoteStatus::Draft->value)
                ->lockForUpdate()
                ->first();

            if ($note === null) {
                // Starting a note requires serving the patient now; an existing draft can be finished later.
                $this->access->ensureServing($actor, $locked);

                $note = new ClinicalNote;
                $note->visit_id = $locked->visit_id;
                $note->queue_ticket_id = $locked->id;
                $note->author_id = $actor->id;
                $note->note_type = $type;
            }

            $note->fill($data)->save();

            AuditLog::record($actor, 'clinical.note.saved', $note, ['visit_id' => $note->visit_id, 'note_type' => $type->value]);

            return $note;
        }), [
            'clinical_notes_one_draft' => ['note', __('You already have a draft for this patient. Reload to continue it.')],
        ]);
    }

    /**
     * Edit a specific draft (e.g. from the patient record after service).
     *
     * @param  array<string, mixed>  $input
     */
    public function update(User $actor, ClinicalNote $note, array $input): ClinicalNote
    {
        if ($note->author_id !== $actor->id) {
            throw new AuthorizationException(__('Only the author can edit a draft note.'));
        }

        if ($note->isSigned()) {
            throw ValidationException::withMessages(['note' => __('Signed notes cannot be edited. Add an amendment instead.')]);
        }

        return $this->handle($actor, $note->ticket, $note->note_type, $input);
    }
}
