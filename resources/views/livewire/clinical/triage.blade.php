<div class="flex max-w-4xl flex-col gap-6">
    <x-page-header :heading="__('Triage')" />
    <x-status-message />
    @foreach (['ticket', 'note', 'service_id', 'priority_reason'] as $field)
        @error($field) <flux:callout variant="danger" icon="x-circle" :heading="$message" /> @enderror
    @endforeach

    @if ($this->ticket === null)
        @include('livewire.clinical.no-patient')
    @else
        <x-clinical.encounter-header :ticket="$this->ticket" />
        <x-clinical.vitals-form :vitals="$this->visitVitals" />

        <section class="flex flex-col gap-3" aria-labelledby="triage-note-heading">
            <flux:heading size="lg" level="2" id="triage-note-heading">{{ __('Triage assessment') }}</flux:heading>
            <form wire:submit="saveNote" class="flex flex-col gap-3">
                <flux:textarea wire:model="note.chief_complaint" :label="__('Chief complaint')" rows="2" />
                <flux:error name="chief_complaint" />
                <flux:textarea wire:model="note.additional_notes" :label="__('Triage findings')" rows="4" />
                <div class="flex flex-wrap gap-2">
                    <flux:button type="submit">{{ __('Save draft') }}</flux:button>
                    <flux:button variant="primary" wire:click="signNote" wire:confirm="{{ __('Sign the triage assessment? It cannot be edited afterwards.') }}">{{ __('Sign assessment') }}</flux:button>
                </div>
            </form>
        </section>

        <flux:heading size="lg" level="2">{{ __('Notes for this visit') }}</flux:heading>
        <x-clinical.note-list :notes="$this->visitNotes" />

        <form wire:submit="sendOn" class="flex flex-wrap items-end gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="w-40">
                <flux:select wire:model="priority" :label="__('Clinical priority')">
                    @foreach (\App\Enums\ClinicalPriority::cases() as $option)
                        <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="min-w-64 flex-1">
                <flux:input wire:model="priorityReason" :label="__('Priority reason')" maxlength="255" :description:trailing="__('Required above routine.')" />
            </div>
            <flux:button type="submit" variant="primary" icon="arrow-right">{{ __('Send to :service', ['service' => $this->ticket->visit->requestedService->name]) }}</flux:button>
        </form>
    @endif
</div>
