<?php

declare(strict_types=1);

namespace App\Support\Readiness;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Production readiness checks (docs/phases/PHASE_08_PRODUCTION.md). They
 * verify configuration and data that code can check; clinic, legal and
 * jurisdictional approvals are listed as manual items, never assumed.
 */
final class PreflightChecks
{
    /**
     * Database triggers that enforce immutability and append-only history.
     */
    public const array REQUIRED_TRIGGERS = [
        'audit_logs_no_update_or_delete',
        'queue_events_no_update_or_delete',
        'clinical_notes_signed_immutable',
        'clinical_note_amendments_append_only',
        'vital_signs_no_update_or_delete',
    ];

    /**
     * @return list<array{check: string, status: 'pass'|'fail'|'warn'|'manual', detail: string}>
     */
    public function run(): array
    {
        $results = [];
        $add = function (string $check, bool $ok, string $detail, bool $warnOnly = false) use (&$results): void {
            $results[] = ['check' => $check, 'status' => $ok ? 'pass' : ($warnOnly ? 'warn' : 'fail'), 'detail' => $detail];
        };

        $add('Environment is production', config('app.env') === 'production', 'APP_ENV='.var_export(config('app.env'), true));
        $add('Debug mode off', config('app.debug') === false, 'APP_DEBUG must be false');
        $add('Application key set', is_string(config('app.key')) && config('app.key') !== '', 'APP_KEY');
        $add('HTTPS application URL', str_starts_with((string) config('app.url'), 'https://'), 'APP_URL='.config('app.url'));
        $add('Secure session cookies', config('session.secure') === true, 'SESSION_SECURE_COOKIE=true');
        $add('Encrypted sessions', config('session.encrypt') === true, 'SESSION_ENCRYPT=true');
        $add('Session idle timeout ≤ 60 minutes', (int) config('session.lifetime') <= 60, 'SESSION_LIFETIME='.config('session.lifetime'));
        $add('PostgreSQL connection', config('database.default') === 'pgsql', 'DB_CONNECTION');
        $add('Database TLS required', in_array(config('database.connections.pgsql.sslmode'), ['require', 'verify-ca', 'verify-full'], true), 'DB_SSLMODE='.config('database.connections.pgsql.sslmode'), warnOnly: true);
        $add('Queued jobs run asynchronously', ! in_array(config('queue.default'), ['sync', 'null'], true), 'QUEUE_CONNECTION='.config('queue.default'));
        $add('Persistent cache and sessions', ! in_array(config('cache.default'), ['array', 'null'], true) && config('session.driver') !== 'array', 'CACHE_STORE / SESSION_DRIVER');
        $add('Display broadcasting configured', config('broadcasting.default') === 'reverb' && filled(config('broadcasting.connections.reverb.key')) && filled(config('broadcasting.connections.reverb.secret')), 'BROADCAST_CONNECTION=reverb with REVERB_* keys', warnOnly: true);
        $add('Mail delivery configured', ! in_array(config('mail.default'), ['log', 'array'], true), 'MAIL_MAILER (needed for staff invitations and password resets)');
        $add('Two-factor authentication required', config('clinic.security.require_two_factor') === true, 'Clinic settings → Require two-factor authentication');
        $add('Clinic timezone set', in_array(config('clinic.timezone'), \DateTimeZone::listIdentifiers(), true), 'CLINIC_TIMEZONE / Clinic settings='.config('clinic.timezone'));

        try {
            $add('No demo accounts', ! User::query()->where('email', 'like', '%@example.test')->exists(), 'Accounts ending in @example.test are synthetic demo users');

            $admins = User::query()->where('is_active', true)->role(Role::Admin->value)->get();
            $add('An active administrator exists', $admins->isNotEmpty(), 'Create one with php artisan staff:create');
            $add('Administrators use two-factor', $admins->every(fn (User $user): bool => $user->two_factor_confirmed_at !== null), 'Every active administrator has confirmed 2FA');

            $present = collect(DB::select('select tgname from pg_trigger where not tgisinternal'))->pluck('tgname')->all();
            $missing = array_values(array_diff(self::REQUIRED_TRIGGERS, $present));
            $add('Immutability triggers installed', $missing === [], $missing === [] ? count(self::REQUIRED_TRIGGERS).' triggers present' : 'Missing: '.implode(', ', $missing));

            Artisan::call('migrate:status', ['--pending' => true]);
            $pending = trim(Artisan::output());
            $add('All migrations applied', $pending === '' || str_contains($pending, 'No pending migrations'), 'php artisan migrate --force');
        } catch (Throwable $exception) {
            $add('Database reachable', false, $exception->getMessage());
        }

        foreach ([
            'Hosting jurisdiction, data residency and applicable health-privacy law confirmed',
            'Retention, deletion and breach-response policies approved by the clinic',
            'Clinical access and emergency ("break-glass") policy approved',
            'Queue, grace and no-show parameters approved by the clinic',
            'Restore drill performed on the production backup target',
        ] as $item) {
            $results[] = ['check' => $item, 'status' => 'manual', 'detail' => 'Requires sign-off; see docs/operations/'];
        }

        return $results;
    }
}
