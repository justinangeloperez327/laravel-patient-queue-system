<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit entry (a database trigger rejects UPDATE and DELETE).
 * Metadata holds identifiers and timestamps only — never patient identifiers.
 *
 * @property int $id
 * @property int|null $actor_id
 * @property string $action
 * @property string $subject_type
 * @property int|null $subject_id
 * @property string $outcome
 * @property array<string, mixed> $metadata
 * @property CarbonImmutable $created_at
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function record(?User $actor, string $action, Model $subject, array $metadata = [], string $outcome = 'success'): self
    {
        $key = $subject->getKey();

        return self::query()->create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => is_int($key) ? $key : null,
            'outcome' => $outcome,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
