<?php

declare(strict_types=1);

namespace App\Services\Clinical;

use App\Enums\Permission;
use App\Enums\QueueTicketStatus;
use App\Enums\VisitStatus;
use App\Models\AuditLog;
use App\Models\CounterSession;
use App\Models\Patient;
use App\Models\QueueTicket;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Clinical access rules (docs/ROLES_AND_PERMISSIONS.md, SECURITY.md):
 *
 * - Writing (vitals, notes) is tied to the encounter: the ticket must be
 *   being served by the actor at the counter they have open.
 * - Reading history needs clinical.history.view AND an active care
 *   relationship: the patient has an open visit with a ticket in one of the
 *   clinician's departments, or the clinician is serving / has served a
 *   ticket of that open visit. Deny by default; every read and every denial
 *   is audited. No emergency ("break-glass") access is implemented until the
 *   clinic approves a policy.
 */
final class ClinicalAccess
{
    /**
     * The ticket the user is serving at their open counter, if any.
     */
    public function servingTicket(User $user): ?QueueTicket
    {
        $session = CounterSession::query()->open()->where('user_id', $user->id)->first();

        if ($session === null) {
            return null;
        }

        return QueueTicket::query()
            ->where('counter_id', $session->counter_id)
            ->where('status', QueueTicketStatus::Serving->value)
            ->where('served_by', $user->id)
            ->first();
    }

    public function ensureServing(User $user, QueueTicket $ticket): void
    {
        if ($this->servingTicket($user)?->id !== $ticket->id) {
            throw ValidationException::withMessages([
                'ticket' => __('Clinical records can only be added for the patient you are serving at your counter.'),
            ]);
        }
    }

    public function hasCareRelationship(User $user, Patient $patient): bool
    {
        if (! $user->is_active || ! $user->can(Permission::ViewClinicalHistory->value)) {
            return false;
        }

        $departmentIds = $user->departments()->pluck('departments.id')->all();

        return DB::table('visits')
            ->join('queue_tickets', 'queue_tickets.visit_id', '=', 'visits.id')
            ->join('services', 'services.id', '=', 'queue_tickets.service_id')
            ->where('visits.patient_id', $patient->id)
            ->whereIn('visits.status', [VisitStatus::CheckedIn->value, VisitStatus::InProgress->value])
            ->where(fn (Builder $query) => $query
                ->whereIn('services.department_id', $departmentIds === [] ? [0] : $departmentIds)
                ->orWhere('queue_tickets.served_by', $user->id))
            ->exists();
    }

    /**
     * Authorize and audit a read of a patient's clinical history.
     *
     * @throws AuthorizationException
     */
    public function authorizeHistory(User $user, Patient $patient, string $context): void
    {
        if (! $this->hasCareRelationship($user, $patient)) {
            AuditLog::record($user, 'clinical.history.view', $patient, ['context' => $context], 'denied');

            throw new AuthorizationException(__('You do not have an active care relationship with this patient.'));
        }

        AuditLog::record($user, 'clinical.history.view', $patient, ['context' => $context]);
    }
}
