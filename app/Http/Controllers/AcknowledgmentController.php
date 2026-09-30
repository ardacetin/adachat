<?php

namespace App\Http\Controllers;

use App\Domain\Institution\Settings\PrivacySettings;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The usage notice users acknowledge on their first sign-in, and again
 * whenever an administrator asks everyone to (a new version).
 */
class AcknowledgmentController extends Controller
{
    public function show(Request $request, PrivacySettings $privacy): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $privacy->acknowledgment_enabled || ($user->acknowledged_version ?? 0) >= $privacy->acknowledgment_version) {
            return to_route('home');
        }

        $custom = $privacy->acknowledgment_text[app()->getLocale()] ?? null;

        return Inertia::render('auth/acknowledgment', [
            // Null: the built-in, translated notice.
            'text' => is_string($custom) && trim($custom) !== '' ? $custom : null,
        ]);
    }

    public function store(Request $request, PrivacySettings $privacy): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->forceFill([
            'acknowledged_version' => $privacy->acknowledgment_version,
            'acknowledged_at' => now(),
        ])->save();

        return redirect()->intended(route('home'));
    }
}
