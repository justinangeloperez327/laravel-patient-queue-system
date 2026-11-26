<div class="flex max-w-xl flex-col gap-6">
    <x-page-header :heading="$department === null ? __('Add department') : __('Edit :name', ['name' => $department->name])" />

    <form wire:submit="save" class="flex flex-col gap-6">
        <div class="grid gap-4 sm:grid-cols-3">
            <flux:input wire:model="code" :label="__('Code')" required class="font-mono" :description:trailing="__('e.g. OPD, LAB')" />
            <div class="sm:col-span-2">
                <flux:input wire:model="name" :label="__('Name')" required />
            </div>
        </div>
        <flux:textarea wire:model="description" :label="__('Description')" :badge="__('Optional')" rows="3" />
        <flux:switch wire:model="is_active" :label="__('Active')" :description="__('A department can be deactivated once its services and counters are inactive.')" />

        <div class="flex items-center gap-3">
            <flux:button variant="primary" type="submit">{{ __('Save department') }}</flux:button>
            <flux:button variant="ghost" :href="route('departments.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</div>
