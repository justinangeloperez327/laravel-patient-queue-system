<div class="flex max-w-2xl flex-col gap-6">
    <x-page-header :heading="$service === null ? __('Add service') : __('Edit :name', ['name' => $service->name])" />

    <form wire:submit="save" class="flex flex-col gap-6">
        <flux:select wire:model="department_id" :label="__('Department')" required>
            <flux:select.option value="">{{ __('Choose a department') }}</flux:select.option>
            @foreach ($this->departments as $department)
                <flux:select.option :value="$department->id">{{ $department->name }} ({{ $department->code }})</flux:select.option>
            @endforeach
        </flux:select>

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <flux:input wire:model="name" :label="__('Name')" required />
            </div>
            <flux:input wire:model="queue_prefix" :label="__('Queue prefix')" required maxlength="3" class="font-mono uppercase"
                :description:trailing="__('1–3 letters, e.g. C → C-001')" />
        </div>

        <flux:fieldset>
            <flux:legend>{{ __('Triage routing') }}</flux:legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model.live="triage_mode" :label="__('Triage')">
                    @foreach ($triageModes as $mode)
                        <flux:select.option :value="$mode->value">{{ $mode->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($triage_mode !== 'none')
                    <flux:select wire:model="triage_service_id" :label="__('Triage service')" required>
                        <flux:select.option value="">{{ __('Choose the triage service') }}</flux:select.option>
                        @foreach ($this->triageCandidates as $candidate)
                            <flux:select.option :value="$candidate->id">{{ $candidate->queue_prefix }} — {{ $candidate->name }} ({{ $candidate->department->code }})</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
            </div>
            <flux:description class="mt-2">{{ __('Patients for a service with triage are first queued at the triage service, then routed back after assessment. A triage service cannot require triage itself.') }}</flux:description>
        </flux:fieldset>

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="estimated_duration_minutes" type="number" min="1" max="480" :label="__('Estimated duration (minutes)')" required />
        </div>

        <flux:switch wire:model="allows_appointments" :label="__('Accepts appointments')" :description="__('Required for provider schedules and booking.')" />
        <flux:switch wire:model="is_active" :label="__('Active')" />

        <div class="flex items-center gap-3">
            <flux:button variant="primary" type="submit">{{ __('Save service') }}</flux:button>
            <flux:button variant="ghost" :href="route('services.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</div>
