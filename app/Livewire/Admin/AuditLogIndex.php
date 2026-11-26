<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Enums\Permission;
use App\Models\AuditLog;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * @property-read LengthAwarePaginator<int, AuditLog> $logs
 *
 * Read-only access log review for administrators. Entries hold identifiers
 * and context only; the log itself is append-only.
 */
#[Title('Audit log')]
final class AuditLogIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $date = '';

    #[Url]
    public string $action = '';

    #[Url]
    public string $outcome = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->can(Permission::ViewAuditLogs->value) === true, 403);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) !== 1) {
            $this->date = ClinicTime::today()->toDateString();
        }
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, AuditLog>
     */
    #[Computed]
    public function logs(): LengthAwarePaginator
    {
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) === 1
            ? CarbonImmutable::parse($this->date, ClinicTime::timezone())
            : ClinicTime::today();

        return AuditLog::query()
            ->with('actor')
            ->where('created_at', '>=', $day->utc())
            ->where('created_at', '<', $day->addDay()->utc())
            ->when($this->action !== '', fn ($query) => $query->where('action', 'like', str_replace(['%', '_'], ['\\%', '\\_'], $this->action).'%'))
            ->when(in_array($this->outcome, ['success', 'denied', 'failure'], true), fn ($query) => $query->where('outcome', $this->outcome))
            ->orderByDesc('id')
            ->paginate(50);
    }

    public function render(): View
    {
        return view('livewire.admin.audit-log');
    }
}
