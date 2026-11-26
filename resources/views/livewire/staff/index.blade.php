<div class="flex flex-col gap-6">
    <x-page-header :heading="__('Staff')" :subheading="__('Accounts, roles and department assignments.')">
        <x-slot:actions>
            <flux:button variant="primary" icon="plus" :href="route('staff.create')" wire:navigate>{{ __('Add staff member') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    <x-status-message />

    <div class="w-full max-w-md">
        <flux:input wire:model.live.debounce.300ms="search" :label="__('Search')" icon="magnifying-glass" :placeholder="__('Name or email')" />
    </div>

    <flux:table :paginate="$this->staff">
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Email') }}</flux:table.column>
            <flux:table.column>{{ __('Roles') }}</flux:table.column>
            <flux:table.column>{{ __('Departments') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->staff as $member)
                <flux:table.row :key="$member->id">
                    <flux:table.cell variant="strong">{{ $member->name }}</flux:table.cell>
                    <flux:table.cell>{{ $member->email }}</flux:table.cell>
                    <flux:table.cell>
                        <div class="flex flex-wrap gap-1">
                            @foreach ($member->roles as $role)
                                <flux:badge size="sm">{{ \App\Enums\Role::tryFrom($role->name)?->label() ?? $role->name }}</flux:badge>
                            @endforeach
                        </div>
                    </flux:table.cell>
                    <flux:table.cell>{{ $member->departments->pluck('code')->sort()->implode(', ') ?: '—' }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$member->is_active ? 'green' : 'zinc'">{{ $member->is_active ? __('Active') : __('Inactive') }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:button size="sm" variant="ghost" :href="route('staff.edit', $member)" wire:navigate>{{ __('Edit') }}</flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6"><div class="py-6 text-center"><flux:text>{{ __('No staff match your search.') }}</flux:text></div></flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</div>
