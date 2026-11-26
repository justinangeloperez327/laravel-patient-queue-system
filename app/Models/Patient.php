<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Sex;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Patient demographics. Identifiable data: never broadcast, log or expose
 * outside authorized staff screens.
 *
 * @property int $id
 * @property string $patient_number
 * @property string $first_name
 * @property string|null $middle_name
 * @property string $last_name
 * @property Carbon $date_of_birth
 * @property Sex|null $sex
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $nationality
 * @property string|null $address
 * @property string|null $emergency_contact_name
 * @property string|null $emergency_contact_phone
 * @property int $registered_by
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $registeredBy
 */
#[Fillable([
    'first_name',
    'middle_name',
    'last_name',
    'date_of_birth',
    'sex',
    'phone',
    'email',
    'nationality',
    'address',
    'emergency_contact_name',
    'emergency_contact_phone',
])]
#[Hidden([
    'phone',
    'email',
    'address',
    'emergency_contact_name',
    'emergency_contact_phone',
])]
class Patient extends Model
{
    /** @use HasFactory<PatientFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'sex' => Sex::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function fullName(): string
    {
        return collect([$this->first_name, $this->middle_name, $this->last_name])
            ->filter(fn (?string $part): bool => filled($part))
            ->implode(' ');
    }

    /**
     * Search by patient number, name, phone or exact date of birth (YYYY-MM-DD).
     *
     * @param  Builder<Patient>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)).'%';

        $query->where(function (Builder $query) use ($term, $like): void {
            $query->whereRaw('lower(patient_number) like ?', [$like])
                ->orWhereRaw('lower(first_name) like ?', [$like])
                ->orWhereRaw('lower(last_name) like ?', [$like])
                ->orWhereRaw("lower(first_name || ' ' || last_name) like ?", [$like])
                ->orWhere('phone', 'like', $like);

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $term) === 1 && strtotime($term) !== false) {
                $query->orWhereDate('date_of_birth', $term);
            }
        });
    }
}
