<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Staff roles. Administrators receive no implicit clinical or reception
 * permissions; a user may hold several roles when authorized.
 */
enum Role: string
{
    case Admin = 'admin';
    case Receptionist = 'receptionist';
    case Nurse = 'nurse';
    case Doctor = 'doctor';
    case ServiceStaff = 'service_staff';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Receptionist => 'Receptionist',
            self::Nurse => 'Nurse',
            self::Doctor => 'Doctor',
            self::ServiceStaff => 'Service staff',
        };
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => [
                Permission::ViewDashboard,
                Permission::ManageUsers,
                Permission::ManageDepartments,
                Permission::ManageServices,
                Permission::ManageCounters,
                Permission::ManageSettings,
                Permission::ManageDisplays,
                Permission::ViewOperationalReports,
            ],
            self::Receptionist => [
                Permission::ViewDashboard,
                Permission::RegisterPatients,
                Permission::UpdatePatientDemographics,
                Permission::ManageAppointments,
                Permission::CheckInVisits,
                Permission::IssueTickets,
                Permission::RequeueTickets,
            ],
            self::Nurse => [
                Permission::ViewDashboard,
                Permission::OperateQueue,
                Permission::TransferTickets,
                Permission::CompleteTickets,
                Permission::PerformTriage,
                Permission::RecordVitalSigns,
                Permission::WriteNursingNotes,
                Permission::ViewClinicalHistory,
                Permission::AssignClinicalPriority,
            ],
            self::Doctor => [
                Permission::ViewDashboard,
                Permission::OperateQueue,
                Permission::TransferTickets,
                Permission::CompleteTickets,
                Permission::RecordVitalSigns,
                Permission::WriteSoapNotes,
                Permission::SignClinicalNotes,
                Permission::ViewClinicalHistory,
                Permission::AssignClinicalPriority,
            ],
            self::ServiceStaff => [
                Permission::ViewDashboard,
                Permission::OperateQueue,
                Permission::RequeueTickets,
                Permission::TransferTickets,
                Permission::CompleteTickets,
            ],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }
}
