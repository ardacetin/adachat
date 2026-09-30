<?php

use App\Http\Controllers\Settings\AppearanceController;
use App\Http\Controllers\Settings\LanguageController;
use App\Http\Controllers\Settings\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'throttle:app'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');

    Route::get('settings/language', [LanguageController::class, 'edit'])->name('language.edit');
    Route::put('settings/language', [LanguageController::class, 'update'])->name('language.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');
    Route::put('settings/appearance', [AppearanceController::class, 'update'])->name('appearance.update');
});
