@php($tz = \App\Support\ClinicTime::timezone())
<div class="flex flex-col gap-6">
    <x-page-header :heading="__('Appointments')" :subheading="__('Bookings for the day, in clinic time (:tz).', ['tz' => $tz])">
        <x-slot:actions>
            <flux:button variant="primary" icon="plus" :href="route('appointments.create')" wire:navigate>{{ __('Book appointment') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    <x-status-message />

    <div class="flex flex-wrap items-end gap-3">
        <div class="flex items-end gap-1">
            <flux:button icon="chevron-left" wire:click="shiftDay(-1)" :aria-label="__('Previous day')" />
            <flux:input type="date" wire:model.live="date" :label="__('Date')" />
            <flux:button icon="chevron-right" wire:click="shiftDay(1)" :aria-label="__('Next day')" />
        </div>
        <flux:button wire:click="today" variant="ghost">{{ __('Today') }}</flux:button>
        <div class="w-56">
            <flux:select wire:model.live="provider" :label="__('Provider')">
                <flux:select.option value="">{{ __('All providers') }}</flux:select.option>
                @foreach ($this->providers as $option)
                    <flux:select.option :value="$option->id">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <div class="w-56">
            <flux:select wire:model.live="service" :label="__('Service')">
                <flux:select.option value="">{{ __('All services') }}</flux:select.option>
                @foreach ($this->services as $option)
                    <flux:select.option :value="$option->id">{{ $option->queue_prefix }} — {{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    <flux:heading size="lg" level="2">{{ $this->day()->isoFormat('dddd, D MMMM YYYY') }}</flux:heading>

    @forelse ($this->appointmentsByProvider as $appointments)
        <section class="flex flex-col gap-2" wire:key="provider-{{ $appointments->first()->provider_id }}">
            <flux:heading size="md" level="3">{{ $appointments->first()->provider->name }}</flux:heading>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Time') }}</flux:table.column>
                    <flux:table.column>{{ __('Patient') }}</flux:table.column>
                    <flux:table.column>{{ __('Service') }}</flux:table.column>
                    <flux:table.column>{{ __('Number') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($appointments as $appointment)
                        <flux:table.row :key="$appointment->id">
                            <flux:table.cell class="tabular-nums">{{ $appointment->scheduled_start_at->setTimezone($tz)->format('H:i') }}–{{ $appointment->scheduled_end_at->setTimezone($tz)->format('H:i') }}</flux:table.cell>
                            <flux:table.cell variant="strong">{{ $appointment->patient->last_name }}, {{ $appointment->patient->first_name }}</flux:table.cell>
                            <flux:table.cell>{{ $appointment->service->queue_prefix }} — {{ $appointment->service->name }}</flux:table.cell>
                            <flux:table.cell class="font-mono">{{ $appointment->appointment_number }}</flux:table.cell>
                            <flux:table.cell><flux:badge size="sm" :color="$appointment->status->color()">{{ $appointment->status->label() }}</flux:badge></flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:button size="sm" variant="ghost" :href="route('appointments.show', $appointment)" wire:navigate>{{ __('Open') }}</flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </section>
    @empty
        <div class="rounded-lg border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-600">
            <flux:text>{{ __('No appointments on this day.') }}</flux:text>
            <div class="mt-3"><flux:button size="sm" :href="route('appointments.create')" wire:navigate>{{ __('Book appointment') }}</flux:button></div>
        </div>
    @endforelse
</div>
