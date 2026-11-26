<?php

use App\Enums\Permission;
use App\Http\Controllers\DisplayController;
use App\Http\Controllers\ReportExportController;
use App\Livewire\Appointments\AppointmentCalendar;
use App\Livewire\Appointments\AppointmentDetail;
use App\Livewire\Appointments\BookAppointmentForm;
use App\Livewire\Clinical\ConsultationEncounter;
use App\Livewire\Clinical\PatientRecord;
use App\Livewire\Clinical\TriageEncounter;
use App\Livewire\Counters\CounterForm;
use App\Livewire\Counters\CounterIndex;
use App\Livewire\Departments\DepartmentForm;
use App\Livewire\Departments\DepartmentIndex;
use App\Livewire\Displays\DisplayForm;
use App\Livewire\Displays\DisplayIndex;
use App\Livewire\Patients\PatientForm;
use App\Livewire\Patients\PatientIndex;
use App\Livewire\Queues\QueueBoard;
use App\Livewire\Queues\Workspace;
use App\Livewire\Reception\ReceptionDesk;
use App\Livewire\Reports\ReportsPage;
use App\Livewire\Services\ServiceForm;
use App\Livewire\Services\ServiceIndex;
use App\Livewire\Staff\StaffForm;
use App\Livewire\Staff\StaffIndex;
use App\Livewire\Visits\VisitIndex;
use App\Models\Appointment;
use App\Models\Counter;
use App\Models\Department;
use App\Models\DisplayDevice;
use App\Models\Patient;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Support\Facades\Route;

// Public waiting-room displays: authenticated by a paired device key, not by staff login.
Route::prefix('display/{device}')->name('display.')->controller(DisplayController::class)->group(function () {
    Route::get('/', 'show')->name('show');
    Route::post('pair', 'pair')->middleware('throttle:display-pairing')->name('pair');
    Route::post('unpair', 'unpair')->name('unpair');
    Route::get('snapshot', 'snapshot')->middleware('throttle:120,1')->name('snapshot');
    Route::post('broadcasting/auth', 'authorizeChannel')->middleware('throttle:60,1')->name('auth');
});

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
    Route::livewire('patients/{patient}', PatientRecord::class)->middleware('can:viewRecord,patient')->name('patients.show');

    Route::livewire('reception', ReceptionDesk::class)->middleware('can:create,'.Visit::class)->name('reception');
    Route::livewire('visits', VisitIndex::class)->middleware('can:viewAny,'.Visit::class)->name('visits.index');

    Route::livewire('appointments', AppointmentCalendar::class)->middleware('can:viewAny,'.Appointment::class)->name('appointments.index');
    Route::livewire('appointments/create', BookAppointmentForm::class)->middleware('can:create,'.Appointment::class)->name('appointments.create');
    Route::livewire('appointments/{appointment}', AppointmentDetail::class)->middleware('can:view,appointment')->name('appointments.show');

    Route::livewire('queues', QueueBoard::class)->middleware('can:viewAny,'.QueueTicket::class)->name('queues.index');
    Route::livewire('workspace', Workspace::class)->middleware('can:'.Permission::OperateQueue->value)->name('workspace');
    Route::livewire('workspace/triage', TriageEncounter::class)->middleware('can:'.Permission::PerformTriage->value)->name('workspace.triage');
    Route::livewire('workspace/consultation', ConsultationEncounter::class)->middleware('can:'.Permission::ViewClinicalHistory->value)->name('workspace.consultation');

    Route::livewire('reports', ReportsPage::class)->middleware('can:'.Permission::ViewOperationalReports->value)->name('reports.index');
    Route::get('reports/export', ReportExportController::class)->middleware(['can:'.Permission::ExportReports->value, 'throttle:20,1'])->name('reports.export');

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

    Route::livewire('displays', DisplayIndex::class)->middleware('can:viewAny,'.DisplayDevice::class)->name('displays.index');
    Route::livewire('displays/create', DisplayForm::class)->middleware('can:create,'.DisplayDevice::class)->name('displays.create');
    Route::livewire('displays/{device}/edit', DisplayForm::class)->middleware('can:update,device')->name('displays.edit');

    Route::livewire('staff', StaffIndex::class)->middleware('can:viewAny,'.User::class)->name('staff.index');
    Route::livewire('staff/create', StaffForm::class)->middleware('can:create,'.User::class)->name('staff.create');
    Route::livewire('staff/{user}/edit', StaffForm::class)->middleware('can:update,user')->name('staff.edit');
});

require __DIR__.'/settings.php';
