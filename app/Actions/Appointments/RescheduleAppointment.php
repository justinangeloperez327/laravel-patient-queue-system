<?php

declare(strict_types=1);

namespace App\Actions\Appointments;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\ProviderAvailability;
use App\Support\ConstraintViolation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Moves a pending appointment to another free slot (optionally with another
 * eligible provider for the same service). The appointment number is kept
 * and the change is audited.
 */
final class RescheduleAppointment
{
    use ValidatesBookings;

    public function __construct(private readonly ProviderAvailability $availability) {}

    protected function availability(): ProviderAvailability
    {
        return $this->availability;
    }

    /**
     * @param  array<string, mixed>  $input  provider_id, scheduled_start_at (ISO-8601)
     */
    public function handle(User $actor, Appointment $appointment, array $input): Appointment
    {
        Gate::forUser($actor)->authorize('update', $appointment);

        /** @var array{provider_id: int} $data */
        $data = Validator::make($input, [
            'provider_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'scheduled_start_at' => ['required', 'string'],
        ])->validate();

        $provider = User::query()->findOrFail((int) $data['provider_id']);
        $start = $this->parseStart($input['scheduled_start_at']);

        return ConstraintViolation::guard(fn (): Appointment => DB::transaction(function () use ($actor, $appointment, $provider, $start): Appointment {
            /** @var Appointment $locked */
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isPending()) {
                throw ValidationException::withMessages(['status' => __('Only scheduled or confirmed appointments can be rescheduled.')]);
            }

            $this->ensureBookable($locked->patient, $locked->service, $provider);
            $this->ensureWithinHorizon($start);
            $slot = $this->requireSlot($provider, $locked->service, $start, $locked);
            $this->ensurePatientFree($locked->patient, $slot, $locked);

            $before = [
                'provider_id' => $locked->provider_id,
                'scheduled_start_at' => $locked->scheduled_start_at->toIso8601String(),
            ];

            $locked->provider_id = $provider->id;
            $locked->scheduled_start_at = $slot->start;
            $locked->scheduled_end_at = $slot->end;
            $locked->save();

            AuditLog::record($actor, 'appointment.rescheduled', $locked, [
                'from' => $before,
                'to' => ['provider_id' => $provider->id, 'scheduled_start_at' => $slot->start->toIso8601String()],
            ]);

            return $locked;
        }), $this->bookingConstraints());
    }
}
