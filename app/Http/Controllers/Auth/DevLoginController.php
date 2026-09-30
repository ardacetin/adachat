<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Password-less sign-in for local development and automated tests only.
 * The route is never registered outside the local and testing environments.
 */
class DevLoginController extends Controller
{
    public static function isEnabled(): bool
    {
        return app()->environment(['local', 'testing'])
            && config('ada.auth.dev_login') === true;
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(self::isEnabled(), 404);

        $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $user = User::query()->findOrFail($request->integer('user_id'));

        if (! $user->isActive()) {
            throw ValidationException::withMessages([
                'auth' => __('auth.account_disabled'),
            ]);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('home'));
    }
}
