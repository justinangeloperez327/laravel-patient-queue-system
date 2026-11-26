<div class="flex max-w-3xl flex-col gap-8">
    <x-page-header :heading="$staff === null ? __('Add staff member') : $staff->name" />

    <x-status-message />

    <form wire:submit="save" class="flex flex-col gap-6">
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="name" :label="__('Full name')" required autocomplete="off" />
            <flux:input wire:model="email" type="email" :label="__('Email')" required autocomplete="off" />
        </div>

        <flux:checkbox.group wire:model="roles" :label="__('Roles')" :description="__('Administrators have no clinical or reception access unless also given those roles.')">
            @foreach ($roleOptions as $role)
                <flux:checkbox :value="$role->value" :label="$role->label()" />
            @endforeach
        </flux:checkbox.group>

        <flux:checkbox.group wire:model="department_ids" :label="__('Departments')" :description="__('Staff only work queues and hold schedules in their assigned departments.')">
            @forelse ($this->departments as $department)
                <flux:checkbox :value="(string) $department->id" :label="$department->name.' ('.$department->code.')'" :description="$department->is_active ? null : __('Inactive')" />
            @empty
                <flux:text>{{ __('No active departments yet.') }}</flux:text>
            @endforelse
        </flux:checkbox.group>

        @if ($staff !== null)
            <flux:switch wire:model="is_active" :label="__('Active')" :description="__('Inactive staff cannot sign in and are signed out immediately.')" />
        @else
            <flux:callout icon="envelope" :heading="__('The new staff member will receive an email link to set their password.')" />
        @endif

        <div class="flex items-center gap-3">
            <flux:button variant="primary" type="submit">{{ $staff === null ? __('Create staff member') : __('Save changes') }}</flux:button>
            <flux:button variant="ghost" :href="route('staff.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>

    @if ($this->canManageSchedules)
        <flux:separator />
        <livewire:staff.provider-schedules :provider="$staff" />
        <flux:separator />
        <livewire:staff.schedule-exceptions :provider="$staff" />
    @endif
</div>
