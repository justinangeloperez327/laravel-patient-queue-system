<?php

namespace Database\Factories;

use App\Models\Counter;
use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Counter>
 */
class CounterFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'department_id' => Department::factory(),
            'code' => strtoupper(fake()->unique()->bothify('CTR-###??')),
            'name' => 'Room '.fake()->unique()->numberBetween(1, 9999),
            'location' => null,
            'is_active' => true,
        ];
    }
}
