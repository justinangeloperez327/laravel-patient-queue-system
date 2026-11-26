<?php

use App\Enums\Permission;
use App\Enums\QueueTicketStatus;
use App\Enums\Role;
use App\Models\Service;
use App\Models\User;
use App\Services\Reporting\OperationalReport;
use App\Services\Reporting\ReportFilters;
use App\Support\ClinicTime;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    /**
     * @return list<string>
     */
    #[Computed]
    public function roleLabels(): array
    {
        return $this->user()->getRoleNames()
            ->map(fn (string $name): string => Role::tryFrom($name)?->label() ?? $name)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Today's operational KPIs, for report viewers only.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function today(): ?array
    {
        return $this->user()->can(Permission::ViewOperationalReports->value)
            ? app(OperationalReport::class)->summary(ReportFilters::today())
            : null;
    }

    /**
     * Live queue counts per service (no patient data). Reception and report
     * viewers see every service; queue staff see their departments only.
     *
     * @return list<array{prefix: string, name: string, waiting: int, at_counter: int, skipped: int}>
     */
    #[Computed]
    public function queues(): array
    {
        $user = $this->user();
        $seesAll = $user->can(Permission::IssueTickets->value) || $user->can(Permission::ViewOperationalReports->value);

        if (! $seesAll && ! $user->can(Permission::OperateQueue->value)) {
            return [];
        }

        $counts = DB::table('queue_tickets')
            ->whereDate('queue_date', ClinicTime::today()->toDateString())
            ->select('service_id')
            ->selectRaw("count(*) filter (where status = 'waiting') as waiting")
            ->selectRaw("count(*) filter (where status in ('called', 'serving')) as at_counter")
            ->selectRaw("count(*) filter (where status = 'skipped') as skipped")
            ->groupBy('service_id')
            ->get()
            ->keyBy('service_id');

        return Service::query()
            ->active()
            ->when(! $seesAll, fn ($query) => $query->whereIn('department_id', $user->departments()->select('departments.id')))
            ->orderBy('queue_prefix')
            ->get()
            ->map(fn (Service $service): array => [
                'prefix' => $service->queue_prefix,
                'name' => $service->name,
                'waiting' => (int) ($counts->get($service->id)->waiting ?? 0),
                'at_counter' => (int) ($counts->get($service->id)->at_counter ?? 0),
                'skipped' => (int) ($counts->get($service->id)->skipped ?? 0),
            ])
            ->values()
            ->all();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}; ?>

@php($fmt = fn ($v, $suffix = '') => $v === null ? '—' : number_format((float) $v, 1).$suffix)
<div class="flex h-full w-full flex-1 flex-col gap-6" wire:poll.30s>
    <div>
        <flux:heading size="xl" level="1">{{ __('Dashboard') }}</flux:heading>
        <flux:text class="mt-2">
            {{ __('Signed in as :name.', ['name' => auth()->user()->name]) }}
            @foreach ($this->roleLabels as $label)
                <flux:badge size="sm" class="ms-1">{{ $label }}</flux:badge>
            @endforeach
        </flux:text>
    </div>

    @if ($today = $this->today)
        <section aria-labelledby="today-heading" class="flex flex-col gap-3">
            <div class="flex items-baseline justify-between gap-3">
                <flux:heading size="lg" level="2" id="today-heading">{{ __('Today') }}</flux:heading>
                <flux:link :href="route('reports.index')" wire:navigate class="text-sm">{{ __('Open reports') }}</flux:link>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-test="today-kpis">
                <x-kpi :label="__('Visits')" :value="$today['visits']" :hint="__(':n distinct patients', ['n' => $today['distinct_patients']])" />
                <x-kpi :label="__('Tickets served')" :value="$today['tickets_served']" :hint="__('of :n issued', ['n' => $today['tickets_issued']])" />
                <x-kpi :label="__('Mean wait')" :value="$fmt($today['wait']['mean'], ' min')" :hint="__('90th percentile :p min', ['p' => $fmt($today['wait']['p90'])])" />
                <x-kpi :label="__('No-show rate')" :value="$fmt($today['appointments']['no_show_rate'], ' %')" :hint="__(':n of :e eligible bookings', ['n' => $today['appointments']['no_shows'], 'e' => $today['appointments']['eligible']])" />
            </div>
        </section>
    @endif

    @if ($this->queues !== [])
        <section aria-labelledby="queues-heading" class="flex flex-col gap-3">
            <flux:heading size="lg" level="2" id="queues-heading">{{ __('Queues now') }}</flux:heading>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Service') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Waiting') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('At a counter') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Skipped') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->queues as $queue)
                        <flux:table.row :key="$queue['prefix']">
                            <flux:table.cell>{{ $queue['prefix'] }} — {{ $queue['name'] }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $queue['waiting'] }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $queue['at_counter'] }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $queue['skipped'] }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </section>
    @endif
</div>
