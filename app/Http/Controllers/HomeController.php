<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Services\IdentityProviderRegistry;
use App\Domain\Institution\Services\ContentTexts;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Chat\ConversationController;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "/": the chat for signed-in users, a short landing page for everyone
 * else. The landing page shows nothing beyond the institution's branding
 * and the way in: its sign-in button goes straight to the identity
 * provider (one button per provider when there are several).
 */
class HomeController extends Controller
{
    public function __invoke(Request $request, ConversationController $chat, IdentityProviderRegistry $providers, ContentTexts $texts, InstitutionSettings $institution): Response
    {
        if ($request->user() !== null) {
            return $chat->index($request);
        }

        return Inertia::render('welcome', [
            'texts' => $texts->landing(app()->getLocale(), [
                'app' => (string) config('app.name'),
                'institution' => $institution->name,
            ]),
            ...LoginController::options($providers),
        ]);
    }
}
