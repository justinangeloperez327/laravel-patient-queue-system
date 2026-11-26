<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QueueEventType;
use App\Enums\QueueTicketStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only record of a ticket transition, recall or priority change
 * (a database trigger rejects UPDATE and DELETE).
 *
 * @property int $id
 * @property int $queue_ticket_id
 * @property int|null $user_id
 * @property QueueEventType $event_type
 * @property QueueTicketStatus|null $previous_status
 * @property QueueTicketStatus|null $new_status
 * @property int|null $counter_id
 * @property string|null $remarks
 * @property array<string, mixed> $metadata
 * @property CarbonImmutable $created_at
 * @property-read QueueTicket $ticket
 * @property-read User|null $user
 * @property-read Counter|null $counter
 */
class QueueEvent extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => QueueEventType::class,
            'previous_status' => QueueTicketStatus::class,
            'new_status' => QueueTicketStatus::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<QueueTicket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(QueueTicket::class, 'queue_ticket_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Counter, $this>
     */
    public function counter(): BelongsTo
    {
        return $this->belongsTo(Counter::class);
    }
}
