<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Appointments\BookAppointment;
use App\Enums\DayOfWeek;
use App\Enums\Role;
use App\Enums\TriageMode;
use App\Models\Appointment;
use App\Models\Counter;
use App\Models\Department;
use App\Models\Patient;
use App\Models\ProviderSchedule;
use App\Models\Service;
use App\Models\StaffAssignment;
use App\Models\User;
use App\Services\ProviderAvailability;
use App\Support\ClinicTime;
use Illuminate\Database\Seeder;

/**
 * Synthetic clinic configuration for local development only, mirroring the
 * T → C → L → C → P example in docs/WORKFLOW.md. Requires DemoStaffSeeder.
 */
final class DemoMasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $departments = collect([
            'NUR' => 'Nursing',
            'OPD' => 'Outpatient consultation',
            'LAB' => 'Laboratory',
            'PHR' => 'Pharmacy',
        ])->map(fn (string $name, string $code): Department => Department::query()->firstOrCreate(['code' => $code], ['name' => $name]));

        $triage = Service::query()->firstOrCreate(['queue_prefix' => 'T'], [
            'department_id' => $departments['NUR']->id,
            'name' => 'Triage',
            'estimated_duration_minutes' => 10,
        ]);

        $consultation = Service::query()->firstOrCreate(['queue_prefix' => 'C'], [
            'department_id' => $departments['OPD']->id,
            'name' => 'General consultation',
            'triage_mode' => TriageMode::Required,
            'triage_service_id' => $triage->id,
            'allows_appointments' => true,
            'estimated_duration_minutes' => 15,
        ]);

        $laboratory = Service::query()->firstOrCreate(['queue_prefix' => 'L'], [
            'department_id' => $departments['LAB']->id,
            'name' => 'Specimen collection',
            'estimated_duration_minutes' => 10,
        ]);

        $pharmacy = Service::query()->firstOrCreate(['queue_prefix' => 'P'], [
            'department_id' => $departments['PHR']->id,
            'name' => 'Pharmacy dispensing',
            'estimated_duration_minutes' => 5,
        ]);

        $counters = [
            ['TRIAGE-1', 'Triage station 1', $triage],
            ['ROOM-1', 'Consultation room 1', $consultation],
            ['ROOM-2', 'Consultation room 2', $consultation],
            ['LAB-1', 'Laboratory window 1', $laboratory],
            ['PHARM-1', 'Pharmacy window 1', $pharmacy],
        ];

        foreach ($counters as [$code, $name, $service]) {
            $counter = Counter::query()->firstOrCreate(['code' => $code], [
                'department_id' => $service->department_id,
                'name' => $name,
            ]);
            $counter->services()->syncWithoutDetaching([$service->id => ['department_id' => $service->department_id]]);
        }

        $assignments = [
            Role::Nurse->value => ['NUR'],
            Role::Doctor->value => ['OPD'],
            Role::ServiceStaff->value => ['LAB', 'PHR'],
        ];

        foreach ($assignments as $role => $codes) {
            $user = User::query()->where('email', str_replace('_', '-', $role).'@example.test')->first();

            foreach ($user === null ? [] : $codes as $code) {
                StaffAssignment::query()->firstOrCreate(['user_id' => $user->id, 'department_id' => $departments[$code]->id]);
            }
        }

        $doctor = User::query()->where('email', 'doctor@example.test')->first();

        if ($doctor !== null && ! $doctor->providerSchedules()->exists()) {
            foreach ([DayOfWeek::Monday, DayOfWeek::Tuesday, DayOfWeek::Wednesday, DayOfWeek::Thursday, DayOfWeek::Friday] as $day) {
                ProviderSchedule::query()->create([
                    'provider_id' => $doctor->id,
                    'service_id' => $consultation->id,
                    'day_of_week' => $day,
                    'start_time' => '09:00',
                    'end_time' => '12:00',
                    'slot_duration_minutes' => 15,
                ]);
            }
        }

        $receptionist = User::query()->where('email', 'receptionist@example.test')->first();

        if ($receptionist !== null && Patient::query()->doesntExist()) {
            Patient::factory()->count(12)->create(['registered_by' => $receptionist->id]);
        }

        if ($receptionist !== null && $doctor !== null && Appointment::query()->doesntExist()) {
            $this->bookDemoAppointments($receptionist, $doctor, $consultation);
        }
    }

    /**
     * Book the next few free slots through the real booking action.
     */
    private function bookDemoAppointments(User $receptionist, User $doctor, Service $consultation): void
    {
        $availability = app(ProviderAvailability::class);
        $book = app(BookAppointment::class);
        $patients = Patient::query()->orderBy('id')->limit(4)->get();
        $day = ClinicTime::today();

        for ($i = 0; $i < 14 && $patients->isNotEmpty(); $i++, $day = $day->addDay()) {
            foreach (array_slice($availability->slots($doctor, $consultation, $day), 0, 2) as $slot) {
                $patient = $patients->shift();

                if ($patient === null) {
                    return;
                }

                $book->handle($receptionist, [
                    'patient_id' => $patient->id,
                    'service_id' => $consultation->id,
                    'provider_id' => $doctor->id,
                    'scheduled_start_at' => $slot->key(),
                    'reason_for_visit' => 'Follow-up',
                ]);
            }
        }
    }
}
