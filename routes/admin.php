<?php

use App\Http\Controllers\Admin\AuthSettingsController;
use App\Http\Controllers\Admin\InstitutionSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:access-admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::inertia('/', 'admin/index')->name('index');

    Route::middleware('can:manage-system')->group(function () {
        Route::get('institution', [InstitutionSettingsController::class, 'edit'])->name('institution.edit');
        // POST (not PUT): the form carries file uploads.
        Route::post('institution', [InstitutionSettingsController::class, 'update'])->name('institution.update');

        Route::get('authentication', [AuthSettingsController::class, 'edit'])->name('authentication.edit');
        Route::put('authentication', [AuthSettingsController::class, 'update'])->name('authentication.update');
    });
});
