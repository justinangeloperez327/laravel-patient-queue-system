<div class="flex flex-col gap-6">
    <x-page-header :heading="__('Displays')" :subheading="__('Waiting-room screens. They show ticket numbers and rooms only.')">
        <x-slot:actions>
            <flux:button variant="primary" icon="plus" :href="route('displays.create')" wire:navigate>{{ __('Add display') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    <x-status-message />

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Services') }}</flux:table.column>
            <flux:table.column>{{ __('Last seen') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->devices as $device)
                <flux:table.row :key="$device->id">
                    <flux:table.cell variant="strong">{{ $device->name }}</flux:table.cell>
                    <flux:table.cell>{{ collect($device->serviceIds())->map(fn ($id) => $this->services[$id]->queue_prefix ?? '?')->sort()->implode(', ') }}</flux:table.cell>
                    <flux:table.cell class="tabular-nums">{{ $device->last_seen_at ? \App\Support\ClinicTime::toLocalDisplay($device->last_seen_at) : __('Never') }}</flux:table.cell>
                    <flux:table.cell><flux:badge size="sm" :color="$device->is_active ? 'green' : 'zinc'">{{ $device->is_active ? __('Active') : __('Inactive') }}</flux:badge></flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:button size="sm" variant="ghost" :href="route('displays.edit', $device)" wire:navigate>{{ __('Edit') }}</flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5"><div class="py-6 text-center"><flux:text>{{ __('No displays yet.') }}</flux:text></div></flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</div>
