<?php

declare(strict_types=1);

namespace App\Actions\Displays;

use App\Models\AuditLog;
use App\Models\DisplayDevice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Creates or updates a display. Creating (and rotating) returns the plain
 * pairing key exactly once; only its SHA-256 hash is stored.
 */
final class SaveDisplayDevice
{
    /**
     * @param  array<string, mixed>  $input  name, title?, service_ids, voice_lang, is_active
     * @return array{0: DisplayDevice, 1: string|null} the device and, when created, its pairing key
     */
    public function handle(User $actor, array $input, ?DisplayDevice $device = null): array
    {
        Gate::forUser($actor)->authorize($device === null ? 'create' : 'update', $device ?? DisplayDevice::class);

        /** @var array{name: string, title?: string|null, service_ids: list<int>, voice_lang: string, is_active: bool} $data */
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:120'],
            'service_ids' => ['required', 'array', 'min:1'],
            'service_ids.*' => ['integer', 'distinct', Rule::exists('services', 'id')],
            'voice_lang' => ['required', 'string', 'regex:/^[a-z]{2}(-[A-Z]{2})?$/'],
            'is_active' => ['required', 'boolean'],
        ], [
            'service_ids.required' => __('Choose at least one service to show.'),
            'voice_lang.regex' => __('Use a language tag such as en-US.'),
        ])->validate();

        return DB::transaction(function () use ($actor, $data, $device): array {
            $key = null;

            if ($device === null) {
                $device = new DisplayDevice;
                $key = self::newKey();
                $device->credential_hash = DisplayDevice::hashKey($key);
            }

            $device->name = trim($data['name']);
            $device->is_active = (bool) $data['is_active'];
            $device->configuration = [
                'service_ids' => array_values(array_unique(array_map('intval', $data['service_ids']))),
                'voice_lang' => $data['voice_lang'],
                'title' => trim((string) ($data['title'] ?? '')),
            ];
            $device->save();

            AuditLog::record($actor, $key === null ? 'display.updated' : 'display.created', $device);

            return [$device, $key];
        });
    }

    /**
     * Issues a new pairing key; every paired screen must pair again.
     */
    public function rotateKey(User $actor, DisplayDevice $device): string
    {
        Gate::forUser($actor)->authorize('update', $device);

        $key = self::newKey();
        $device->credential_hash = DisplayDevice::hashKey($key);
        $device->save();

        AuditLog::record($actor, 'display.key_rotated', $device);

        return $key;
    }

    private static function newKey(): string
    {
        return Str::lower(Str::random(8).'-'.Str::random(8).'-'.Str::random(8));
    }
}
