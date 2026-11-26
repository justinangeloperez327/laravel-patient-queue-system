<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->auditDeniedClinicalAccess();
    }

    /**
     * Every refused attempt to open a patient record is audited, wherever
     * the check happens (route middleware, component or action).
     */
    protected function auditDeniedClinicalAccess(): void
    {
        Gate::after(function (?User $user, string $ability, ?bool $result, array $arguments): void {
            if ($result === true || $user === null || $ability !== 'viewRecord' || ! ($arguments[0] ?? null) instanceof Patient) {
                return;
            }

            AuditLog::record($user, 'clinical.history.view', $arguments[0], ['context' => 'patient_record'], 'denied');
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
