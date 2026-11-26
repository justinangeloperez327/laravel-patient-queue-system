<?php

declare(strict_types=1);

namespace App\Livewire\Queues;

use App\Actions\Queues\CallNextTicket;
use App\Actions\Queues\CloseCounterSession;
use App\Actions\Queues\CompleteTicket;
use App\Actions\Queues\OpenCounterSession;
use App\Actions\Queues\RecallTicket;
use App\Actions\Queues\SkipTicket;
use App\Actions\Queues\StartTicket;
use App\Actions\Queues\TransferTicket;
use App\Enums\Permission;
use App\Enums\QueueTicketStatus;
use App\Livewire\Concerns\ManagesQueueTickets;
use App\Models\Counter;
use App\Models\CounterSession;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Services\Queue\QueueOrdering;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * @property-read CounterSession|null $session
 * @property-read QueueTicket|null $current
 * @property-read Collection<int, QueueTicket> $upNext
 * @property-read Collection<int, Counter> $counters
 * @property-read Collection<int, Service> $transferTargets
 *
 * The counter workspace: open a counter, call the next patient, then
 * recall, skip, start, complete or transfer.
 */
#[Title('My counter')]
final class Workspace extends Component
{
    use ManagesQueueTickets;

    public string $transferServiceId = '';

    public string $transferRemarks = '';

    public function mount(): void
    {
        abort_unless($this->queueActor()->can(Permission::OperateQueue->value), 403);
    }

    #[Computed]
    public function session(): ?CounterSession
    {
        return CounterSession::query()->open()->where('user_id', $this->queueActor()->id)->with('counter.services')->first();
    }

    /**
     * Counters the user may staff, with whoever is staffing them now.
     *
     * @return Collection<int, Counter>
     */
    #[Computed]
    public function counters(): Collection
    {
        $user = $this->queueActor();

        return Counter::query()
            ->active()
            ->whereIn('department_id', $user->departments()->select('departments.id'))
            ->with(['services', 'sessions' => fn ($query) => $query->whereNull('closed_at')->with('user')])
            ->orderBy('code')
            ->get();
    }

    #[Computed]
    public function current(): ?QueueTicket
    {
        $session = $this->session;

        return $session === null ? null : QueueTicket::query()
            ->with(['visit.patient', 'visit.requestedService', 'service'])
            ->where('counter_id', $session->counter_id)
            ->inStatus([QueueTicketStatus::Called, QueueTicketStatus::Serving])
            ->first();
    }

    /**
     * The next eligible tickets for this counter, in call order.
     *
     * @return Collection<int, QueueTicket>
     */
    #[Computed]
    public function upNext(): Collection
    {
        $session = $this->session;

        if ($session === null) {
            return new Collection;
        }

        $serviceIds = $session->counter->services->where('is_active', true)->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();

        return app(QueueOrdering::class)
            ->eligible(array_values($serviceIds), $this->queueActor()->id)
            ->with('visit.patient')
            ->limit(8)
            ->get();
    }

    /**
     * @return Collection<int, Service>
     */
    #[Computed]
    public function transferTargets(): Collection
    {
        return Service::query()->active()->whereKeyNot($this->current->service_id ?? 0)->orderBy('name')->get();
    }

    public function openCounter(int $counterId, OpenCounterSession $open): void
    {
        $open->handle($this->queueActor(), Counter::query()->findOrFail($counterId));
        $this->refreshWorkspace();
    }

    public function closeCounter(CloseCounterSession $close): void
    {
        $close->handle($this->queueActor());
        $this->refreshWorkspace();
    }

    public function callNext(CallNextTicket $callNext): void
    {
        $session = $this->session;
        abort_if($session === null, 409);

        $ticket = $callNext->handle($this->queueActor(), $session->counter, $this->requestKey);
        $this->afterAction(__('Called :number.', ['number' => $ticket->ticket_number]));
    }

    public function recall(RecallTicket $recall): void
    {
        $ticket = $recall->handle($this->queueActor(), $this->currentOrFail(), $this->requestKey);
        $this->afterAction(__('Recalled :number.', ['number' => $ticket->ticket_number]));
    }

    public function skip(SkipTicket $skip): void
    {
        $ticket = $skip->handle($this->queueActor(), $this->currentOrFail(), $this->requestKey);
        $this->afterAction(__('Skipped :number. It can be requeued from the queue board.', ['number' => $ticket->ticket_number]));
    }

    public function start(StartTicket $start): void
    {
        $ticket = $start->handle($this->queueActor(), $this->currentOrFail(), $this->requestKey);
        $this->afterAction(__('Serving :number.', ['number' => $ticket->ticket_number]));
    }

    public function complete(CompleteTicket $complete): void
    {
        $ticket = $complete->handle($this->queueActor(), $this->currentOrFail(), $this->requestKey);
        $this->afterAction(__('Completed :number.', ['number' => $ticket->ticket_number]));
    }

    public function transfer(TransferTicket $transfer): void
    {
        $from = $this->currentOrFail();
        $next = $transfer->handle(
            $this->queueActor(),
            $from,
            $this->transferServiceId === '' ? 0 : (int) $this->transferServiceId,
            $this->transferRemarks,
            $this->requestKey,
        );
        $this->reset('transferServiceId', 'transferRemarks');
        $this->afterAction(__('Transferred :from → :to.', ['from' => $from->ticket_number, 'to' => $next->ticket_number]));
    }

    public function render(): View
    {
        return view('livewire.queues.workspace');
    }

    private function currentOrFail(): QueueTicket
    {
        $current = $this->current;
        abort_if($current === null, 409);

        return $current;
    }

    private function afterAction(string $message): void
    {
        $this->rotateRequestKey();
        $this->queueStatus($message);
        $this->refreshWorkspace();
    }

    private function refreshWorkspace(): void
    {
        unset($this->session, $this->current, $this->upNext, $this->counters, $this->transferTargets);
    }
}
