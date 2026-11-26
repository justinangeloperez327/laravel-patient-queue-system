@props(['visit', 'outcome'])

<form wire:submit="closeVisit" class="flex flex-wrap items-end gap-3 rounded-lg border border-amber-300 bg-amber-50 p-3 dark:border-amber-700 dark:bg-amber-950">
    <div class="min-w-64 flex-1">
        <flux:input wire:model="closeReason" required maxlength="255"
            :label="$outcome === 'cancelled' ? __('Reason for cancelling :number', ['number' => $visit->visit_number]) : __('Note for :number (left without service)', ['number' => $visit->visit_number])" />
    </div>
    <flux:button type="submit" variant="danger">{{ $outcome === 'cancelled' ? __('Cancel visit') : __('Record left without service') }}</flux:button>
    <flux:button wire:click="stopClosing" variant="ghost">{{ __('Keep visit') }}</flux:button>
    <div class="w-full"><flux:error name="status" /></div>
</form>
