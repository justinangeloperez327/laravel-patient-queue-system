<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\StaffAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffAssignment>
 */
class StaffAssignmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'department_id' => Department::factory(),
            'is_active' => true,
        ];
    }
}
