@props(['selected' => null, 'results' => collect(), 'search' => ''])

<div class="flex flex-col gap-2" data-test="patient-picker">
    @if ($selected)
        <flux:label>{{ __('Patient') }}</flux:label>
        <div class="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700">
            <div>
                <div class="font-medium">{{ $selected->fullName() }}</div>
                <div class="text-sm text-zinc-500"><span class="font-mono">{{ $selected->patient_number }}</span> · {{ __('Born :date', ['date' => $selected->date_of_birth->toDateString()]) }}</div>
            </div>
            <flux:button size="sm" variant="ghost" wire:click="clearPatient">{{ __('Change') }}</flux:button>
        </div>
    @else
        <flux:input wire:model.live.debounce.300ms="patientSearch" :label="__('Patient')" icon="magnifying-glass"
            :placeholder="__('Name, patient number, phone or YYYY-MM-DD')" autocomplete="off" />
        @if (mb_strlen(trim($search)) >= 2)
            <div class="divide-y divide-zinc-100 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @forelse ($results as $result)
                    <button type="button" wire:click="selectPatient({{ $result->id }})" wire:key="patient-result-{{ $result->id }}"
                        class="flex w-full items-center justify-between gap-3 px-3 py-2 text-start hover:bg-zinc-50 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-accent dark:hover:bg-zinc-800">
                        <span class="font-medium">{{ $result->last_name }}, {{ $result->first_name }}</span>
                        <span class="text-sm text-zinc-500"><span class="font-mono">{{ $result->patient_number }}</span> · {{ $result->date_of_birth->toDateString() }}</span>
                    </button>
                @empty
                    <div class="px-3 py-3 text-sm text-zinc-500">
                        {{ __('No active patients match.') }}
                        @can('create', \App\Models\Patient::class)
                            <flux:link :href="route('patients.create')" wire:navigate>{{ __('Register a new patient') }}</flux:link>
                        @endcan
                    </div>
                @endforelse
            </div>
        @endif
    @endif
    <flux:error name="patient_id" />
</div>
