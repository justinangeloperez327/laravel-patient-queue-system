@props(['vitals', 'editable' => true])

<section class="flex flex-col gap-3" aria-labelledby="vitals-heading">
    <flux:heading size="lg" level="2" id="vitals-heading">{{ __('Vital signs') }}</flux:heading>
    @if ($vitals->isNotEmpty())
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Time') }}</flux:table.column>
                <flux:table.column>{{ __('BP (mmHg)') }}</flux:table.column>
                <flux:table.column>{{ __('HR') }}</flux:table.column>
                <flux:table.column>{{ __('RR') }}</flux:table.column>
                <flux:table.column>{{ __('Temp °C') }}</flux:table.column>
                <flux:table.column>{{ __('SpO₂ %') }}</flux:table.column>
                <flux:table.column>{{ __('Wt kg') }}</flux:table.column>
                <flux:table.column>{{ __('Ht cm') }}</flux:table.column>
                <flux:table.column>{{ __('Pain') }}</flux:table.column>
                <flux:table.column>{{ __('By') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($vitals as $set)
                    <flux:table.row :key="'vitals-'.$set->id">
                        <flux:table.cell class="tabular-nums">{{ \App\Support\ClinicTime::toLocalDisplay($set->recorded_at) }}</flux:table.cell>
                        <flux:table.cell class="tabular-nums">{{ $set->systolic_bp ? $set->systolic_bp.'/'.$set->diastolic_bp : '—' }}</flux:table.cell>
                        <flux:table.cell class="tabular-nums">{{ $set->heart_rate ?? '—' }}</flux:table.cell>
                        <flux:table.cell class="tabular-nums">{{ $set->respiratory_rate ?? '—' }}</flux:table.cell>
                        <flux:table.cell class="tabular-nums">{{ $set->temperature_celsius ?? '—' }}</flux:table.cell>
                        <flux:table.cell class="tabular-nums">{{ $set->oxygen_saturation ?? '—' }}</flux:table.cell>
                        <flux:table.cell class="tabular-nums">{{ $set->weight_kg ?? '—' }}</flux:table.cell>
                        <flux:table.cell class="tabular-nums">{{ $set->height_cm ?? '—' }}</flux:table.cell>
                        <flux:table.cell class="tabular-nums">{{ $set->pain_score ?? '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $set->recordedBy->name }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    @if ($editable)
        @can(\App\Enums\Permission::RecordVitalSigns->value)
            <form wire:submit="recordVitals" class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="grid gap-3 sm:grid-cols-4">
                    <flux:input wire:model="vitals.systolic_bp" type="number" inputmode="numeric" :label="__('Systolic (mmHg)')" min="50" max="300" />
                    <flux:input wire:model="vitals.diastolic_bp" type="number" inputmode="numeric" :label="__('Diastolic (mmHg)')" min="20" max="200" />
                    <flux:input wire:model="vitals.heart_rate" type="number" inputmode="numeric" :label="__('Heart rate (/min)')" min="20" max="250" />
                    <flux:input wire:model="vitals.respiratory_rate" type="number" inputmode="numeric" :label="__('Resp. rate (/min)')" min="4" max="80" />
                    <flux:input wire:model="vitals.temperature_celsius" type="number" step="0.1" inputmode="decimal" :label="__('Temperature (°C)')" min="30" max="45" />
                    <flux:input wire:model="vitals.oxygen_saturation" type="number" inputmode="numeric" :label="__('SpO₂ (%)')" min="50" max="100" />
                    <flux:input wire:model="vitals.weight_kg" type="number" step="0.01" inputmode="decimal" :label="__('Weight (kg)')" min="0.3" max="500" />
                    <flux:input wire:model="vitals.height_cm" type="number" step="0.1" inputmode="decimal" :label="__('Height (cm)')" min="20" max="260" />
                    <flux:input wire:model="vitals.pain_score" type="number" inputmode="numeric" :label="__('Pain (0–10)')" min="0" max="10" />
                    <div class="sm:col-span-3"><flux:input wire:model="vitals.remarks" :label="__('Remarks')" :badge="__('Optional')" maxlength="255" /></div>
                </div>
                @foreach (['vitals', 'systolic_bp', 'diastolic_bp', 'heart_rate', 'respiratory_rate', 'temperature_celsius', 'oxygen_saturation', 'weight_kg', 'height_cm', 'pain_score'] as $field)
                    <flux:error :name="$field" />
                @endforeach
                <div><flux:button type="submit" variant="primary">{{ __('Record vital signs') }}</flux:button></div>
                <flux:text class="text-xs">{{ __('Recorded sets cannot be edited; record a new set to correct a reading.') }}</flux:text>
            </form>
        @endcan
    @endif
</section>
