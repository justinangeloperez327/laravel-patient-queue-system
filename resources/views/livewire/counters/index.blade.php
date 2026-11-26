<div class="flex flex-col gap-6">
    <x-page-header :heading="__('Counters')" :subheading="__('Desks, rooms and stations that call patients.')">
        <x-slot:actions>
            <flux:button variant="primary" icon="plus" :href="route('counters.create')" wire:navigate>{{ __('Add counter') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    <x-status-message />

    <flux:table :paginate="$this->counters">
        <flux:table.columns>
            <flux:table.column>{{ __('Code') }}</flux:table.column>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Department') }}</flux:table.column>
            <flux:table.column>{{ __('Services') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->counters as $counter)
                <flux:table.row :key="$counter->id">
                    <flux:table.cell class="font-mono">{{ $counter->code }}</flux:table.cell>
                    <flux:table.cell variant="strong">{{ $counter->name }}@if ($counter->location) <span class="font-normal text-zinc-500">· {{ $counter->location }}</span>@endif</flux:table.cell>
                    <flux:table.cell>{{ $counter->department->name }}</flux:table.cell>
                    <flux:table.cell>{{ $counter->services->pluck('queue_prefix')->sort()->implode(', ') ?: '—' }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$counter->is_active ? 'green' : 'zinc'">{{ $counter->is_active ? __('Active') : __('Inactive') }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:button size="sm" variant="ghost" :href="route('counters.edit', $counter)" wire:navigate>{{ __('Edit') }}</flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6"><div class="py-6 text-center"><flux:text>{{ __('No counters yet.') }}</flux:text></div></flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</div>
