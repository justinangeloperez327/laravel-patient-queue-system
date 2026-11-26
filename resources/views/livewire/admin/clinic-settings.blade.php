<div class="flex max-w-2xl flex-col gap-6">
    <x-page-header :heading="__('Clinic settings')" :subheading="__('Changes apply immediately and are recorded in the audit log.')" />
    <x-status-message />

    <form wire:submit="save" class="flex flex-col gap-6">
        <flux:fieldset>
            <flux:legend>{{ __('Time') }}</flux:legend>
            <flux:select wire:model="values.clinic__timezone" :label="__('Clinic timezone')" :description="__('Sets the operational day: daily ticket numbers, appointment days and reports. Change only outside opening hours.')">
                @foreach ($timezones as $timezone)
                    <flux:select.option :value="$timezone">{{ $timezone }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="values.clinic__timezone" />
        </flux:fieldset>

        <flux:fieldset>
            <flux:legend>{{ __('Appointments and queue') }}</flux:legend>
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach (['clinic.appointments.late_grace_minutes', 'clinic.appointments.early_eligibility_minutes', 'clinic.appointments.booking_horizon_days', 'clinic.queue.walk_in_safeguard_minutes', 'clinic.queue.max_recalls'] as $key)
                    @php($field = str_replace('.', '__', $key))
                    <div>
                        <flux:input type="number" wire:model="values.{{ $field }}" :label="__($definitions[$key]['label'])" />
                        <flux:error name="values.{{ $field }}" />
                    </div>
                @endforeach
            </div>
            <flux:description class="mt-2">{{ __('Defaults are proposals from the queue engine documentation and must be approved by the clinic.') }}</flux:description>
        </flux:fieldset>

        <flux:fieldset>
            <flux:legend>{{ __('Security') }}</flux:legend>
            <flux:switch wire:model="values.clinic__security__require_two_factor" :label="__('Require two-factor authentication')"
                :description="__('Staff without two-factor authentication can only open their security settings until they set it up.')" />
        </flux:fieldset>

        <div><flux:button variant="primary" type="submit">{{ __('Save settings') }}</flux:button></div>
    </form>
</div>
