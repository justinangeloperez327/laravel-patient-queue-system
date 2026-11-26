<div class="flex max-w-3xl flex-col gap-6">
    <x-page-header
        :heading="$patient === null ? __('Register patient') : __('Edit patient :number', ['number' => $patient->patient_number])"
        :subheading="__('Use synthetic data only until clinic compliance approval.')" />

    <form wire:submit="save" class="flex flex-col gap-6">
        <flux:fieldset>
            <flux:legend>{{ __('Identity') }}</flux:legend>
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="first_name" :label="__('First name')" required autocomplete="off" />
                <flux:input wire:model="middle_name" :label="__('Middle name')" :badge="__('Optional')" autocomplete="off" />
                <flux:input wire:model="last_name" :label="__('Last name')" required autocomplete="off" />
            </div>
            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="date_of_birth" type="date" :label="__('Date of birth')" required />
                <flux:select wire:model="sex" :label="__('Sex')" :badge="__('Optional')">
                    <flux:select.option value="">{{ __('Not recorded') }}</flux:select.option>
                    @foreach ($sexes as $option)
                        <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="nationality" :label="__('Nationality')" :badge="__('Optional')" />
            </div>
        </flux:fieldset>

        <flux:fieldset>
            <flux:legend>{{ __('Contact') }}</flux:legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="phone" type="tel" :label="__('Phone')" :badge="__('Optional')" placeholder="+1 555 0100" />
                <flux:input wire:model="email" type="email" :label="__('Email')" :badge="__('Optional')" />
            </div>
            <div class="mt-4">
                <flux:textarea wire:model="address" :label="__('Address')" :badge="__('Optional')" rows="2" />
            </div>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="emergency_contact_name" :label="__('Emergency contact name')" :badge="__('Optional')" />
                <flux:input wire:model="emergency_contact_phone" type="tel" :label="__('Emergency contact phone')" :badge="__('Optional')" placeholder="+1 555 0100" />
            </div>
        </flux:fieldset>

        @if ($patient !== null)
            <flux:switch wire:model="is_active" :label="__('Active')" :description="__('Inactive patients are hidden from search by default. Records are never deleted.')" />
        @endif

        @error('duplicate')
            <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Possible duplicate')">
                <flux:callout.text>{{ $message }}</flux:callout.text>
                <x-slot name="actions">
                    <flux:checkbox wire:model="confirm_duplicate" :label="__('This is a different patient — register anyway')" />
                </x-slot>
            </flux:callout>
        @enderror

        <div class="flex items-center gap-3">
            <flux:button variant="primary" type="submit">{{ $patient === null ? __('Register patient') : __('Save changes') }}</flux:button>
            <flux:button variant="ghost" :href="route('patients.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</div>
