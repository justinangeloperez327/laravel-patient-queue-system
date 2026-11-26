<?php

declare(strict_types=1);

namespace App\Actions\Clinical;

use App\Enums\ClinicalNoteStatus;
use App\Models\AuditLog;
use App\Models\ClinicalNote;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Signs the author's draft. After signing the note is immutable.
 */
final class SignClinicalNote
{
    public function handle(User $actor, ClinicalNote $note): ClinicalNote
    {
        Gate::forUser($actor)->authorize($note->note_type->signPermission()->value);

        return DB::transaction(function () use ($actor, $note): ClinicalNote {
            /** @var ClinicalNote $locked */
            $locked = ClinicalNote::query()->whereKey($note->id)->lockForUpdate()->firstOrFail();

            if ($locked->author_id !== $actor->id) {
                throw new AuthorizationException(__('Only the author can sign a note.'));
            }

            if ($locked->isSigned()) {
                throw ValidationException::withMessages(['note' => __('This note is already signed.')]);
            }

            $missing = array_values(array_filter(
                $locked->note_type->requiredForSigning(),
                fn (string $field): bool => blank($locked->getAttribute($field)),
            ));

            if ($missing !== []) {
                throw ValidationException::withMessages(array_fill_keys($missing, __('Required before signing.')));
            }

            $locked->status = ClinicalNoteStatus::Signed;
            $locked->signed_at = now();
            $locked->save();

            AuditLog::record($actor, 'clinical.note.signed', $locked, ['visit_id' => $locked->visit_id, 'note_type' => $locked->note_type->value]);

            return $locked;
        });
    }
}
