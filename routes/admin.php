<?php

use App\Http\Controllers\Admin\AiModelController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\AuthSettingsController;
use App\Http\Controllers\Admin\BudgetPolicyController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\GroupController;
use App\Http\Controllers\Admin\InstitutionSettingsController;
use App\Http\Controllers\Admin\ModelAliasController;
use App\Http\Controllers\Admin\PrivacySettingsController;
use App\Http\Controllers\Admin\ProviderController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ReportExportController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:access-admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', DashboardController::class)->name('index');
    Route::get('reports', ReportController::class)->name('reports.index');
    Route::get('reports/export', ReportExportController::class)->name('reports.export');

    // Administrators and super administrators (docs/authentication.md §4);
    // UserPolicy decides per action.
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
    Route::put('users/{user}/group', [UserController::class, 'updateGroup'])->name('users.group');
    Route::put('users/{user}/budget', [UserController::class, 'updateBudget'])->name('users.budget');
    Route::put('users/{user}/status', [UserController::class, 'updateStatus'])->name('users.status');
    Route::put('users/{user}/role', [UserController::class, 'updateRole'])->name('users.role');
    Route::post('users/{user}/adjustments', [UserController::class, 'storeAdjustment'])->name('users.adjustments');

    Route::resource('groups', GroupController::class)->except(['show']);

    Route::get('audit-log', [AuditLogController::class, 'index'])->name('audit-log.index');

    Route::middleware('can:manage-system')->group(function () {
        Route::get('institution', [InstitutionSettingsController::class, 'edit'])->name('institution.edit');
        // POST (not PUT): the form carries file uploads.
        Route::post('institution', [InstitutionSettingsController::class, 'update'])->name('institution.update');
        Route::post('institution/test-mail', [InstitutionSettingsController::class, 'testMail'])->name('institution.test-mail');

        Route::get('authentication', [AuthSettingsController::class, 'edit'])->name('authentication.edit');
        Route::put('authentication', [AuthSettingsController::class, 'update'])->name('authentication.update');

        Route::get('privacy', [PrivacySettingsController::class, 'edit'])->name('privacy.edit');
        Route::put('privacy', [PrivacySettingsController::class, 'update'])->name('privacy.update');

        Route::resource('providers', ProviderController::class)->except(['show', 'destroy']);
        Route::post('providers/{provider}/check', [ProviderController::class, 'check'])
            ->middleware('throttle:10,1')
            ->name('providers.check');

        Route::resource('models', AiModelController::class)->except(['show', 'destroy']);
        Route::resource('aliases', ModelAliasController::class)
            ->parameters(['aliases' => 'alias'])
            ->except(['show', 'destroy']);

        Route::resource('budget-policies', BudgetPolicyController::class)->except(['show']);
    });
});
