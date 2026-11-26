<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When clinic.security.require_two_factor is on, staff without confirmed
 * two-factor authentication can only reach the security settings (to set
 * it up) and log out.
 *
 * Registered as Livewire persistent middleware too: component update
 * requests are re-checked against the page they were rendered on, so the
 * Livewire endpoint cannot be used to act from any other page.
 */
final class RequireTwoFactor
{
    private const array ALLOWED_ROUTES = [
        'security.edit',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.disable',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || config('clinic.security.require_two_factor') !== true || $user->two_factor_confirmed_at !== null) {
            return $next($request);
        }

        $route = $request->route()?->getName();

        // The Livewire update endpoint itself is checked via persistent middleware (see above).
        if (($route !== null && str_ends_with($route, 'livewire.update')) || in_array($route, self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, __('Two-factor authentication is required.'));
        }

        return redirect()->route('security.edit')
            ->with('status', __('Your clinic requires two-factor authentication. Set it up to continue.'));
    }
}
