<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Clinic settings editable by administrators. Stored values override the
 * config('clinic.*') defaults at boot, so the rest of the application keeps
 * reading config(). Every change is audited with old and new values.
 */
final class ClinicSettings
{
    private const string CACHE_KEY = 'clinic.settings.v1';

    /**
     * Setting key => [label, validation rules, cast]
     *
     * @return array<string, array{label: string, rules: list<mixed>, cast: 'int'|'bool'|'string'}>
     */
    public static function definitions(): array
    {
        return [
            'clinic.timezone' => ['label' => 'Clinic timezone', 'rules' => ['required', 'string', 'timezone:all'], 'cast' => 'string'],
            'clinic.appointments.late_grace_minutes' => ['label' => 'Late grace period (minutes)', 'rules' => ['required', 'integer', 'between:0,120'], 'cast' => 'int'],
            'clinic.appointments.early_eligibility_minutes' => ['label' => 'Early queue eligibility (minutes)', 'rules' => ['required', 'integer', 'between:0,120'], 'cast' => 'int'],
            'clinic.appointments.booking_horizon_days' => ['label' => 'Booking horizon (days)', 'rules' => ['required', 'integer', 'between:1,365'], 'cast' => 'int'],
            'clinic.queue.walk_in_safeguard_minutes' => ['label' => 'Walk-in safeguard (minutes)', 'rules' => ['required', 'integer', 'between:5,240'], 'cast' => 'int'],
            'clinic.queue.max_recalls' => ['label' => 'Maximum recalls', 'rules' => ['required', 'integer', 'between:0,10'], 'cast' => 'int'],
            'clinic.security.require_two_factor' => ['label' => 'Require two-factor authentication', 'rules' => ['required', 'boolean'], 'cast' => 'bool'],
        ];
    }

    /**
     * Apply stored overrides to the runtime config. Safe to call when the
     * database is unavailable (e.g. during package discovery).
     */
    public function applyToConfig(): void
    {
        try {
            foreach ($this->stored() as $key => $value) {
                config([$key => $value]);
            }
        } catch (Throwable) {
            // Fall back to file/environment configuration.
        }
    }

    /**
     * @return array<string, int|bool|string>
     */
    public function current(): array
    {
        $values = [];

        foreach (array_keys(self::definitions()) as $key) {
            $values[$key] = $this->cast($key, config($key));
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(User $actor, array $input): void
    {
        Gate::forUser($actor)->authorize(Permission::ManageSettings->value);

        $definitions = self::definitions();
        $rules = [];

        foreach ($definitions as $key => $definition) {
            $rules[str_replace('.', '__', $key)] = $definition['rules'];
        }

        $flat = [];
        foreach ($definitions as $key => $definition) {
            $flat[str_replace('.', '__', $key)] = $input[$key] ?? null;
        }

        $validated = Validator::make($flat, $rules)->validate();
        $before = $this->current();
        $changes = [];

        DB::transaction(function () use ($actor, $definitions, $validated, $before, &$changes): void {
            foreach (array_keys($definitions) as $key) {
                $value = $this->cast($key, $validated[str_replace('.', '__', $key)]);

                if ($value === $before[$key]) {
                    continue;
                }

                DB::table('settings')->updateOrInsert(['key' => $key], [
                    'value' => json_encode($value),
                    'updated_by' => $actor->id,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]);
                $changes[$key] = ['from' => $before[$key], 'to' => $value];
            }

            if ($changes !== []) {
                AuditLog::record($actor, 'settings.updated', $actor, ['changes' => $changes]);
            }
        });

        Cache::forget(self::CACHE_KEY);
        $this->applyToConfig();
    }

    /**
     * @return array<string, int|bool|string>
     */
    private function stored(): array
    {
        /** @var array<string, int|bool|string> $stored */
        $stored = Cache::rememberForever(self::CACHE_KEY, function (): array {
            if (! Schema::hasTable('settings')) {
                // Not migrated yet: do not cache an empty result.
                throw new \RuntimeException('Settings table missing.');
            }

            $values = [];

            foreach (DB::table('settings')->whereIn('key', array_keys(self::definitions()))->get(['key', 'value']) as $row) {
                $values[(string) $row->key] = $this->cast((string) $row->key, json_decode((string) $row->value, true));
            }

            return $values;
        });

        return $stored;
    }

    private function cast(string $key, mixed $value): int|bool|string
    {
        return match (self::definitions()[$key]['cast']) {
            'int' => is_numeric($value) ? (int) $value : 0,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL),
            'string' => is_scalar($value) ? (string) $value : '',
        };
    }

    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
