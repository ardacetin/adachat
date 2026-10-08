<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\Contracts\RedirectIdentityProvider;
use App\Domain\Identity\Services\IdentityProviderRegistry;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    /**
     * Sign-in starts on the landing page: /login (where guests are sent,
     * and where a refused sign-in returns) leads there, keeping the error
     * to show and the page the user wanted.
     */
    public function show(Request $request): RedirectResponse
    {
        $request->session()->reflash();

        return redirect()->route('home');
    }

    /**
     * The sign-in options for the landing page.
     *
     * @return array{providers: list<array{key: string, label: string}>, devLoginUsers: mixed}
     */
    public static function options(IdentityProviderRegistry $providers): array
    {
        return [
            'providers' => array_map(
                fn (RedirectIdentityProvider $provider): array => ['key' => $provider->key(), 'label' => $provider->label()],
                $providers->enabled(),
            ),
            'devLoginUsers' => DevLoginController::isEnabled()
                ? User::query()
                    ->orderBy('name')
                    ->limit(20)
                    ->get(['id', 'name', 'email', 'role'])
                : null,
        ];
    }

    /**
     * Sign the current user out.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
