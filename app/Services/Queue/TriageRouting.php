<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Enums\TriageMode;
use App\Models\Service;
use Illuminate\Validation\ValidationException;

/**
 * Chooses the first queue for a check-in (docs/WORKFLOW.md): the triage
 * service when the requested service requires triage, or when triage is
 * optional and reception selects it; otherwise the requested service.
 */
final class TriageRouting
{
    public function firstService(Service $requested, bool $sendToTriage): Service
    {
        $routeToTriage = match ($requested->triage_mode) {
            TriageMode::Required => true,
            TriageMode::Optional => $sendToTriage,
            TriageMode::None => false,
        };

        if (! $routeToTriage) {
            return $requested;
        }

        $triage = $requested->triageService;

        if ($triage === null || ! $triage->is_active) {
            throw ValidationException::withMessages(['service_id' => __('The triage service for :service is not available.', ['service' => $requested->name])]);
        }

        return $triage;
    }
}
