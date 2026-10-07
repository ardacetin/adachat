<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The language switch on the landing and sign-in pages. A visitor's choice
 * is kept in a cookie; a signed-in user's is saved as their preference,
 * like Settings → Language.
 */
class LocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(config('ada.locales.available'))],
        ]);
        $locale = $validated['locale'];

        $user = $request->user();

        if ($user !== null) {
            $user->forceFill(['locale' => $locale])->save();
        }

        // One year; read by SetLocale only while no user preference applies.
        return back()->withCookie(cookie(SetLocale::COOKIE, $locale, 60 * 24 * 365));
    }
}
