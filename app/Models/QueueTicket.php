<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ClinicalPriority;
use App\Enums\QueueEventType;
use App\Enums\QueueTicketStatus;
use App\Enums\SchedulingPreference;
use Carbon\CarbonImmutable;
use Database\Factories\QueueTicketFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A place in one service's queue for one visit. All attributes are set by
 * the queue actions; there is no mass assignment.
 *
 * @property int $id
 * @property int $visit_id
 * @property int $service_id
 * @property int|null $counter_id
 * @property int|null $assigned_provider_id
 * @property int|null $served_by
 * @property int|null $transferred_from_id
 * @property string $ticket_number
 * @property int $sequence_number
 * @property CarbonImmutable $queue_date
 * @property ClinicalPriority $priority
 * @property string|null $priority_reason
 * @property SchedulingPreference $scheduling_preference
 * @property QueueTicketStatus $status
 * @property int $issued_by
 * @property CarbonImmutable $queued_at
 * @property CarbonImmutable $eligible_at
 * @property CarbonImmutable|null $called_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Visit $visit
 * @property-read Service $service
 * @property-read Counter|null $counter
 * @property-read User|null $assignedProvider
 * @property-read User|null $servedBy
 * @property-read QueueTicket|null $transferredFrom
 * @property-read QueueTicket|null $transferredTo
 */
class QueueTicket extends Model
{
    /** @use HasFactory<QueueTicketFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'waiting',
        'priority' => 0,
        'scheduling_preference' => 'standard',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'queue_date' => 'date',
            'priority' => ClinicalPriority::class,
            'scheduling_preference' => SchedulingPreference::class,
            'status' => QueueTicketStatus::class,
            'sequence_number' => 'integer',
            'queued_at' => 'datetime',
            'eligible_at' => 'datetime',
            'called_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Visit, $this>
     */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<Counter, $this>
     */
    public function counter(): BelongsTo
    {
        return $this->belongsTo(Counter::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedProvider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_provider_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function servedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'served_by');
    }

    /**
     * @return BelongsTo<QueueTicket, $this>
     */
    public function transferredFrom(): BelongsTo
    {
        return $this->belongsTo(QueueTicket::class, 'transferred_from_id');
    }

    /**
     * @return HasOne<QueueTicket, $this>
     */
    public function transferredTo(): HasOne
    {
        return $this->hasOne(QueueTicket::class, 'transferred_from_id');
    }

    /**
     * @return HasMany<QueueEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(QueueEvent::class)->orderBy('id');
    }

    public function recallCount(): int
    {
        return $this->events()->where('event_type', QueueEventType::Recalled->value)->count();
    }

    /**
     * @param  Builder<QueueTicket>  $query
     * @param  list<QueueTicketStatus>  $statuses
     */
    public function scopeInStatus(Builder $query, array $statuses): void
    {
        $query->whereIn('status', QueueTicketStatus::valuesOf($statuses));
    }
}
