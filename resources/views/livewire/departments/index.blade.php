<div class="flex flex-col gap-6">
    <x-page-header :heading="__('Departments')">
        <x-slot:actions>
            <flux:button variant="primary" icon="plus" :href="route('departments.create')" wire:navigate>{{ __('Add department') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    <x-status-message />

    <flux:table :paginate="$this->departments">
        <flux:table.columns>
            <flux:table.column>{{ __('Code') }}</flux:table.column>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Services') }}</flux:table.column>
            <flux:table.column>{{ __('Counters') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->departments as $department)
                <flux:table.row :key="$department->id">
                    <flux:table.cell class="font-mono">{{ $department->code }}</flux:table.cell>
                    <flux:table.cell variant="strong">{{ $department->name }}</flux:table.cell>
                    <flux:table.cell>{{ $department->services_count }}</flux:table.cell>
                    <flux:table.cell>{{ $department->counters_count }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$department->is_active ? 'green' : 'zinc'">{{ $department->is_active ? __('Active') : __('Inactive') }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:button size="sm" variant="ghost" :href="route('departments.edit', $department)" wire:navigate>{{ __('Edit') }}</flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6"><div class="py-6 text-center"><flux:text>{{ __('No departments yet. Add one to start configuring services and counters.') }}</flux:text></div></flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</div>
