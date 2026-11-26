@php($tz = \App\Support\ClinicTime::timezone())
@php($session = $this->session)
<div class="flex flex-col gap-6" wire:poll.5s>
    <x-page-header :heading="$session ? $session->counter->name : __('My counter')"
        :subheading="$session ? __('Serving :services', ['services' => $session->counter->services->pluck('queue_prefix')->sort()->implode(', ') ?: __('no services')]) : __('Choose the counter you are working at.')">
        @if ($session)
            <x-slot:actions>
                <flux:button wire:click="closeCounter" variant="ghost" icon="arrow-right-start-on-rectangle">{{ __('Close counter') }}</flux:button>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-status-message />
    @foreach (['counter', 'queue', 'ticket', 'service_id', 'idempotency_key'] as $field)
        @error($field) <flux:callout variant="danger" icon="x-circle" :heading="$message" /> @enderror
    @endforeach

    @if (! $session)
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @forelse ($this->counters as $counter)
                @php($staffedBy = $counter->sessions->first()?->user)
                <div class="flex flex-col gap-2 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" wire:key="counter-{{ $counter->id }}">
                    <flux:heading size="md" level="2">{{ $counter->name }}</flux:heading>
                    <flux:text class="text-sm">{{ $counter->services->pluck('queue_prefix')->sort()->implode(', ') ?: __('No services') }}@if ($counter->location) · {{ $counter->location }}@endif</flux:text>
                    @if ($staffedBy)
                        <flux:text class="text-sm">{{ __('Staffed by :name', ['name' => $staffedBy->name]) }}</flux:text>
                    @else
                        <div><flux:button size="sm" variant="primary" wire:click="openCounter({{ $counter->id }})">{{ __('Open :counter', ['counter' => $counter->name]) }}</flux:button></div>
                    @endif
                </div>
            @empty
                <flux:text>{{ __('No active counters in your departments.') }}</flux:text>
            @endforelse
        </div>
    @else
        @php($current = $this->current)
        <section class="rounded-lg border border-zinc-200 p-6 dark:border-zinc-700" aria-labelledby="current-heading" data-test="current-ticket">
            @if ($current)
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="font-mono text-4xl font-semibold" id="current-heading">{{ $current->ticket_number }}</div>
                        <div class="mt-1 text-lg">{{ $current->visit->patient->fullName() }}</div>
                        <flux:text class="text-sm">{{ $current->visit->visit_number }} · {{ __('Requested: :service', ['service' => $current->visit->requestedService->name]) }}</flux:text>
                    </div>
                    <div class="flex flex-col items-end gap-1">
                        <flux:badge :color="$current->status->color()">{{ $current->status->label() }}</flux:badge>
                        <flux:badge size="sm" :color="$current->priority->color()">{{ $current->priority->label() }}</flux:badge>
                        @if ($current->status === \App\Enums\QueueTicketStatus::Called)
                            <flux:text class="text-xs">{{ __('Recalls: :n of :max', ['n' => $current->recallCount(), 'max' => config('clinic.queue.max_recalls')]) }}</flux:text>
                        @endif
                    </div>
                </div>

                <div class="mt-6 flex flex-wrap gap-2">
                    @if ($current->status === \App\Enums\QueueTicketStatus::Called)
                        <flux:button variant="primary" wire:click="start" wire:loading.attr="disabled">{{ __('Start service') }}</flux:button>
                        <flux:button wire:click="recall" wire:loading.attr="disabled" icon="speaker-wave">{{ __('Recall') }}</flux:button>
                        <flux:button wire:click="skip" wire:loading.attr="disabled">{{ __('Skip (no-show)') }}</flux:button>
                    @else
                        @can('complete', $current)
                            <flux:button variant="primary" wire:click="complete" wire:loading.attr="disabled" icon="check">{{ __('Complete') }}</flux:button>
                        @endcan
                    @endif
                    @can('changePriority', $current)
                        @if ($current->status === \App\Enums\QueueTicketStatus::Called)
                            <flux:button variant="ghost" wire:click="editTicket({{ $current->id }}, 'priority')">{{ __('Priority') }}</flux:button>
                        @endif
                    @endcan
                </div>

                @if ($current->status === \App\Enums\QueueTicketStatus::Serving)
                    @can('transfer', $current)
                        <form wire:submit="transfer" class="mt-6 flex flex-wrap items-end gap-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                            <div class="w-72">
                                <flux:select wire:model="transferServiceId" :label="__('Transfer to')" required>
                                    <flux:select.option value="">{{ __('Choose the next service') }}</flux:select.option>
                                    @foreach ($this->transferTargets as $target)
                                        <flux:select.option :value="$target->id">{{ $target->queue_prefix }} — {{ $target->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>
                            <div class="min-w-48 flex-1">
                                <flux:input wire:model="transferRemarks" :label="__('Note')" :badge="__('Optional')" maxlength="255" />
                            </div>
                            <flux:button type="submit" wire:loading.attr="disabled">{{ __('Transfer') }}</flux:button>
                        </form>
                    @endcan
                @endif
            @else
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <flux:text>{{ __('No patient at the counter.') }}</flux:text>
                    <flux:button variant="primary" wire:click="callNext" wire:loading.attr="disabled" icon="megaphone">{{ __('Call next patient') }}</flux:button>
                </div>
            @endif
        </section>

        @if ($editingTicketId !== null && ($editing = \App\Models\QueueTicket::find($editingTicketId)))
            <x-ticket-edit-form :ticket="$editing" :mode="$editingMode" />
        @endif

        <section class="flex flex-col gap-2" aria-labelledby="next-heading">
            <flux:heading size="lg" level="2" id="next-heading">{{ __('Up next') }}</flux:heading>
            @forelse ($this->upNext as $ticket)
                <div class="flex items-center justify-between gap-3 border-b border-zinc-100 py-2 last:border-0 dark:border-zinc-800" wire:key="next-{{ $ticket->id }}">
                    <div><span class="font-mono font-medium">{{ $ticket->ticket_number }}</span> <span class="text-zinc-600 dark:text-zinc-300">{{ $ticket->visit->patient->last_name }}, {{ $ticket->visit->patient->first_name }}</span></div>
                    <div class="flex items-center gap-2">
                        @if ($ticket->priority !== \App\Enums\ClinicalPriority::Routine)<flux:badge size="sm" :color="$ticket->priority->color()">{{ $ticket->priority->label() }}</flux:badge>@endif
                        @if ($ticket->scheduling_preference === \App\Enums\SchedulingPreference::Appointment)<flux:badge size="sm">{{ __('Appt') }}</flux:badge>@endif
                        <span class="text-sm tabular-nums text-zinc-500">{{ $ticket->eligible_at->setTimezone($tz)->format('H:i') }}</span>
                    </div>
                </div>
            @empty
                <flux:text>{{ __('Nobody is waiting.') }}</flux:text>
            @endforelse
        </section>
    @endif
</div>
