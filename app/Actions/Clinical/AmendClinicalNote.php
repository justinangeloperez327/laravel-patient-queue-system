<?php

declare(strict_types=1);

namespace App\Actions\Clinical;

use App\Models\AuditLog;
use App\Models\ClinicalNote;
use App\Models\ClinicalNoteAmendment;
use App\Models\Patient;
use App\Models\User;
use App\Services\Clinical\ClinicalAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Adds an attributed, signed amendment to a signed note. Any clinician
 * allowed to write that note type and in a care relationship with the
 * patient may amend; the original note never changes.
 */
final class AmendClinicalNote
{
    public function __construct(private readonly ClinicalAccess $access) {}

    public function handle(User $actor, ClinicalNote $note, string $amendment, string $reason): ClinicalNoteAmendment
    {
        Gate::forUser($actor)->authorize($note->note_type->writePermission()->value);

        /** @var Patient $patient */
        $patient = $note->visit->patient;

        if (! $this->access->hasCareRelationship($actor, $patient) && $note->author_id !== $actor->id) {
            AuditLog::record($actor, 'clinical.note.amend', $note, [], 'denied');

            throw new AuthorizationException(__('You do not have an active care relationship with this patient.'));
        }

        /** @var array{amendment: string, reason: string} $data */
        $data = Validator::make(['amendment' => trim($amendment), 'reason' => trim($reason)], [
            'amendment' => ['required', 'string', 'max:10000'],
            'reason' => ['required', 'string', 'max:255'],
        ])->validate();

        return DB::transaction(function () use ($actor, $note, $data): ClinicalNoteAmendment {
            /** @var ClinicalNote $locked */
            $locked = ClinicalNote::query()->whereKey($note->id)->sharedLock()->firstOrFail();

            if (! $locked->isSigned()) {
                throw ValidationException::withMessages(['note' => __('Drafts are edited directly; only signed notes are amended.')]);
            }

            $amendment = new ClinicalNoteAmendment;
            $amendment->clinical_note_id = $locked->id;
            $amendment->author_id = $actor->id;
            $amendment->amendment = $data['amendment'];
            $amendment->reason = $data['reason'];
            $amendment->signed_at = now();
            $amendment->save();

            AuditLog::record($actor, 'clinical.note.amended', $locked, ['amendment_id' => $amendment->id]);

            return $amendment;
        });
    }
}
