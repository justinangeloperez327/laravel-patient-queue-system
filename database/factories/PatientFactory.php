<?php

namespace Database\Factories;

use App\Enums\Sex;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * Synthetic patients only. Never seed real patient data.
 *
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_number' => fn () => sprintf('PT-%06d', (int) DB::scalar("select nextval('patient_number_seq')")),
            'first_name' => fake()->firstName(),
            'middle_name' => null,
            'last_name' => fake()->lastName(),
            'date_of_birth' => fake()->dateTimeBetween('-90 years', '-1 year')->format('Y-m-d'),
            'sex' => fake()->randomElement(Sex::cases()),
            'phone' => fake()->numerify('+1555#######'),
            'email' => fake()->unique()->safeEmail(),
            'nationality' => null,
            'address' => null,
            'emergency_contact_name' => null,
            'emergency_contact_phone' => null,
            'registered_by' => User::factory(),
            'is_active' => true,
        ];
    }
}
