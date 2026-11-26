<div class="flex max-w-2xl flex-col gap-6">
    <x-page-header :heading="$counter === null ? __('Add counter') : __('Edit :code', ['code' => $counter->code])" />

    <form wire:submit="save" class="flex flex-col gap-6">
        <flux:select wire:model.live="department_id" :label="__('Department')" required>
            <flux:select.option value="">{{ __('Choose a department') }}</flux:select.option>
            @foreach ($this->departments as $department)
                <flux:select.option :value="$department->id">{{ $department->name }} ({{ $department->code }})</flux:select.option>
            @endforeach
        </flux:select>

        <div class="grid gap-4 sm:grid-cols-3">
            <flux:input wire:model="code" :label="__('Code')" required class="font-mono" :description:trailing="__('e.g. ROOM-1')" />
            <div class="sm:col-span-2">
                <flux:input wire:model="name" :label="__('Name')" required :description:trailing="__('Shown on the public display, e.g. Room 1')" />
            </div>
        </div>
        <flux:input wire:model="location" :label="__('Location')" :badge="__('Optional')" />

        <flux:checkbox.group wire:model="service_ids" :label="__('Services served')">
            @forelse ($this->availableServices as $service)
                <flux:checkbox :value="(string) $service->id" :label="$service->queue_prefix.' — '.$service->name" :description="$service->is_active ? null : __('Inactive')" />
            @empty
                <flux:text>{{ $department_id === '' ? __('Choose a department to see its services.') : __('This department has no active services yet.') }}</flux:text>
            @endforelse
        </flux:checkbox.group>

        <flux:switch wire:model="is_active" :label="__('Active')" />

        <div class="flex items-center gap-3">
            <flux:button variant="primary" type="submit">{{ __('Save counter') }}</flux:button>
            <flux:button variant="ghost" :href="route('counters.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</div>
