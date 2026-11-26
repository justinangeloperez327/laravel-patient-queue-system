<?php

use App\Enums\Permission;
use App\Livewire\Counters\CounterForm;
use App\Livewire\Counters\CounterIndex;
use App\Livewire\Departments\DepartmentForm;
use App\Livewire\Departments\DepartmentIndex;
use App\Livewire\Patients\PatientForm;
use App\Livewire\Patients\PatientIndex;
use App\Livewire\Services\ServiceForm;
use App\Livewire\Services\ServiceIndex;
use App\Livewire\Staff\StaffForm;
use App\Livewire\Staff\StaffIndex;
use App\Models\Counter;
use App\Models\Department;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Route;

// Staff-only application: the root sends guests to login and staff to the dashboard.
Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')
        ->middleware('can:'.Permission::ViewDashboard->value)
        ->name('dashboard');

    // Reception. Components and actions authorize again; route middleware is defence in depth.
    Route::livewire('patients', PatientIndex::class)->middleware('can:viewAny,'.Patient::class)->name('patients.index');
    Route::livewire('patients/create', PatientForm::class)->middleware('can:create,'.Patient::class)->name('patients.create');
    Route::livewire('patients/{patient}/edit', PatientForm::class)->middleware('can:update,patient')->name('patients.edit');

    // Administration.
    Route::livewire('departments', DepartmentIndex::class)->middleware('can:viewAny,'.Department::class)->name('departments.index');
    Route::livewire('departments/create', DepartmentForm::class)->middleware('can:create,'.Department::class)->name('departments.create');
    Route::livewire('departments/{department}/edit', DepartmentForm::class)->middleware('can:update,department')->name('departments.edit');

    Route::livewire('services', ServiceIndex::class)->middleware('can:viewAny,'.Service::class)->name('services.index');
    Route::livewire('services/create', ServiceForm::class)->middleware('can:create,'.Service::class)->name('services.create');
    Route::livewire('services/{service}/edit', ServiceForm::class)->middleware('can:update,service')->name('services.edit');

    Route::livewire('counters', CounterIndex::class)->middleware('can:viewAny,'.Counter::class)->name('counters.index');
    Route::livewire('counters/create', CounterForm::class)->middleware('can:create,'.Counter::class)->name('counters.create');
    Route::livewire('counters/{counter}/edit', CounterForm::class)->middleware('can:update,counter')->name('counters.edit');

    Route::livewire('staff', StaffIndex::class)->middleware('can:viewAny,'.User::class)->name('staff.index');
    Route::livewire('staff/create', StaffForm::class)->middleware('can:create,'.User::class)->name('staff.create');
    Route::livewire('staff/{user}/edit', StaffForm::class)->middleware('can:update,user')->name('staff.edit');
});

require __DIR__.'/settings.php';
