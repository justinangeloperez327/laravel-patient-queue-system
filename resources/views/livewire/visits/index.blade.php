@php($tz = \App\Support\ClinicTime::timezone())
<div class="flex flex-col gap-6">
    <x-page-header :heading="__('Visits')" :subheading="__('Check-ins by clinic day.')" />

    <x-status-message />

    <div class="flex flex-wrap items-end gap-4">
        <flux:input type="date" wire:model.live="date" :label="__('Date')" />
        <div class="w-56">
            <flux:select wire:model.live="status" :label="__('Status')">
                <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
                @foreach ($statuses as $option)
                    <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    @if ($closingVisitId !== null && ($closing = $this->visits->firstWhere('id', $closingVisitId)))
        <x-close-visit-form :visit="$closing" :outcome="$closingOutcome" />
    @endif

    <flux:table :paginate="$this->visits">
        <flux:table.columns>
            <flux:table.column>{{ __('Visit') }}</flux:table.column>
            <flux:table.column>{{ __('Checked in') }}</flux:table.column>
            <flux:table.column>{{ __('Patient') }}</flux:table.column>
            <flux:table.column>{{ __('Service') }}</flux:table.column>
            <flux:table.column>{{ __('Type') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->visits as $visit)
                <flux:table.row :key="$visit->id">
                    <flux:table.cell class="font-mono">{{ $visit->visit_number }}</flux:table.cell>
                    <flux:table.cell class="tabular-nums">{{ $visit->checked_in_at->setTimezone($tz)->format('H:i') }}</flux:table.cell>
                    <flux:table.cell variant="strong">{{ $visit->patient->last_name }}, {{ $visit->patient->first_name }}</flux:table.cell>
                    <flux:table.cell>{{ $visit->requestedService->queue_prefix }} — {{ $visit->requestedService->name }}</flux:table.cell>
                    <flux:table.cell>
                        {{ $visit->visit_type->label() }}
                        @if ($visit->appointment)
                            <flux:link class="font-mono text-xs" :href="route('appointments.show', $visit->appointment)" wire:navigate>{{ $visit->appointment->appointment_number }}</flux:link>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell><flux:badge size="sm" :color="$visit->status->color()">{{ $visit->status->label() }}</flux:badge></flux:table.cell>
                    <flux:table.cell align="end">
                        @if ($visit->status === \App\Enums\VisitStatus::CheckedIn)
                            <flux:button size="sm" variant="ghost" wire:click="startClosing({{ $visit->id }}, 'left_without_service')">{{ __('Left without service') }}</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="startClosing({{ $visit->id }}, 'cancelled')">{{ __('Cancel') }}</flux:button>
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7"><div class="py-6 text-center"><flux:text>{{ __('No visits on this day.') }}</flux:text></div></flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</div>
