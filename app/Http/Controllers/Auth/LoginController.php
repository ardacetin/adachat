<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\Contracts\RedirectIdentityProvider;
use App\Domain\Identity\Services\IdentityProviderRegistry;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class LoginController extends Controller
{
    /**
     * /login is where guests are sent and where a refused sign-in returns.
     * With a single identity provider it goes straight to it, so a link or
     * an expired session signs in with one click. A refused sign-in (an
     * error to show) goes to the landing page instead: the provider would
     * sign the user in again at once and refuse them again. So do several
     * providers, to choose from, and the development login.
     */
    public function show(Request $request, IdentityProviderRegistry $providers): SymfonyResponse
    {
        $enabled = $providers->enabled();

        if (count($enabled) === 1 && ! $request->session()->has('errors') && ! DevLoginController::isEnabled()) {
            $target = route('auth.redirect', $enabled[0]->key());

            // An Inertia visit cannot follow a redirect to another site.
            return $request->header('X-Inertia') !== null ? Inertia::location($target) : redirect()->to($target);
        }

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
