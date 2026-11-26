<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Models\User;

/**
 * Queue access. Reception issues, requeues and cancels tickets anywhere;
 * clinical and service staff act only on tickets of services in their
 * assigned departments (server-side department scope).
 */
final class QueueTicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::IssueTickets->value) || $user->can(Permission::OperateQueue->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::IssueTickets->value);
    }

    public function operate(User $user, QueueTicket $ticket): bool
    {
        return $user->can(Permission::OperateQueue->value) && $this->inScope($user, $ticket);
    }

    public function complete(User $user, QueueTicket $ticket): bool
    {
        return $user->can(Permission::CompleteTickets->value) && $this->inScope($user, $ticket);
    }

    public function transfer(User $user, QueueTicket $ticket): bool
    {
        return $user->can(Permission::TransferTickets->value) && $this->inScope($user, $ticket);
    }

    public function requeue(User $user, QueueTicket $ticket): bool
    {
        return $user->can(Permission::RequeueTickets->value)
            && ($user->can(Permission::IssueTickets->value) || $this->inScope($user, $ticket));
    }

    public function cancel(User $user, QueueTicket $ticket): bool
    {
        return $user->can(Permission::IssueTickets->value)
            || ($user->can(Permission::OperateQueue->value) && $this->inScope($user, $ticket));
    }

    public function changePriority(User $user, QueueTicket $ticket): bool
    {
        return $user->can(Permission::AssignClinicalPriority->value) && $this->inScope($user, $ticket);
    }

    private function inScope(User $user, QueueTicket $ticket): bool
    {
        $departmentId = Service::query()->whereKey($ticket->service_id)->value('department_id');

        return is_int($departmentId) && $user->isAssignedToDepartment($departmentId);
    }
}
