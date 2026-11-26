<div class="flex flex-col gap-6">
    <x-page-header :heading="__('Patients')" :subheading="__('Search registered patients or register a new one.')">
        <x-slot:actions>
            @can('create', \App\Models\Patient::class)
                <flux:button variant="primary" icon="plus" :href="route('patients.create')" wire:navigate>{{ __('Register patient') }}</flux:button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <x-status-message />

    <div class="flex flex-wrap items-end gap-4">
        <div class="w-full max-w-md">
            <flux:input wire:model.live.debounce.300ms="search" :label="__('Search')" icon="magnifying-glass"
                :description:trailing="__('Patient number, name, phone or date of birth (YYYY-MM-DD)')" />
        </div>
        <flux:checkbox wire:model.live="includeInactive" :label="__('Include inactive')" />
    </div>

    <flux:table :paginate="$this->patients">
        <flux:table.columns>
            <flux:table.column>{{ __('Patient no.') }}</flux:table.column>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Date of birth') }}</flux:table.column>
            <flux:table.column>{{ __('Phone') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->patients as $patient)
                <flux:table.row :key="$patient->id">
                    <flux:table.cell class="font-mono">{{ $patient->patient_number }}</flux:table.cell>
                    <flux:table.cell variant="strong">{{ $patient->last_name }}, {{ $patient->first_name }} {{ $patient->middle_name }}</flux:table.cell>
                    <flux:table.cell>{{ $patient->date_of_birth->toDateString() }}</flux:table.cell>
                    <flux:table.cell>{{ $patient->phone ?? '—' }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$patient->is_active ? 'green' : 'zinc'">{{ $patient->is_active ? __('Active') : __('Inactive') }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:button size="sm" variant="ghost" :href="route('patients.show', $patient)" wire:navigate>{{ __('Open') }}</flux:button>
                        @can('update', $patient)
                            <flux:button size="sm" variant="ghost" :href="route('patients.edit', $patient)" wire:navigate>{{ __('Edit') }}</flux:button>
                        @endcan
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6">
                        <div class="py-6 text-center">
                            <flux:text>{{ $search === '' ? __('No patients registered yet.') : __('No patients match your search.') }}</flux:text>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</div>
