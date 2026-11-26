<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An attributed, signed addition to a signed clinical note. Append-only.
 *
 * @property int $id
 * @property int $clinical_note_id
 * @property int $author_id
 * @property string $amendment
 * @property string $reason
 * @property CarbonImmutable $signed_at
 * @property CarbonImmutable $created_at
 * @property-read User $author
 */
class ClinicalNoteAmendment extends Model
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
            'signed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
