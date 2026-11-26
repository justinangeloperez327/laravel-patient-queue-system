<?php

declare(strict_types=1);

namespace App\Actions\Clinical;

use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\QueueTicket;
use App\Models\User;
use App\Models\VitalSign;
use App\Services\Clinical\ClinicalAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Records a set of vital signs for the patient being served. Ranges match
 * the database CHECK constraints; units are mmHg, beats/min, breaths/min,
 * °C, %, kg, cm and a 0–10 pain score.
 */
final class RecordVitalSigns
{
    public function __construct(private readonly ClinicalAccess $access) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, QueueTicket $ticket, array $input): VitalSign
    {
        Gate::forUser($actor)->authorize(Permission::RecordVitalSigns->value);

        $input = array_map(fn (mixed $value): mixed => is_string($value) && trim($value) === '' ? null : $value, $input);

        /** @var array<string, int|float|string|null> $data */
        $data = Validator::make($input, [
            'systolic_bp' => ['nullable', 'integer', 'between:50,300', 'required_with:diastolic_bp'],
            'diastolic_bp' => ['nullable', 'integer', 'between:20,200', 'required_with:systolic_bp', 'lt:systolic_bp'],
            'heart_rate' => ['nullable', 'integer', 'between:20,250'],
            'respiratory_rate' => ['nullable', 'integer', 'between:4,80'],
            'temperature_celsius' => ['nullable', 'numeric', 'decimal:0,1', 'between:30,45'],
            'oxygen_saturation' => ['nullable', 'integer', 'between:50,100'],
            'weight_kg' => ['nullable', 'numeric', 'decimal:0,2', 'between:0.3,500'],
            'height_cm' => ['nullable', 'numeric', 'decimal:0,1', 'between:20,260'],
            'pain_score' => ['nullable', 'integer', 'between:0,10'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ], [
            'diastolic_bp.lt' => __('Diastolic pressure must be lower than systolic.'),
            'temperature_celsius.between' => __('Temperature must be between 30.0 and 45.0 °C.'),
        ])->after(function (\Illuminate\Validation\Validator $validator) use ($input): void {
            $measurements = ['systolic_bp', 'heart_rate', 'respiratory_rate', 'temperature_celsius', 'oxygen_saturation', 'weight_kg', 'height_cm', 'pain_score'];

            if (collect($measurements)->every(fn (string $key): bool => ($input[$key] ?? null) === null)) {
                $validator->errors()->add('vitals', __('Enter at least one measurement.'));
            }
        })->validate();

        return DB::transaction(function () use ($actor, $ticket, $data): VitalSign {
            $locked = QueueTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $this->access->ensureServing($actor, $locked);

            $vitals = new VitalSign($data);
            $vitals->visit_id = $locked->visit_id;
            $vitals->queue_ticket_id = $locked->id;
            $vitals->recorded_by = $actor->id;
            $vitals->recorded_at = now();
            $vitals->save();

            AuditLog::record($actor, 'clinical.vitals.recorded', $vitals, ['visit_id' => $locked->visit_id, 'ticket_id' => $locked->id]);

            return $vitals;
        });
    }
}
