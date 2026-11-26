<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A public waiting-room screen. It authenticates with a revocable pairing
 * key (only its SHA-256 hash is stored) and only ever receives sanitized
 * data: ticket numbers and room names, never patient identifiers.
 *
 * @property int $id
 * @property string $name
 * @property string $credential_hash
 * @property bool $is_active
 * @property CarbonImmutable|null $last_seen_at
 * @property array{service_ids?: list<int>, voice_lang?: string, title?: string} $configuration
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Hidden(['credential_hash'])]
class DisplayDevice extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'configuration' => '{}',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
            'configuration' => 'array',
        ];
    }

    public static function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }

    public function matchesKey(string $key): bool
    {
        return hash_equals($this->credential_hash, self::hashKey($key));
    }

    /**
     * @return list<int>
     */
    public function serviceIds(): array
    {
        $ids = $this->configuration['service_ids'] ?? [];

        return array_map('intval', $ids);
    }

    public function voiceLang(): string
    {
        $lang = $this->configuration['voice_lang'] ?? 'en-US';

        return preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $lang) === 1 ? $lang : 'en-US';
    }

    public function title(): string
    {
        $title = $this->configuration['title'] ?? '';

        return $title !== '' ? $title : $this->name;
    }

    public function channelName(): string
    {
        return 'display-device.'.$this->id;
    }

    /**
     * Active devices showing a service.
     *
     * @param  Builder<DisplayDevice>  $query
     */
    public function scopeShowingService(Builder $query, int $serviceId): void
    {
        $query->where('is_active', true)
            ->whereRaw("configuration->'service_ids' @> ?::jsonb", [json_encode([$serviceId])]);
    }
}
