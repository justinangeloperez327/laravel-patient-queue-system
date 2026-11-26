<div class="flex flex-col gap-6">
    <x-page-header :heading="__('Audit log')" :subheading="__('Append-only record of access and changes. Identifiers only — open the record to see details you are authorized for.')" />

    <div class="flex flex-wrap items-end gap-3">
        <flux:input type="date" wire:model.live="date" :label="__('Date')" />
        <div class="w-56"><flux:input wire:model.live.debounce.300ms="action" :label="__('Action starts with')" :placeholder="__('e.g. clinical.')" /></div>
        <div class="w-44">
            <flux:select wire:model.live="outcome" :label="__('Outcome')">
                <flux:select.option value="">{{ __('All') }}</flux:select.option>
                <flux:select.option value="success">{{ __('Success') }}</flux:select.option>
                <flux:select.option value="denied">{{ __('Denied') }}</flux:select.option>
                <flux:select.option value="failure">{{ __('Failure') }}</flux:select.option>
            </flux:select>
        </div>
    </div>

    <flux:table :paginate="$this->logs">
        <flux:table.columns>
            <flux:table.column>{{ __('Time') }}</flux:table.column>
            <flux:table.column>{{ __('Actor') }}</flux:table.column>
            <flux:table.column>{{ __('Action') }}</flux:table.column>
            <flux:table.column>{{ __('Subject') }}</flux:table.column>
            <flux:table.column>{{ __('Outcome') }}</flux:table.column>
            <flux:table.column>{{ __('Details') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->logs as $log)
                <flux:table.row :key="$log->id">
                    <flux:table.cell class="tabular-nums">{{ \App\Support\ClinicTime::toLocalDisplay($log->created_at) }}</flux:table.cell>
                    <flux:table.cell>{{ $log->actor?->name ?? __('system') }}</flux:table.cell>
                    <flux:table.cell class="font-mono text-xs">{{ $log->action }}</flux:table.cell>
                    <flux:table.cell class="font-mono text-xs">{{ class_basename($log->subject_type) }} #{{ $log->subject_id }}</flux:table.cell>
                    <flux:table.cell><flux:badge size="sm" :color="$log->outcome === 'success' ? 'green' : 'red'">{{ $log->outcome }}</flux:badge></flux:table.cell>
                    <flux:table.cell class="max-w-md truncate font-mono text-xs" title="{{ json_encode($log->metadata) }}">{{ json_encode($log->metadata) }}</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row><flux:table.cell colspan="6"><div class="py-6 text-center"><flux:text>{{ __('No entries for these filters.') }}</flux:text></div></flux:table.cell></flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</div>
