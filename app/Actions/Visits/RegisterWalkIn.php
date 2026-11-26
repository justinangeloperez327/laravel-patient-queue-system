<?php

declare(strict_types=1);

namespace App\Actions\Visits;

use App\Enums\VisitType;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\Queue\TicketIssuer;
use App\Services\Queue\TriageRouting;
use App\Support\ConstraintViolation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Checks in a walk-in patient for a requested service.
 */
final class RegisterWalkIn
{
    use OpensVisits;

    public function __construct(
        private readonly TicketIssuer $issuer,
        private readonly TriageRouting $routing,
    ) {}

    /**
     * Opens the visit and issues its first ticket in one transaction.
     *
     * @param  array<string, mixed>  $input  patient_id, service_id, reason_for_visit?, send_to_triage?
     */
    public function handle(User $actor, array $input): Visit
    {
        Gate::forUser($actor)->authorize('create', Visit::class);

        /** @var array{patient_id: int, service_id: int, reason_for_visit?: string|null, send_to_triage?: bool} $data */
        $data = Validator::make($input, [
            'patient_id' => ['required', 'integer', Rule::exists('patients', 'id')],
            'service_id' => ['required', 'integer', Rule::exists('services', 'id')],
            'reason_for_visit' => ['nullable', 'string', 'max:1000'],
            'send_to_triage' => ['sometimes', 'boolean'],
        ])->validate();

        return ConstraintViolation::guard(fn (): Visit => DB::transaction(function () use ($actor, $data): Visit {
            /** @var Patient $patient */
            $patient = Patient::query()->whereKey((int) $data['patient_id'])->lockForUpdate()->firstOrFail();
            $service = Service::query()->findOrFail((int) $data['service_id']);

            $this->ensurePatientCanCheckIn($patient);
            $this->ensureServiceOpen($service);

            $visit = $this->openVisit($actor, $patient, $service, VisitType::WalkIn, null, $data['reason_for_visit'] ?? null);
            $this->issuer->issue($actor, $visit, $this->routing->firstService($service, (bool) ($data['send_to_triage'] ?? false)));

            AuditLog::record($actor, 'visit.checked_in', $visit, ['walk_in' => true]);

            return $visit;
        }), $this->checkInConstraints());
    }
}
