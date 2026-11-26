<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Models\StaffAssignment;
use App\Models\User;

trait SyncsStaffAssignments
{
    /**
     * Activate the given department assignments and deactivate the rest.
     * Assignment rows are kept (not deleted) to preserve history.
     *
     * @param  list<int>  $departmentIds
     */
    protected function syncAssignments(User $user, array $departmentIds): void
    {
        StaffAssignment::query()
            ->where('user_id', $user->id)
            ->whereNotIn('department_id', $departmentIds)
            ->where('is_active', true)
            ->update(['is_active' => false, 'updated_at' => now()]);

        foreach ($departmentIds as $departmentId) {
            StaffAssignment::query()->updateOrCreate(
                ['user_id' => $user->id, 'department_id' => $departmentId],
                ['is_active' => true],
            );
        }
    }
}
