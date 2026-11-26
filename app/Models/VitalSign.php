<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One set of vital signs. Append-only: a wrong reading is corrected by
 * recording a new set. Units: mmHg, beats/min, breaths/min, °C, %, kg, cm,
 * pain 0–10.
 *
 * @property int $id
 * @property int $visit_id
 * @property int $queue_ticket_id
 * @property int $recorded_by
 * @property int|null $systolic_bp
 * @property int|null $diastolic_bp
 * @property int|null $heart_rate
 * @property int|null $respiratory_rate
 * @property string|null $temperature_celsius
 * @property int|null $oxygen_saturation
 * @property string|null $weight_kg
 * @property string|null $height_cm
 * @property int|null $pain_score
 * @property string|null $remarks
 * @property CarbonImmutable $recorded_at
 * @property-read User $recordedBy
 */
class VitalSign extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'systolic_bp',
        'diastolic_bp',
        'heart_rate',
        'respiratory_rate',
        'temperature_celsius',
        'oxygen_saturation',
        'weight_kg',
        'height_cm',
        'pain_score',
        'remarks',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'systolic_bp' => 'integer',
            'diastolic_bp' => 'integer',
            'heart_rate' => 'integer',
            'respiratory_rate' => 'integer',
            'temperature_celsius' => 'decimal:1',
            'oxygen_saturation' => 'integer',
            'weight_kg' => 'decimal:2',
            'height_cm' => 'decimal:1',
            'pain_score' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
