<?php

declare(strict_types=1);

namespace App\Actions\Patients;

use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Updates demographics and the active flag. The patient number and
 * registering user are immutable.
 */
final class UpdatePatientDemographics
{
    use PatientValidationRules;

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, Patient $patient, array $input): Patient
    {
        Gate::forUser($actor)->authorize('update', $patient);

        /** @var array<string, mixed> $data */
        $data = Validator::make(
            $this->normalizeDemographics($input),
            [...$this->demographicsRules(), 'is_active' => ['required', 'boolean']],
            $this->demographicsMessages(),
        )->validate();

        return DB::transaction(function () use ($actor, $patient, $data): Patient {
            Patient::query()->whereKey($patient->id)->lockForUpdate()->first();

            $patient->fill($data);
            $patient->is_active = (bool) $data['is_active'];
            // Field names only: values are personal data and stay out of the audit log.
            $changed = array_keys($patient->getDirty());
            $patient->save();

            AuditLog::record($actor, 'patient.updated', $patient, ['fields' => $changed]);

            return $patient;
        });
    }
}
