@php($tz = \App\Support\ClinicTime::timezone())
<div class="flex max-w-4xl flex-col gap-6">
    <x-page-header :heading="$patient->fullName()" :subheading="$patient->patient_number">
        <x-slot:actions>
            @can('update', $patient)
                <flux:button :href="route('patients.edit', $patient)" wire:navigate>{{ __('Edit demographics') }}</flux:button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <x-status-message />

    <dl class="grid gap-4 rounded-lg border border-zinc-200 p-4 sm:grid-cols-3 dark:border-zinc-700">
        <div><dt class="text-sm text-zinc-500">{{ __('Date of birth') }}</dt><dd>{{ $patient->date_of_birth->toDateString() }} ({{ $patient->date_of_birth->age }} y)</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Sex') }}</dt><dd>{{ $patient->sex?->label() ?? '—' }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Status') }}</dt><dd>{{ $patient->is_active ? __('Active') : __('Inactive') }}</dd></div>
        @can('view', $patient)
            <div><dt class="text-sm text-zinc-500">{{ __('Phone') }}</dt><dd>{{ $patient->phone ?? '—' }}</dd></div>
            <div><dt class="text-sm text-zinc-500">{{ __('Email') }}</dt><dd>{{ $patient->email ?? '—' }}</dd></div>
            <div><dt class="text-sm text-zinc-500">{{ __('Emergency contact') }}</dt><dd>{{ $patient->emergency_contact_name ?? '—' }} {{ $patient->emergency_contact_phone }}</dd></div>
        @endcan
    </dl>

    @if (! $showClinical)
        <flux:callout icon="lock-closed" :heading="__('Clinical history is only visible to clinicians caring for this patient.')" />
    @endif

    @if ($amendingNoteId !== null)
        <form wire:submit="amend" class="flex flex-col gap-3 rounded-lg border border-amber-300 bg-amber-50 p-4 dark:border-amber-700 dark:bg-amber-950">
            <flux:heading size="md" level="2">{{ __('Add amendment') }}</flux:heading>
            <flux:textarea wire:model="amendment" :label="__('Amendment')" rows="3" required />
            <flux:input wire:model="amendmentReason" :label="__('Reason')" required maxlength="255" />
            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">{{ __('Sign amendment') }}</flux:button>
                <flux:button wire:click="$set('amendingNoteId', null)" variant="ghost">{{ __('Back') }}</flux:button>
            </div>
            <flux:error name="note" />
        </form>
    @endif

    <section class="flex flex-col gap-4" aria-labelledby="visits-heading">
        <flux:heading size="lg" level="2" id="visits-heading">{{ __('Visits') }}</flux:heading>
        @forelse ($this->visits as $visit)
            <article class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" wire:key="visit-{{ $visit->id }}">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <span class="font-mono font-medium">{{ $visit->visit_number }}</span>
                        <span class="text-sm text-zinc-500">· {{ $visit->checked_in_at->setTimezone($tz)->isoFormat('D MMM YYYY, HH:mm') }} · {{ $visit->requestedService->name }} · {{ $visit->visit_type->label() }}</span>
                    </div>
                    <flux:badge size="sm" :color="$visit->status->color()">{{ $visit->status->label() }}</flux:badge>
                </div>
                <flux:text class="text-sm">{{ __('Tickets: :list', ['list' => $visit->tickets->map(fn ($t) => $t->ticket_number.' ('.strtolower($t->status->label()).')')->implode(' → ') ?: '—']) }}</flux:text>

                @if ($showClinical)
                    @if ($visit->vitalSigns->isNotEmpty())
                        <x-clinical.vitals-form :vitals="$visit->vitalSigns" :editable="false" />
                    @endif
                    <x-clinical.note-list :notes="$visit->clinicalNotes" :amendable="true" />
                @endif
            </article>
        @empty
            <flux:text>{{ __('No visits yet.') }}</flux:text>
        @endforelse
    </section>
</div>
