<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Permission catalogue. Role assignments live in {@see Role::permissions()}.
 *
 * Exact clinical access rules are a proposed default and must be validated
 * with the clinic before production (docs/ROLES_AND_PERMISSIONS.md).
 */
enum Permission: string
{
    // Shared
    case ViewDashboard = 'dashboard.view';

    // Administration
    case ManageUsers = 'users.manage';
    case ManageDepartments = 'departments.manage';
    case ManageServices = 'services.manage';
    case ManageCounters = 'counters.manage';
    case ManageSettings = 'settings.manage';
    case ManageDisplays = 'displays.manage';
    case ManageSchedules = 'schedules.manage';
    case ViewOperationalReports = 'reports.operational.view';

    // Reception (patient demographics are receptionist-only in this phase;
    // clinical access to patient records arrives with care-relationship
    // scoping in phase 5)
    case ViewPatients = 'patients.view';
    case RegisterPatients = 'patients.register';
    case UpdatePatientDemographics = 'patients.demographics.update';
    case ManageAppointments = 'appointments.manage';
    case CheckInVisits = 'visits.check_in';
    case IssueTickets = 'queue.tickets.issue';
    case RequeueTickets = 'queue.tickets.requeue';

    // Queue operation (assigned queues only; department scope enforced by policies)
    case OperateQueue = 'queue.operate';
    case TransferTickets = 'queue.tickets.transfer';
    case CompleteTickets = 'queue.tickets.complete';

    // Clinical
    case PerformTriage = 'clinical.triage.perform';
    case RecordVitalSigns = 'clinical.vitals.record';
    case WriteNursingNotes = 'clinical.notes.nursing.write';
    case WriteSoapNotes = 'clinical.notes.soap.write';
    case SignClinicalNotes = 'clinical.notes.sign';
    case ViewClinicalHistory = 'clinical.history.view';
    case AssignClinicalPriority = 'clinical.priority.assign';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $permission): string => $permission->value, self::cases());
    }
}
