<?php

use App\Http\Controllers\Admin\AiModelController;
use App\Http\Controllers\Admin\AuthSettingsController;
use App\Http\Controllers\Admin\BudgetPolicyController;
use App\Http\Controllers\Admin\GroupController;
use App\Http\Controllers\Admin\InstitutionSettingsController;
use App\Http\Controllers\Admin\ModelAliasController;
use App\Http\Controllers\Admin\ProviderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:access-admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::inertia('/', 'admin/index')->name('index');

    Route::middleware('can:manage-system')->group(function () {
        Route::get('institution', [InstitutionSettingsController::class, 'edit'])->name('institution.edit');
        // POST (not PUT): the form carries file uploads.
        Route::post('institution', [InstitutionSettingsController::class, 'update'])->name('institution.update');

        Route::get('authentication', [AuthSettingsController::class, 'edit'])->name('authentication.edit');
        Route::put('authentication', [AuthSettingsController::class, 'update'])->name('authentication.update');

        Route::resource('providers', ProviderController::class)->except(['show', 'destroy']);
        Route::post('providers/{provider}/check', [ProviderController::class, 'check'])
            ->middleware('throttle:10,1')
            ->name('providers.check');

        Route::resource('models', AiModelController::class)->except(['show', 'destroy']);
        Route::resource('aliases', ModelAliasController::class)
            ->parameters(['aliases' => 'alias'])
            ->except(['show', 'destroy']);

        Route::resource('groups', GroupController::class)->except(['show']);
        Route::resource('budget-policies', BudgetPolicyController::class)->except(['show']);
    });
});
