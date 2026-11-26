<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Authorization\SyncRolesAndPermissions;
use Illuminate\Database\Seeder;

final class RolesAndPermissionsSeeder extends Seeder
{
    public function run(SyncRolesAndPermissions $sync): void
    {
        $sync->handle();
    }
}
