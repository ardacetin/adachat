<?php

use App\Http\Controllers\Auth\DevLoginController;
use App\Http\Controllers\Auth\ExternalLoginController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Chat\ConversationController;
use App\Http\Controllers\Chat\MessageController;
use Illuminate\Support\Facades\Route;

// Public: the IdP administrator (or the IdP) reads it; also the SP entity ID.
Route::get('auth/saml/metadata', [ExternalLoginController::class, 'samlMetadata'])
    ->middleware('throttle:60,1')
    ->name('auth.saml.metadata');

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');

    Route::get('auth/{provider}/redirect', [ExternalLoginController::class, 'redirect'])
        ->where('provider', '[a-z0-9-]+')
        ->middleware('throttle:20,1')
        ->name('auth.redirect');

    // Assertion consumer service: the IdP posts the SAML response here.
    Route::post('auth/{provider}/acs', [ExternalLoginController::class, 'callback'])
        ->where('provider', '[a-z0-9-]+')
        ->middleware('throttle:20,1')
        ->name('auth.acs');

    if (DevLoginController::isEnabled()) {
        Route::post('dev/login', [DevLoginController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('dev-login');
    }
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', [ConversationController::class, 'index'])->name('home');
    Route::get('c/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
    Route::patch('c/{conversation}', [ConversationController::class, 'update'])->name('conversations.update');
    Route::delete('c/{conversation}', [ConversationController::class, 'destroy'])->name('conversations.destroy');

    Route::post('chat/messages', [MessageController::class, 'store'])
        ->middleware('throttle:60,1')
        ->name('messages.store');
    Route::post('chat/messages/{message}/regenerate', [MessageController::class, 'regenerate'])
        ->middleware('throttle:60,1')
        ->name('messages.regenerate');
    Route::post('chat/messages/{message}/cancel', [MessageController::class, 'cancel'])
        ->middleware('throttle:120,1')
        ->name('messages.cancel');
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
