<section class="flex flex-col gap-4" aria-labelledby="schedules-heading">
    <div>
        <flux:heading size="lg" level="2" id="schedules-heading">{{ __('Weekly schedule') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Recurring availability for appointments, in clinic time (:tz).', ['tz' => \App\Support\ClinicTime::timezone()]) }}</flux:text>
    </div>

    @error('provider') <flux:callout variant="danger" icon="x-circle" :heading="$message" /> @enderror

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Day') }}</flux:table.column>
            <flux:table.column>{{ __('Time') }}</flux:table.column>
            <flux:table.column>{{ __('Service') }}</flux:table.column>
            <flux:table.column>{{ __('Slot') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->schedules as $schedule)
                <flux:table.row :key="$schedule->id">
                    <flux:table.cell>{{ $schedule->day_of_week->label() }}</flux:table.cell>
                    <flux:table.cell class="tabular-nums">{{ $schedule->timeRangeLabel() }}</flux:table.cell>
                    <flux:table.cell>{{ $schedule->service->queue_prefix }} — {{ $schedule->service->name }}</flux:table.cell>
                    <flux:table.cell>{{ __(':min min', ['min' => $schedule->slot_duration_minutes]) }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$schedule->is_active ? 'green' : 'zinc'">{{ $schedule->is_active ? __('Active') : __('Inactive') }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:button size="sm" variant="ghost" wire:click="edit({{ $schedule->id }})">{{ __('Edit') }}</flux:button>
                        <flux:button size="sm" variant="ghost" wire:click="toggleActive({{ $schedule->id }})">{{ $schedule->is_active ? __('Deactivate') : __('Activate') }}</flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6"><div class="py-4 text-center"><flux:text>{{ __('No schedule blocks yet.') }}</flux:text></div></flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <form wire:submit="save" class="flex flex-col gap-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
        <flux:heading size="md" level="3">{{ $editingId === null ? __('Add schedule block') : __('Edit schedule block') }}</flux:heading>

        @if ($this->eligibleServices->isEmpty())
            <flux:text>{{ __('No appointable services in this provider\'s departments. Assign a department and enable appointments on a service first.') }}</flux:text>
        @else
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="service_id" :label="__('Service')" required>
                    <flux:select.option value="">{{ __('Choose a service') }}</flux:select.option>
                    @foreach ($this->eligibleServices as $service)
                        <flux:select.option :value="$service->id">{{ $service->queue_prefix }} — {{ $service->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="day_of_week" :label="__('Day')">
                    @foreach ($days as $day)
                        <flux:select.option :value="$day->value">{{ $day->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="start_time" type="time" :label="__('Start')" required />
                <flux:input wire:model="end_time" type="time" :label="__('End')" required />
                <flux:input wire:model="slot_duration_minutes" type="number" min="5" max="240" :label="__('Slot (minutes)')" required />
            </div>
            <flux:switch wire:model="is_active" :label="__('Active')" />
            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit">{{ $editingId === null ? __('Add block') : __('Save block') }}</flux:button>
                @if ($editingId !== null)
                    <flux:button variant="ghost" wire:click="cancelEdit">{{ __('Cancel') }}</flux:button>
                @endif
            </div>
        @endif
    </form>
</section>
