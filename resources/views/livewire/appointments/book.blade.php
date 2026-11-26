<div class="flex max-w-3xl flex-col gap-6">
    <x-page-header :heading="__('Book appointment')" :subheading="__('Times are clinic time (:tz).', ['tz' => \App\Support\ClinicTime::timezone()])" />

    <form wire:submit="save" class="flex flex-col gap-6">
        <x-patient-picker :selected="$this->selectedPatient" :results="$this->patientResults" :search="$patientSearch" />

        <div class="grid gap-4 sm:grid-cols-3">
            <flux:select wire:model.live="service_id" :label="__('Service')" required>
                <flux:select.option value="">{{ __('Choose a service') }}</flux:select.option>
                @foreach ($this->serviceOptions as $option)
                    <flux:select.option :value="$option->id">{{ $option->queue_prefix }} — {{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="provider_id" :label="__('Provider')" required :disabled="$service_id === ''">
                <flux:select.option value="">{{ __('Choose a provider') }}</flux:select.option>
                @foreach ($this->providerOptions as $option)
                    <flux:select.option :value="$option->id">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input type="date" wire:model.live="slotDate" :label="__('Date')" :min="\App\Support\ClinicTime::today()->toDateString()" required />
        </div>
        @if ($service_id !== '' && $this->providerOptions->isEmpty())
            <flux:text>{{ __('No provider has a schedule for this service yet.') }}</flux:text>
        @endif
        <flux:error name="service_id" />
        <flux:error name="provider_id" />

        <x-appointment-slots :slots="$this->availableSlots" :selected="$scheduled_start_at" :ready="$service_id !== '' && $provider_id !== ''" />

        <flux:textarea wire:model="reason_for_visit" :label="__('Reason for visit')" :badge="__('Optional')" rows="2"
            :description:trailing="__('Brief, non-clinical description, e.g. “Follow-up”.')" />

        <div class="flex items-center gap-3">
            <flux:button variant="primary" type="submit">{{ __('Book appointment') }}</flux:button>
            <flux:button variant="ghost" :href="route('appointments.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</div>
