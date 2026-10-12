<?php

use App\Http\Controllers\Settings\AppearanceController;
use App\Http\Controllers\Settings\LanguageController;
use App\Http\Controllers\Settings\NotificationController;
use App\Http\Controllers\Settings\ProfileController;
use Illuminate\Routing\RedirectController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'throttle:app'])->group(function () {
    // Route::redirect() also answers the QUERY method (Laravel 13.35), which
    // Wayfinder's generated route types do not know yet.
    Route::get('settings', RedirectController::class)
        ->defaults('destination', '/settings/profile')
        ->defaults('status', 302);

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');

    Route::get('settings/language', [LanguageController::class, 'edit'])->name('language.edit');
    Route::put('settings/language', [LanguageController::class, 'update'])->name('language.update');

    Route::get('settings/notifications', [NotificationController::class, 'edit'])->name('notifications.edit');
    Route::put('settings/notifications', [NotificationController::class, 'update'])->name('notifications.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');
    Route::put('settings/appearance', [AppearanceController::class, 'update'])->name('appearance.update');
});
