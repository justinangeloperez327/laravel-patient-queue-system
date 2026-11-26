<section class="flex flex-col gap-4" aria-labelledby="exceptions-heading">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="lg" level="2" id="exceptions-heading">{{ __('Unavailability') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Leave or other periods when this provider cannot be booked.') }}</flux:text>
        </div>
        <flux:checkbox wire:model.live="showPast" :label="__('Show past')" />
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('From') }}</flux:table.column>
            <flux:table.column>{{ __('Until') }}</flux:table.column>
            <flux:table.column>{{ __('Reason') }}</flux:table.column>
            <flux:table.column><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->exceptions as $exception)
                <flux:table.row :key="$exception->id">
                    <flux:table.cell class="tabular-nums">{{ \App\Support\ClinicTime::toLocalDisplay($exception->starts_at) }}</flux:table.cell>
                    <flux:table.cell class="tabular-nums">{{ \App\Support\ClinicTime::toLocalDisplay($exception->ends_at) }}</flux:table.cell>
                    <flux:table.cell>{{ $exception->reason }}</flux:table.cell>
                    <flux:table.cell align="end">
                        @can('delete', $exception)
                            <flux:button size="sm" variant="ghost" wire:click="delete({{ $exception->id }})"
                                wire:confirm="{{ __('Remove the unavailability period from :from?', ['from' => \App\Support\ClinicTime::toLocalDisplay($exception->starts_at)]) }}">{{ __('Remove') }}</flux:button>
                        @endcan
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="4"><div class="py-4 text-center"><flux:text>{{ __('No upcoming unavailability.') }}</flux:text></div></flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <form wire:submit="save" class="flex flex-col gap-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
        <flux:heading size="md" level="3">{{ __('Add unavailability') }}</flux:heading>
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="starts_at" type="datetime-local" :label="__('From')" required />
            <flux:input wire:model="ends_at" type="datetime-local" :label="__('Until')" required />
        </div>
        <flux:input wire:model="reason" :label="__('Reason')" required maxlength="255" :description:trailing="__('Avoid personal health details, e.g. “Annual leave”.')" />
        <div>
            <flux:button variant="primary" type="submit">{{ __('Add unavailability') }}</flux:button>
        </div>
    </form>
</section>
