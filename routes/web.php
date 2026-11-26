<?php

use App\Enums\Permission;
use Illuminate\Support\Facades\Route;

// Staff-only application: the root sends guests to login and staff to the dashboard.
Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'can:'.Permission::ViewDashboard->value])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
