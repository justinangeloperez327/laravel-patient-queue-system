<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Visits\CloseVisitBeforeService;
use App\Enums\VisitStatus;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

/**
 * Inline "cancel visit / left without service" with a required reason.
 */
trait ClosesVisits
{
    #[Locked]
    public ?int $closingVisitId = null;

    #[Locked]
    public string $closingOutcome = '';

    public string $closeReason = '';

    public function startClosing(int $visitId, string $outcome): void
    {
        $this->closingVisitId = Visit::query()->findOrFail($visitId)->id;
        $this->closingOutcome = VisitStatus::from($outcome)->value;
        $this->closeReason = '';
        $this->resetErrorBag();
    }

    public function stopClosing(): void
    {
        $this->closingVisitId = null;
        $this->closingOutcome = '';
    }

    public function closeVisit(CloseVisitBeforeService $close): void
    {
        /** @var User $actor */
        $actor = Auth::user();
        $visit = Visit::query()->findOrFail($this->closingVisitId);

        $closed = $close->handle($actor, $visit, VisitStatus::from($this->closingOutcome), $this->closeReason);

        session()->flash('status', __('Visit :number: :status.', ['number' => $closed->visit_number, 'status' => strtolower($closed->status->label())]));
        $this->stopClosing();
    }
}
