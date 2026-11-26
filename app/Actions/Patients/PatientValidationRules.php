<?php

declare(strict_types=1);

namespace App\Actions\Patients;

use App\Enums\Sex;
use App\Support\ClinicTime;
use Illuminate\Validation\Rule;

trait PatientValidationRules
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function demographicsRules(): array
    {
        $phone = ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()\-]{5,30}$/'];

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.ClinicTime::today()->toDateString()],
            'sex' => ['nullable', Rule::enum(Sex::class)],
            'phone' => $phone,
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:1000'],
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergency_contact_phone' => $phone,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function demographicsMessages(): array
    {
        return [
            'phone.regex' => __('Enter a valid phone number.'),
            'emergency_contact_phone.regex' => __('Enter a valid phone number.'),
            'date_of_birth.before_or_equal' => __('The date of birth cannot be in the future.'),
        ];
    }

    /**
     * Trim strings and turn blanks into nulls.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function normalizeDemographics(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_string($value)) {
                $value = trim($value);
                $input[$key] = $value === '' ? null : $value;
            }
        }

        if (is_string($input['email'] ?? null)) {
            $input['email'] = mb_strtolower($input['email']);
        }

        return $input;
    }
}
