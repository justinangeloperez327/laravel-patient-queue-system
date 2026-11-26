<?php

declare(strict_types=1);

namespace App\Livewire\Staff;

use App\Actions\Staff\CreateStaffUser;
use App\Actions\Staff\UpdateStaffUser;
use App\Enums\Role;
use App\Models\Department;
use App\Models\ProviderSchedule;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Create a staff account (an invitation email lets them set a password) or
 * edit profile, roles, active flag and department assignments.
 */
final class StaffForm extends Component
{
    #[Locked]
    public ?User $staff = null;

    public string $name = '';

    public string $email = '';

    public bool $is_active = true;

    /**
     * @var array<int, string>
     */
    public array $roles = [];

    /**
     * @var array<int, string>
     */
    public array $department_ids = [];

    public function mount(?User $user = null): void
    {
        if ($user === null || ! $user->exists) {
            $this->authorize('create', User::class);
            $this->staff = null;

            return;
        }

        $this->authorize('update', $user);
        $this->staff = $user;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->is_active = $user->is_active;
        $this->roles = array_values(array_filter($user->getRoleNames()->all(), is_string(...)));
        $this->department_ids = $user->departments()->pluck('departments.id')->map(fn (int $id): string => (string) $id)->values()->all();
    }

    /**
     * @return Collection<int, Department>
     */
    #[Computed]
    public function departments(): Collection
    {
        return Department::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', array_map('intval', $this->department_ids)))
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function canManageSchedules(): bool
    {
        return $this->staff !== null
            && $this->staff->isProvider()
            && Auth::user()?->can('viewAny', ProviderSchedule::class) === true;
    }

    public function save(CreateStaffUser $create, UpdateStaffUser $update): void
    {
        /** @var User $actor */
        $actor = Auth::user();

        $input = [
            'name' => $this->name,
            'email' => $this->email,
            'roles' => array_values($this->roles),
            'department_ids' => array_values(array_map('intval', $this->department_ids)),
        ];

        if ($this->staff === null) {
            $user = $create->handle($input, $actor);
            session()->flash('status', __('Created :name. A link to set their password was sent to :email.', ['name' => $user->name, 'email' => $user->email]));
            $this->redirectRoute('staff.edit', ['user' => $user], navigate: true);

            return;
        }

        $user = $update->handle($actor, $this->staff, [...$input, 'is_active' => $this->is_active]);
        session()->flash('status', __('Saved :name.', ['name' => $user->name]));
        $this->redirectRoute('staff.index', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.staff.form', ['roleOptions' => Role::cases()])
            ->title($this->staff === null ? __('Add staff member') : __('Edit staff member'));
    }
}
