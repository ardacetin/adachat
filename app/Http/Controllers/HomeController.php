<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Contracts\RedirectIdentityProvider;
use App\Domain\Identity\Services\IdentityProviderRegistry;
use App\Http\Controllers\Chat\ConversationController;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "/": the chat for signed-in users, a short landing page for everyone
 * else. The landing page shows nothing beyond the institution's branding
 * and the sign-in providers; signing in happens on /login.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request, ConversationController $chat, IdentityProviderRegistry $providers): Response
    {
        if ($request->user() !== null) {
            return $chat->index($request);
        }

        return Inertia::render('welcome', [
            'providers' => array_map(
                fn (RedirectIdentityProvider $provider): array => ['key' => $provider->key(), 'label' => $provider->label()],
                $providers->enabled(),
            ),
        ]);
    }
}
