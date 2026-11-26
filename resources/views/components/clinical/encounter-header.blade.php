@props(['ticket'])

<div class="flex flex-wrap items-start justify-between gap-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
    <div>
        <div class="font-mono text-2xl font-semibold">{{ $ticket->ticket_number }}</div>
        <div class="text-lg">{{ $ticket->visit->patient->fullName() }}</div>
        <flux:text class="text-sm">
            {{ $ticket->visit->patient->patient_number }} ·
            {{ __('Born :date (:age y)', ['date' => $ticket->visit->patient->date_of_birth->toDateString(), 'age' => $ticket->visit->patient->date_of_birth->age]) }}
            @if ($ticket->visit->patient->sex) · {{ $ticket->visit->patient->sex->label() }} @endif
        </flux:text>
        <flux:text class="text-sm">{{ $ticket->visit->visit_number }} · {{ __('Requested: :service', ['service' => $ticket->visit->requestedService->name]) }}</flux:text>
        @if ($ticket->visit->reason_for_visit)
            <flux:text class="text-sm">{{ __('Reason for visit: :reason', ['reason' => $ticket->visit->reason_for_visit]) }}</flux:text>
        @endif
    </div>
    <div class="flex flex-col items-end gap-2">
        <flux:badge :color="$ticket->priority->color()">{{ $ticket->priority->label() }}</flux:badge>
        <flux:link :href="route('patients.show', $ticket->visit->patient)" wire:navigate class="text-sm">{{ __('Full patient record') }}</flux:link>
    </div>
</div>
