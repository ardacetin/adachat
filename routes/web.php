<?php

use App\Domain\Identity\Providers\OidcIdentityProvider;
use App\Domain\Identity\Providers\SamlIdentityProvider;
use App\Http\Controllers\AcknowledgmentController;
use App\Http\Controllers\Auth\DevLoginController;
use App\Http\Controllers\Auth\ExternalLoginController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Chat\AssistantGalleryController;
use App\Http\Controllers\Chat\AttachmentController;
use App\Http\Controllers\Chat\ConversationController;
use App\Http\Controllers\Chat\ConversationExportController;
use App\Http\Controllers\Chat\ConversationShareController;
use App\Http\Controllers\Chat\MessageController;
use App\Http\Controllers\Chat\MessageFeedbackController;
use App\Http\Controllers\Chat\SearchController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\UsageController;
use Illuminate\Support\Facades\Route;

// Numeric throttles get a prefix each: without one, Laravel keys them all by
// the user (or IP) alone, so one route's requests would count for the others.

// Public: the IdP administrator (or the IdP) reads it; also the SP entity ID.
Route::get('auth/saml/metadata', [ExternalLoginController::class, 'samlMetadata'])
    ->middleware('throttle:60,1,saml-metadata:')
    ->name('auth.saml.metadata');

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');

    Route::get('auth/{provider}/redirect', [ExternalLoginController::class, 'redirect'])
        ->where('provider', '[a-z0-9-]+')
        ->middleware('throttle:20,1,auth-redirect:')
        ->name('auth.redirect');

    // Assertion consumer service: the IdP posts the SAML response here.
    Route::post('auth/{provider}/acs', [ExternalLoginController::class, 'callback'])
        ->where('provider', SamlIdentityProvider::KEY)
        ->middleware('throttle:20,1,auth-acs:')
        ->name('auth.acs');

    // OpenID Connect redirects the browser back here with the code (GET).
    Route::get('auth/{provider}/callback', [ExternalLoginController::class, 'callback'])
        ->where('provider', OidcIdentityProvider::KEY)
        ->middleware('throttle:20,1,auth-callback:')
        ->name('auth.callback');

    // Local and testing only. The end-to-end suite signs in more than 30
    // times a minute from one address.
    if (DevLoginController::isEnabled()) {
        Route::post('dev/login', [DevLoginController::class, 'store'])
            ->middleware('throttle:300,1,dev-login:')
            ->name('dev-login');
    }
});

// The language switch on the landing and sign-in pages (also for guests).
Route::post('locale', LocaleController::class)
    ->middleware('throttle:30,1,locale:')
    ->name('locale.update');

// The chat for signed-in users, the landing page for everyone else.
Route::get('/', HomeController::class)->middleware('throttle:app')->name('home');

Route::middleware(['auth', 'throttle:app'])->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('acknowledgment', [AcknowledgmentController::class, 'show'])->name('acknowledgment.show');
    Route::post('acknowledgment', [AcknowledgmentController::class, 'store'])
        ->middleware('throttle:10,1,acknowledgment:')
        ->name('acknowledgment.store');

    Route::get('usage', [UsageController::class, 'show'])->name('usage');
    Route::get('c/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
    Route::patch('c/{conversation}', [ConversationController::class, 'update'])->name('conversations.update');
    Route::delete('c/{conversation}', [ConversationController::class, 'destroy'])->name('conversations.destroy');
    Route::get('c/{conversation}/export.md', ConversationExportController::class)
        ->middleware('throttle:30,1,export:')
        ->name('conversations.export');
    Route::get('assistants', [AssistantGalleryController::class, 'index'])->name('assistants.index');
    Route::get('assistants/{slug}', [AssistantGalleryController::class, 'show'])
        ->where('slug', '[a-z0-9-]+')
        ->name('assistants.show');
    Route::get('search', SearchController::class)
        ->middleware('throttle:60,1,search:')
        ->name('search');

    Route::post('chat/messages', [MessageController::class, 'store'])
        ->middleware('throttle:60,1,messages:')
        ->name('messages.store');
    Route::post('chat/messages/{message}/regenerate', [MessageController::class, 'regenerate'])
        ->middleware('throttle:60,1,messages:')
        ->name('messages.regenerate');
    Route::post('chat/attachments', [AttachmentController::class, 'store'])
        ->middleware('throttle:uploads')
        ->name('attachments.store');
    Route::get('chat/attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
    Route::delete('chat/attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');
    Route::post('chat/messages/{message}/cancel', [MessageController::class, 'cancel'])
        ->middleware('throttle:120,1,messages-cancel:')
        ->name('messages.cancel');

    Route::put('chat/messages/{message}/feedback', MessageFeedbackController::class)
        ->middleware('throttle:60,1,messages-feedback:')
        ->name('messages.feedback');

    // Read-only links to a conversation (docs/sharing.md).
    Route::post('c/{conversation}/shares', [ConversationShareController::class, 'store'])
        ->middleware('throttle:20,1,shares:')
        ->name('conversations.shares.store');
    Route::delete('shares/{share}', [ConversationShareController::class, 'destroy'])->name('shares.destroy');
    Route::get('s/{token}', [ConversationShareController::class, 'show'])
        ->where('token', '[A-Za-z0-9_-]{43}')
        ->middleware('throttle:60,1,share-views:')
        ->name('shares.show');
    Route::post('s/{token}/copy', [ConversationShareController::class, 'copy'])
        ->where('token', '[A-Za-z0-9_-]{43}')
        ->middleware('throttle:20,1,share-copies:')
        ->name('shares.copy');
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
