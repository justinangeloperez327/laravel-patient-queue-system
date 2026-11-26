<?php

declare(strict_types=1);

namespace App\Livewire\Departments;

use App\Actions\Departments\SaveDepartment;
use App\Models\Department;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class DepartmentForm extends Component
{
    #[Locked]
    public ?Department $department = null;

    public string $code = '';

    public string $name = '';

    public string $description = '';

    public bool $is_active = true;

    public function mount(?Department $department = null): void
    {
        if ($department === null || ! $department->exists) {
            $this->authorize('create', Department::class);
            $this->department = null;

            return;
        }

        $this->authorize('update', $department);
        $this->department = $department;
        $this->code = $department->code;
        $this->name = $department->name;
        $this->description = $department->description ?? '';
        $this->is_active = $department->is_active;
    }

    public function save(SaveDepartment $save): void
    {
        /** @var User $actor */
        $actor = Auth::user();

        $department = $save->handle($actor, [
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description === '' ? null : $this->description,
            'is_active' => $this->is_active,
        ], $this->department);

        session()->flash('status', __('Saved department :name.', ['name' => $department->name]));
        $this->redirectRoute('departments.index', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.departments.form')
            ->title($this->department === null ? __('Add department') : __('Edit department'));
    }
}
