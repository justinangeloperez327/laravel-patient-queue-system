@php($tz = \App\Support\ClinicTime::timezone())
<div class="flex flex-col gap-8">
    <x-page-header :heading="__('Reception')" :subheading="\App\Support\ClinicTime::today()->isoFormat('dddd, D MMMM YYYY')">
        <x-slot:actions>
            @can('create', \App\Models\Patient::class)
                <flux:button icon="user-plus" :href="route('patients.create')" wire:navigate>{{ __('Register patient') }}</flux:button>
            @endcan
            @can('create', \App\Models\Appointment::class)
                <flux:button icon="calendar" :href="route('appointments.create')" wire:navigate>{{ __('Book appointment') }}</flux:button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <x-status-message />
    @error('appointment') <flux:callout variant="danger" icon="x-circle" :heading="$message" /> @enderror

    <section class="flex flex-col gap-3" aria-labelledby="todays-appointments">
        <flux:heading size="lg" level="2" id="todays-appointments">{{ __("Today's appointments") }}</flux:heading>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Time') }}</flux:table.column>
                <flux:table.column>{{ __('Patient') }}</flux:table.column>
                <flux:table.column>{{ __('Service') }}</flux:table.column>
                <flux:table.column>{{ __('Provider') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->todaysAppointments as $appointment)
                    <flux:table.row :key="'apt-'.$appointment->id">
                        <flux:table.cell class="tabular-nums">{{ $appointment->scheduled_start_at->setTimezone($tz)->format('H:i') }}</flux:table.cell>
                        <flux:table.cell variant="strong">{{ $appointment->patient->last_name }}, {{ $appointment->patient->first_name }} <span class="font-mono text-xs font-normal text-zinc-500">{{ $appointment->patient->patient_number }}</span></flux:table.cell>
                        <flux:table.cell>{{ $appointment->service->queue_prefix }} — {{ $appointment->service->name }}</flux:table.cell>
                        <flux:table.cell>{{ $appointment->provider->name }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$appointment->status->color()">{{ $appointment->status->label() }}</flux:badge></flux:table.cell>
                        <flux:table.cell align="end">
                            @if ($appointment->status->isPending())
                                @if (now()->lte($appointment->checkInDeadline()))
                                    <flux:button size="sm" variant="primary" wire:click="checkIn({{ $appointment->id }})">{{ __('Check in') }}</flux:button>
                                @else
                                    <flux:badge size="sm" color="red">{{ __('Past grace') }}</flux:badge>
                                @endif
                            @endif
                            <flux:button size="sm" variant="ghost" :href="route('appointments.show', $appointment)" wire:navigate>{{ __('Open') }}</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6"><div class="py-4 text-center"><flux:text>{{ __('No appointments today.') }}</flux:text></div></flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>

    <section class="flex flex-col gap-3" aria-labelledby="walk-in">
        <flux:heading size="lg" level="2" id="walk-in">{{ __('Walk-in check-in') }}</flux:heading>
        <form wire:submit="registerWalkIn" class="flex max-w-2xl flex-col gap-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <x-patient-picker :selected="$this->selectedPatient" :results="$this->patientResults" :search="$patientSearch" />
            <flux:select wire:model="service_id" :label="__('Requested service')" required>
                <flux:select.option value="">{{ __('Choose a service') }}</flux:select.option>
                @foreach ($this->services as $option)
                    <flux:select.option :value="$option->id">{{ $option->queue_prefix }} — {{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="reason_for_visit" :label="__('Reason for visit')" :badge="__('Optional')" maxlength="1000" />
            <div><flux:button variant="primary" type="submit">{{ __('Check in walk-in') }}</flux:button></div>
        </form>
    </section>

    <section class="flex flex-col gap-3" aria-labelledby="open-visits">
        <flux:heading size="lg" level="2" id="open-visits">{{ __('Checked in now') }}</flux:heading>
        @if ($closingVisitId !== null && ($closing = $this->openVisits->firstWhere('id', $closingVisitId)))
            <x-close-visit-form :visit="$closing" :outcome="$closingOutcome" />
        @endif
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Visit') }}</flux:table.column>
                <flux:table.column>{{ __('Patient') }}</flux:table.column>
                <flux:table.column>{{ __('Service') }}</flux:table.column>
                <flux:table.column>{{ __('Type') }}</flux:table.column>
                <flux:table.column>{{ __('Checked in') }}</flux:table.column>
                <flux:table.column><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->openVisits as $visit)
                    <flux:table.row :key="'visit-'.$visit->id">
                        <flux:table.cell class="font-mono">{{ $visit->visit_number }}</flux:table.cell>
                        <flux:table.cell variant="strong">{{ $visit->patient->last_name }}, {{ $visit->patient->first_name }}</flux:table.cell>
                        <flux:table.cell>{{ $visit->requestedService->queue_prefix }} — {{ $visit->requestedService->name }}</flux:table.cell>
                        <flux:table.cell>{{ $visit->visit_type->label() }}</flux:table.cell>
                        <flux:table.cell class="tabular-nums">{{ $visit->checked_in_at->setTimezone($tz)->format('H:i') }}</flux:table.cell>
                        <flux:table.cell align="end">
                            @if ($visit->status === \App\Enums\VisitStatus::CheckedIn)
                                <flux:button size="sm" variant="ghost" wire:click="startClosing({{ $visit->id }}, 'left_without_service')">{{ __('Left without service') }}</flux:button>
                                <flux:button size="sm" variant="ghost" wire:click="startClosing({{ $visit->id }}, 'cancelled')">{{ __('Cancel') }}</flux:button>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6"><div class="py-4 text-center"><flux:text>{{ __('Nobody is checked in.') }}</flux:text></div></flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>
</div>
