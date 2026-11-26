<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Appointments\MarkAppointmentNoShow;
use App\Models\Appointment;
use App\Support\ClinicTime;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * End-of-day sweep: pending appointments from earlier clinic-local days
 * become no-shows. Same-day no-shows are recorded by reception after the
 * grace period.
 */
final class MarkNoShowsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'appointments:mark-no-shows';

    /**
     * @var string
     */
    protected $description = 'Mark pending appointments from previous clinic days as no-shows';

    public function handle(MarkAppointmentNoShow $markNoShow): int
    {
        $marked = 0;

        Appointment::query()
            ->pending()
            ->where('scheduled_start_at', '<', ClinicTime::today()->utc())
            ->orderBy('id')
            ->each(function (Appointment $appointment) use ($markNoShow, &$marked): void {
                try {
                    $markNoShow->handle(null, $appointment);
                    $marked++;
                } catch (ValidationException) {
                    // Changed concurrently (e.g. checked in); leave it.
                }
            });

        $this->components->info("Marked {$marked} appointment(s) as no-show.");

        return self::SUCCESS;
    }
}
