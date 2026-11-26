<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Enums\Role;
use App\Models\Service;
use App\Models\User;
use App\Services\Reporting\OperationalReport;
use App\Services\Reporting\ReportFilters;
use App\Support\ClinicTime;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * @property-read ReportFilters|null $filters
 * @property-read Collection<int, Service> $services
 * @property-read Collection<int, User> $providers
 */
#[Title('Reports')]
final class ReportsPage extends Component
{
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url(as: 'service')]
    public string $service_id = '';

    #[Url(as: 'provider')]
    public string $provider_id = '';

    public function mount(): void
    {
        if ($this->from === '' || $this->to === '') {
            $this->to = ClinicTime::today()->toDateString();
            $this->from = ClinicTime::today()->subDays(6)->toDateString();
        }
    }

    #[Computed]
    public function filters(): ?ReportFilters
    {
        try {
            $this->resetErrorBag();

            return ReportFilters::fromInput($this->filterInput());
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return null;
        }
    }

    /**
     * @return array{from: string, to: string, service_id: string, provider_id: string}
     */
    public function filterInput(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'service_id' => $this->service_id, 'provider_id' => $this->provider_id];
    }

    /**
     * @return Collection<int, Service>
     */
    #[Computed]
    public function services(): Collection
    {
        return Service::query()->orderBy('queue_prefix')->get();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function providers(): Collection
    {
        return User::query()->role(array_map(static fn (Role $role): string => $role->value, Role::providerRoles()))->orderBy('name')->get();
    }

    public function render(OperationalReport $report): View
    {
        $filters = $this->filters;

        return view('livewire.reports.page', [
            'summary' => $filters ? $report->summary($filters) : null,
            'byService' => $filters ? $report->byService($filters) : [],
            'byProvider' => $filters ? $report->byProvider($filters) : [],
            'byDay' => $filters ? $report->byDay($filters) : [],
        ]);
    }
}
