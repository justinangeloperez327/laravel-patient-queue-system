@php($fmt = fn ($v, $suffix = '') => $v === null ? '—' : number_format((float) $v, 1).$suffix)
<div class="flex flex-col gap-6">
    <x-page-header :heading="__('Reports')" :subheading="__('Operational figures in clinic time (:tz). Aggregates only.', ['tz' => \App\Support\ClinicTime::timezone()])">
        @can(\App\Enums\Permission::ExportReports->value)
            @if ($summary)
                <x-slot:actions>
                    <flux:button icon="arrow-down-tray" :href="route('reports.export', [...$this->filterInput(), 'format' => 'xlsx'])">{{ __('Excel workbook') }}</flux:button>
                    <flux:button icon="arrow-down-tray" variant="ghost" :href="route('reports.export', [...$this->filterInput(), 'format' => 'csv', 'table' => 'day'])">{{ __('CSV (by day)') }}</flux:button>
                </x-slot:actions>
            @endif
        @endcan
    </x-page-header>

    <div class="flex flex-wrap items-end gap-3">
        <flux:input type="date" wire:model.live="from" :label="__('From')" />
        <flux:input type="date" wire:model.live="to" :label="__('To')" />
        <div class="w-56">
            <flux:select wire:model.live="service_id" :label="__('Service')">
                <flux:select.option value="">{{ __('All services') }}</flux:select.option>
                @foreach ($this->services as $service)
                    <flux:select.option :value="$service->id">{{ $service->queue_prefix }} — {{ $service->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <div class="w-56">
            <flux:select wire:model.live="provider_id" :label="__('Provider')">
                <flux:select.option value="">{{ __('All providers') }}</flux:select.option>
                @foreach ($this->providers as $provider)
                    <flux:select.option :value="$provider->id">{{ $provider->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>
    @foreach (['from', 'to', 'service_id', 'provider_id'] as $field)
        @error($field) <flux:callout variant="danger" icon="x-circle" :heading="$message" /> @enderror
    @endforeach

    @if ($summary)
        <section aria-labelledby="summary-heading" class="flex flex-col gap-3">
            <flux:heading size="lg" level="2" id="summary-heading">{{ __('Summary') }}</flux:heading>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-test="kpis">
                <x-kpi :label="__('Visits')" :value="$summary['visits']" :hint="__(':c completed · :l left · :x cancelled', ['c' => $summary['visits_completed'], 'l' => $summary['visits_left_without_service'], 'x' => $summary['visits_cancelled']])" />
                <x-kpi :label="__('Distinct patients')" :value="$summary['distinct_patients']" />
                <x-kpi :label="__('Wait (eligible → start)')" :value="$fmt($summary['wait']['mean'], ' min')" :hint="__('median :m · 90th :p · n=:n', ['m' => $fmt($summary['wait']['median']), 'p' => $fmt($summary['wait']['p90']), 'n' => $summary['wait']['count']])" />
                <x-kpi :label="__('Service (start → end)')" :value="$fmt($summary['service']['mean'], ' min')" :hint="__('median :m · 90th :p · n=:n', ['m' => $fmt($summary['service']['median']), 'p' => $fmt($summary['service']['p90']), 'n' => $summary['service']['count']])" />
                <x-kpi :label="__('Tickets served')" :value="$summary['tickets_served']" :hint="__('of :i issued · :c cancelled', ['i' => $summary['tickets_issued'], 'c' => $summary['tickets_cancelled']])" />
                <x-kpi :label="__('No-show rate')" :value="$fmt($summary['appointments']['no_show_rate'], ' %')" :hint="__(':n of :e eligible bookings', ['n' => $summary['appointments']['no_shows'], 'e' => $summary['appointments']['eligible']])" />
                <x-kpi :label="__('Appointments booked')" :value="$summary['appointments']['booked']" :hint="__(':c cancelled', ['c' => $summary['appointments']['cancelled']])" />
            </div>
            <flux:text class="text-xs">{{ __('Eligible bookings reached an outcome (checked in, completed or no-show); cancelled and still-pending bookings are excluded from the no-show rate.') }}</flux:text>
        </section>

        <section aria-labelledby="service-heading" class="flex flex-col gap-2">
            <flux:heading size="lg" level="2" id="service-heading">{{ __('By service') }}</flux:heading>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Service') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Issued') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Served') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Wait mean') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Wait median') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Service mean') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('No-show rate') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($byService as $row)
                        <flux:table.row :key="'s-'.$row['service_id']">
                            <flux:table.cell>{{ $row['prefix'] }} — {{ $row['name'] }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $row['tickets_issued'] }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $row['tickets_served'] }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $fmt($row['wait_mean'], ' min') }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $fmt($row['wait_median'], ' min') }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $fmt($row['service_mean'], ' min') }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $fmt($row['no_show_rate'], ' %') }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row><flux:table.cell colspan="7"><flux:text>{{ __('No activity in this range.') }}</flux:text></flux:table.cell></flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </section>

        <section aria-labelledby="provider-heading" class="flex flex-col gap-2">
            <flux:heading size="lg" level="2" id="provider-heading">{{ __('By provider') }}</flux:heading>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Provider') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Served') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Wait mean') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Service mean') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Eligible bookings') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('No-show rate') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($byProvider as $row)
                        <flux:table.row :key="'p-'.$row['provider_id']">
                            <flux:table.cell>{{ $row['name'] }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $row['tickets_served'] }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $fmt($row['wait_mean'], ' min') }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $fmt($row['service_mean'], ' min') }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $row['appointments_eligible'] }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $fmt($row['no_show_rate'], ' %') }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row><flux:table.cell colspan="6"><flux:text>{{ __('No provider activity in this range.') }}</flux:text></flux:table.cell></flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </section>

        <section aria-labelledby="day-heading" class="flex flex-col gap-2">
            <flux:heading size="lg" level="2" id="day-heading">{{ __('By day') }}</flux:heading>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Date') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Visits') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Patients') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Served') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Wait mean') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Service mean') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($byDay as $row)
                        <flux:table.row :key="'d-'.$row['date']">
                            <flux:table.cell class="tabular-nums">{{ $row['date'] }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $row['visits'] }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $row['distinct_patients'] }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $row['tickets_served'] }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $fmt($row['wait_mean'], ' min') }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $fmt($row['service_mean'], ' min') }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </section>
    @endif
</div>
