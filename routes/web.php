<?php

use App\Http\Controllers\Auth\DevLoginController;
use App\Http\Controllers\Auth\ExternalLoginController;
use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');

    Route::get('auth/{provider}/redirect', [ExternalLoginController::class, 'redirect'])
        ->where('provider', '[a-z0-9-]+')
        ->middleware('throttle:20,1')
        ->name('auth.redirect');

    Route::get('auth/{provider}/callback', [ExternalLoginController::class, 'callback'])
        ->where('provider', '[a-z0-9-]+')
        ->middleware('throttle:20,1')
        ->name('auth.callback');

    if (DevLoginController::isEnabled()) {
        Route::post('dev/login', [DevLoginController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('dev-login');
    }
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::inertia('/', 'home')->name('home');
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
