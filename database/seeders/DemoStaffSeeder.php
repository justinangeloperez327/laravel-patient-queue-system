<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Synthetic staff accounts for local development only (one per role).
 * Never run against an environment holding real data.
 */
final class DemoStaffSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Role::cases() as $role) {
            $user = User::query()->firstOrCreate(
                ['email' => str_replace('_', '-', $role->value).'@example.test'],
                ['name' => 'Demo '.$role->label(), 'password' => 'password'],
            );

            $user->syncRoles([$role->value]);
        }
    }
}
