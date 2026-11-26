<?php

declare(strict_types=1);

namespace App\Actions\Patients;

use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Registers a patient and assigns the next PT-###### number from a
 * PostgreSQL sequence (atomic, never reused, unique-constrained).
 */
final class RegisterPatient
{
    use PatientValidationRules;

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, array $input): Patient
    {
        Gate::forUser($actor)->authorize('create', Patient::class);

        $confirmDuplicate = filter_var($input['confirm_duplicate'] ?? false, FILTER_VALIDATE_BOOL);
        unset($input['confirm_duplicate']);

        /** @var array<string, mixed> $data */
        $data = Validator::make(
            $this->normalizeDemographics($input),
            $this->demographicsRules(),
            $this->demographicsMessages(),
        )->validate();

        if (! $confirmDuplicate) {
            $this->guardAgainstDuplicates($data);
        }

        return DB::transaction(function () use ($actor, $data): Patient {
            $patient = new Patient($data);
            $patient->patient_number = sprintf('PT-%06d', (int) DB::scalar("select nextval('patient_number_seq')"));
            $patient->registered_by = $actor->id;
            $patient->save();

            AuditLog::record($actor, 'patient.registered', $patient);

            return $patient;
        });
    }

    /**
     * Ask the receptionist to confirm when a patient with the same name and
     * date of birth already exists.
     *
     * @param  array<string, mixed>  $data
     */
    private function guardAgainstDuplicates(array $data): void
    {
        $matches = Patient::query()
            ->whereRaw('lower(first_name) = ?', [mb_strtolower((string) $data['first_name'])])
            ->whereRaw('lower(last_name) = ?', [mb_strtolower((string) $data['last_name'])])
            ->whereDate('date_of_birth', (string) $data['date_of_birth'])
            ->orderBy('patient_number')
            ->limit(5)
            ->pluck('patient_number');

        if ($matches->isNotEmpty()) {
            throw ValidationException::withMessages([
                'duplicate' => __('A patient with the same name and date of birth already exists (:numbers). Check the existing record, or confirm to register a new patient.', [
                    'numbers' => $matches->implode(', '),
                ]),
            ]);
        }
    }
}
