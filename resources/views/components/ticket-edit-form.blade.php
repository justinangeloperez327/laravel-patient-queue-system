@props(['ticket', 'mode'])

<form wire:submit="saveTicketEdit" class="flex flex-wrap items-end gap-3 rounded-lg border border-amber-300 bg-amber-50 p-3 dark:border-amber-700 dark:bg-amber-950" data-test="ticket-edit-form">
    @if ($mode === 'priority')
        <div class="w-40">
            <flux:select wire:model="ticketPriority" :label="__('Clinical priority for :number', ['number' => $ticket->ticket_number])">
                @foreach (\App\Enums\ClinicalPriority::cases() as $option)
                    <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <div class="min-w-64 flex-1">
            <flux:input wire:model="ticketReason" :label="__('Reason')" maxlength="255" :description:trailing="__('Required above routine.')" />
        </div>
        <flux:button type="submit" variant="primary">{{ __('Set priority') }}</flux:button>
    @else
        <div class="min-w-64 flex-1">
            <flux:input wire:model="ticketReason" required maxlength="255" :label="__('Reason for cancelling :number', ['number' => $ticket->ticket_number])" />
        </div>
        <flux:button type="submit" variant="danger">{{ __('Cancel ticket') }}</flux:button>
    @endif
    <flux:button wire:click="stopEditingTicket" variant="ghost">{{ __('Back') }}</flux:button>
    <div class="w-full"><flux:error name="ticket" /><flux:error name="priority_reason" /></div>
</form>
