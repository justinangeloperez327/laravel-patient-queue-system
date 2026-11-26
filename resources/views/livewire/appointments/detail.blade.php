@php($tz = \App\Support\ClinicTime::timezone())
@php($status = $appointment->status)
<div class="flex max-w-3xl flex-col gap-6">
    <x-page-header :heading="__('Appointment :number', ['number' => $appointment->appointment_number])">
        <x-slot:actions>
            <flux:badge :color="$status->color()">{{ $status->label() }}</flux:badge>
        </x-slot:actions>
    </x-page-header>

    <x-status-message />
    @error('status') <flux:callout variant="danger" icon="x-circle" :heading="$message" /> @enderror
    @error('appointment') <flux:callout variant="danger" icon="x-circle" :heading="$message" /> @enderror
    @error('patient_id') <flux:callout variant="danger" icon="x-circle" :heading="$message" /> @enderror

    <dl class="grid gap-4 rounded-lg border border-zinc-200 p-4 sm:grid-cols-2 dark:border-zinc-700">
        <div><dt class="text-sm text-zinc-500">{{ __('Patient') }}</dt><dd class="font-medium">{{ $appointment->patient->fullName() }} <span class="font-mono text-sm text-zinc-500">{{ $appointment->patient->patient_number }}</span></dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('When') }}</dt><dd class="font-medium tabular-nums">{{ $appointment->scheduled_start_at->setTimezone($tz)->isoFormat('ddd D MMM YYYY, HH:mm') }}–{{ $appointment->scheduled_end_at->setTimezone($tz)->format('H:i') }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Service') }}</dt><dd>{{ $appointment->service->queue_prefix }} — {{ $appointment->service->name }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Provider') }}</dt><dd>{{ $appointment->provider->name }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Reason for visit') }}</dt><dd>{{ $appointment->reason_for_visit ?? '—' }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Booked by') }}</dt><dd>{{ $appointment->bookedBy->name }}</dd></div>
        @if ($appointment->visit)
            <div><dt class="text-sm text-zinc-500">{{ __('Visit') }}</dt><dd class="font-mono">{{ $appointment->visit->visit_number }} · {{ $appointment->visit->status->label() }}</dd></div>
        @endif
        @if ($appointment->cancellation_reason)
            <div><dt class="text-sm text-zinc-500">{{ __('Cancellation reason') }}</dt><dd>{{ $appointment->cancellation_reason }}</dd></div>
        @endif
    </dl>

    @if ($status->isPending())
        <div class="flex flex-wrap items-center gap-2">
            @can('checkIn', $appointment)
                @if ($appointment->localDate()->equalTo(\App\Support\ClinicTime::today()))
                    <flux:button variant="primary" wire:click="checkIn" icon="check">{{ __('Check in') }}</flux:button>
                @endif
            @endcan
            @can('update', $appointment)
                @if ($status === \App\Enums\AppointmentStatus::Scheduled)
                    <flux:button wire:click="confirm">{{ __('Confirm') }}</flux:button>
                @endif
                <flux:button wire:click="startRescheduling" icon="calendar">{{ __('Reschedule') }}</flux:button>
                @if (now()->gt($appointment->checkInDeadline()))
                    <flux:button wire:click="markNoShow" wire:confirm="{{ __('Record :number as a no-show?', ['number' => $appointment->appointment_number]) }}">{{ __('Record no-show') }}</flux:button>
                @endif
            @endcan
        </div>
        <flux:text class="text-sm">{{ __('Check-in is possible on the day until :time (start + :minutes min grace).', ['time' => $appointment->checkInDeadline()->setTimezone($tz)->format('H:i'), 'minutes' => \App\Models\Appointment::lateGraceMinutes()]) }}</flux:text>

        @if ($rescheduling)
            <form wire:submit="reschedule" class="flex flex-col gap-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:heading size="md" level="2">{{ __('Reschedule') }}</flux:heading>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model.live="provider_id" :label="__('Provider')">
                        @foreach ($this->providerOptions as $option)
                            <flux:select.option :value="$option->id">{{ $option->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input type="date" wire:model.live="slotDate" :label="__('Date')" :min="\App\Support\ClinicTime::today()->toDateString()" />
                </div>
                <flux:error name="provider_id" />
                <x-appointment-slots :slots="$this->availableSlots" :selected="$scheduled_start_at" :ready="$provider_id !== ''" />
                <div class="flex items-center gap-3">
                    <flux:button variant="primary" type="submit">{{ __('Move appointment') }}</flux:button>
                    <flux:button variant="ghost" wire:click="$set('rescheduling', false)">{{ __('Keep current time') }}</flux:button>
                </div>
            </form>
        @endif

        @can('update', $appointment)
            <form wire:submit="cancel" class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:heading size="md" level="2">{{ __('Cancel appointment') }}</flux:heading>
                <flux:input wire:model="cancellation_reason" :label="__('Reason')" required maxlength="255" :description:trailing="__('e.g. “Patient requested”. Avoid clinical details.')" />
                <div><flux:button variant="danger" type="submit">{{ __('Cancel :number', ['number' => $appointment->appointment_number]) }}</flux:button></div>
            </form>
        @endcan
    @endif

    <section class="flex flex-col gap-2">
        <flux:heading size="md" level="2">{{ __('History') }}</flux:heading>
        <ol class="flex flex-col gap-1 text-sm">
            @foreach ($this->history as $entry)
                <li wire:key="audit-{{ $entry->id }}" class="tabular-nums">
                    <span class="text-zinc-500">{{ \App\Support\ClinicTime::toLocalDisplay($entry->created_at) }}</span>
                    — {{ str($entry->action)->after('appointment.')->replace('_', ' ')->ucfirst() }}
                    {{ __('by :name', ['name' => $entry->actor?->name ?? __('system')]) }}
                    @if ($entry->action === 'appointment.rescheduled')
                        <span class="text-zinc-500">({{ \App\Support\ClinicTime::toLocalDisplay(\Carbon\CarbonImmutable::parse($entry->metadata['from']['scheduled_start_at'])) }} → {{ \App\Support\ClinicTime::toLocalDisplay(\Carbon\CarbonImmutable::parse($entry->metadata['to']['scheduled_start_at'])) }})</span>
                    @endif
                </li>
            @endforeach
        </ol>
    </section>
</div>
