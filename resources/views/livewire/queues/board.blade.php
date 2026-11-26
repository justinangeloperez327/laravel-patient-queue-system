@php($tz = \App\Support\ClinicTime::timezone())
<div class="flex flex-col gap-6" wire:poll.10s>
    <x-page-header :heading="__('Queues')" :subheading="__('Today (:date). Waiting tickets are listed in call order.', ['date' => \App\Support\ClinicTime::today()->toDateString()])" />

    <x-status-message />
    @if ($editingTicketId !== null && ($editing = \App\Models\QueueTicket::find($editingTicketId)))
        <x-ticket-edit-form :ticket="$editing" :mode="$editingMode" />
    @endif
    <flux:error name="ticket" />

    @forelse ($this->services as $service)
        @php($tickets = $this->ticketsByService[$service->id] ?? collect())
        <section class="flex flex-col gap-2" wire:key="service-{{ $service->id }}" aria-labelledby="service-{{ $service->id }}">
            <div class="flex items-baseline gap-3">
                <flux:heading size="lg" level="2" id="service-{{ $service->id }}">{{ $service->queue_prefix }} — {{ $service->name }}</flux:heading>
                <flux:text>{{ trans_choice(':count waiting|:count waiting', $tickets->where('status', \App\Enums\QueueTicketStatus::Waiting)->count()) }}</flux:text>
            </div>
            @if ($tickets->isEmpty())
                <flux:text class="text-sm">{{ __('No one in this queue.') }}</flux:text>
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Ticket') }}</flux:table.column>
                        <flux:table.column>{{ __('Patient') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                        <flux:table.column>{{ __('Priority') }}</flux:table.column>
                        <flux:table.column>{{ __('Eligible') }}</flux:table.column>
                        <flux:table.column><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($tickets as $ticket)
                            <flux:table.row :key="'ticket-'.$ticket->id">
                                <flux:table.cell class="font-mono font-medium">{{ $ticket->ticket_number }}</flux:table.cell>
                                <flux:table.cell>{{ $ticket->visit->patient->last_name }}, {{ $ticket->visit->patient->first_name }}
                                    @if ($ticket->scheduling_preference === \App\Enums\SchedulingPreference::Appointment)<flux:badge size="sm" class="ms-1">{{ __('Appt') }}</flux:badge>@endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" :color="$ticket->status->color()">{{ $ticket->status->label() }}</flux:badge>
                                    @if ($ticket->counter && in_array($ticket->status, [\App\Enums\QueueTicketStatus::Called, \App\Enums\QueueTicketStatus::Serving], true))
                                        <span class="text-sm text-zinc-500">{{ $ticket->counter->name }}</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell><flux:badge size="sm" :color="$ticket->priority->color()">{{ $ticket->priority->label() }}</flux:badge></flux:table.cell>
                                <flux:table.cell class="tabular-nums">
                                    {{ $ticket->eligible_at->setTimezone($tz)->format('H:i') }}
                                    @if ($ticket->eligible_at->isFuture())<span class="text-xs text-zinc-500">({{ __('not yet') }})</span>@endif
                                </flux:table.cell>
                                <flux:table.cell align="end">
                                    @if ($ticket->status === \App\Enums\QueueTicketStatus::Skipped)
                                        @can('requeue', $ticket)
                                            <flux:button size="sm" variant="ghost" wire:click="requeue({{ $ticket->id }})">{{ __('Requeue') }}</flux:button>
                                        @endcan
                                    @endif
                                    @if ($ticket->status !== \App\Enums\QueueTicketStatus::Serving)
                                        @can('changePriority', $ticket)
                                            <flux:button size="sm" variant="ghost" wire:click="editTicket({{ $ticket->id }}, 'priority')">{{ __('Priority') }}</flux:button>
                                        @endcan
                                        @can('cancel', $ticket)
                                            <flux:button size="sm" variant="ghost" wire:click="editTicket({{ $ticket->id }}, 'cancel')">{{ __('Cancel') }}</flux:button>
                                        @endcan
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </section>
    @empty
        <div class="rounded-lg border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-600">
            <flux:text>{{ __('No services in your departments. Ask an administrator to assign you to a department.') }}</flux:text>
        </div>
    @endforelse
</div>
