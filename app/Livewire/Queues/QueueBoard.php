<?php

declare(strict_types=1);

namespace App\Livewire\Queues;

use App\Enums\Permission;
use App\Enums\QueueTicketStatus;
use App\Livewire\Concerns\ManagesQueueTickets;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Services\Queue\QueueOrdering;
use App\Support\ClinicTime;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * @property-read Collection<int, Service> $services
 * @property-read array<int, Collection<int, QueueTicket>> $ticketsByService
 *
 * Today's queues per service. Reception sees every service; queue staff see
 * services of their assigned departments only.
 */
#[Title('Queues')]
final class QueueBoard extends Component
{
    use ManagesQueueTickets;

    public function mount(): void
    {
        $this->authorize('viewAny', QueueTicket::class);
    }

    /**
     * @return Collection<int, Service>
     */
    #[Computed]
    public function services(): Collection
    {
        $user = $this->queueActor();

        return Service::query()
            ->active()
            ->when(! $user->can(Permission::IssueTickets->value), fn ($query) => $query->whereIn('department_id', $user->departments()->select('departments.id')))
            ->orderBy('queue_prefix')
            ->get();
    }

    /**
     * Unresolved tickets of today, per service, in call order.
     *
     * @return array<int, Collection<int, QueueTicket>>
     */
    #[Computed]
    public function ticketsByService(): array
    {
        $ids = $this->services->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        return app(QueueOrdering::class)
            ->ordered(QueueTicket::query()
                ->with(['visit.patient', 'counter'])
                ->whereIn('service_id', $ids)
                ->whereDate('queue_date', ClinicTime::today()->toDateString())
                ->inStatus(QueueTicketStatus::unresolved()))
            ->get()
            ->groupBy('service_id')
            ->all();
    }

    public function render(): View
    {
        return view('livewire.queues.board');
    }
}
