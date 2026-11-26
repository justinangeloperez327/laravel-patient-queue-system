<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProviderScheduleExceptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A one-off period in which a provider is unavailable. Stored in UTC.
 *
 * @property int $id
 * @property int $provider_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $provider
 */
#[Fillable(['provider_id', 'starts_at', 'ends_at', 'reason'])]
class ProviderScheduleException extends Model
{
    /** @use HasFactory<ProviderScheduleExceptionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }
}
