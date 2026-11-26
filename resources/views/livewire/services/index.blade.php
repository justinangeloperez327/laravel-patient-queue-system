<div class="flex flex-col gap-6">
    <x-page-header :heading="__('Services')" :subheading="__('Each service issues its own daily ticket series using its queue prefix.')">
        <x-slot:actions>
            <flux:button variant="primary" icon="plus" :href="route('services.create')" wire:navigate>{{ __('Add service') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    <x-status-message />

    <flux:table :paginate="$this->services">
        <flux:table.columns>
            <flux:table.column>{{ __('Prefix') }}</flux:table.column>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Department') }}</flux:table.column>
            <flux:table.column>{{ __('Triage') }}</flux:table.column>
            <flux:table.column>{{ __('Appointments') }}</flux:table.column>
            <flux:table.column>{{ __('Est. minutes') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->services as $service)
                <flux:table.row :key="$service->id">
                    <flux:table.cell class="font-mono">{{ $service->queue_prefix }}</flux:table.cell>
                    <flux:table.cell variant="strong">{{ $service->name }}</flux:table.cell>
                    <flux:table.cell>{{ $service->department->name }}</flux:table.cell>
                    <flux:table.cell>
                        {{ $service->triage_mode->label() }}
                        @if ($service->triageService)
                            <span class="text-zinc-500">→ {{ $service->triageService->queue_prefix }}</span>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $service->allows_appointments ? __('Yes') : __('No') }}</flux:table.cell>
                    <flux:table.cell>{{ $service->estimated_duration_minutes }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$service->is_active ? 'green' : 'zinc'">{{ $service->is_active ? __('Active') : __('Inactive') }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:button size="sm" variant="ghost" :href="route('services.edit', $service)" wire:navigate>{{ __('Edit') }}</flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="8"><div class="py-6 text-center"><flux:text>{{ __('No services yet.') }}</flux:text></div></flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</div>
