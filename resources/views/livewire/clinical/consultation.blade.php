<div class="flex max-w-4xl flex-col gap-6">
    <x-page-header :heading="__('Consultation')" :subheading="$noteType->label()" />
    <x-status-message />
    @foreach (['ticket', 'note'] as $field)
        @error($field) <flux:callout variant="danger" icon="x-circle" :heading="$message" /> @enderror
    @endforeach

    @if ($this->ticket === null)
        @include('livewire.clinical.no-patient')
    @else
        <x-clinical.encounter-header :ticket="$this->ticket" />
        <flux:heading size="lg" level="2">{{ __('Notes for this visit') }}</flux:heading>
        <x-clinical.note-list :notes="$this->visitNotes" />
        <x-clinical.vitals-form :vitals="$this->visitVitals" />

        <section class="flex flex-col gap-3" aria-labelledby="note-heading">
            <flux:heading size="lg" level="2" id="note-heading">{{ $noteType->label() }}</flux:heading>
            <form wire:submit="saveNote" class="flex flex-col gap-3">
                @if ($noteType === \App\Enums\ClinicalNoteType::Soap)
                    <flux:textarea wire:model="note.chief_complaint" :label="__('Chief complaint')" rows="2" />
                    <flux:error name="chief_complaint" />
                    <flux:textarea wire:model="note.subjective" :label="__('Subjective')" rows="3" />
                    <flux:textarea wire:model="note.objective" :label="__('Objective')" rows="3" />
                    <flux:textarea wire:model="note.assessment" :label="__('Assessment')" rows="3" />
                    <flux:error name="assessment" />
                    <flux:textarea wire:model="note.plan" :label="__('Plan')" rows="3" />
                    <flux:error name="plan" />
                @endif
                <flux:textarea wire:model="note.additional_notes" :label="$noteType === \App\Enums\ClinicalNoteType::Soap ? __('Additional notes') : __('Nursing note')" rows="3" />
                <flux:error name="additional_notes" />
                <div class="flex flex-wrap gap-2">
                    <flux:button type="submit">{{ __('Save draft') }}</flux:button>
                    @if ($canSign)
                        <flux:button variant="primary" wire:click="signNote" wire:confirm="{{ __('Sign this note? It cannot be edited afterwards.') }}">{{ __('Sign note') }}</flux:button>
                    @endif
                </div>
            </form>
        </section>

        <div><flux:button :href="route('workspace')" wire:navigate icon="arrow-left">{{ __('Back to my counter to complete or transfer') }}</flux:button></div>
    @endif
</div>
