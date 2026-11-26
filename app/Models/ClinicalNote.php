<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ClinicalNoteStatus;
use App\Enums\ClinicalNoteType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Clinical documentation for one ticket of a visit. Clinical content: never
 * log, broadcast or expose it outside authorized clinical screens. Signed
 * notes are immutable (database trigger); corrections are amendments.
 *
 * @property int $id
 * @property int $visit_id
 * @property int $queue_ticket_id
 * @property int $author_id
 * @property ClinicalNoteType $note_type
 * @property string|null $chief_complaint
 * @property string|null $subjective
 * @property string|null $objective
 * @property string|null $assessment
 * @property string|null $plan
 * @property string|null $additional_notes
 * @property ClinicalNoteStatus $status
 * @property CarbonImmutable|null $signed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Visit $visit
 * @property-read QueueTicket $ticket
 * @property-read User $author
 */
#[Fillable(['chief_complaint', 'subjective', 'objective', 'assessment', 'plan', 'additional_notes'])]
class ClinicalNote extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'note_type' => ClinicalNoteType::class,
            'status' => ClinicalNoteStatus::class,
            'signed_at' => 'datetime',
        ];
    }

    public function isSigned(): bool
    {
        return $this->status === ClinicalNoteStatus::Signed;
    }

    /**
     * @return BelongsTo<Visit, $this>
     */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
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
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return HasMany<ClinicalNoteAmendment, $this>
     */
    public function amendments(): HasMany
    {
        return $this->hasMany(ClinicalNoteAmendment::class)->orderBy('id');
    }
}
