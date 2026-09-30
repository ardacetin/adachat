<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\Actions\LoginUser;
use App\Domain\Identity\Contracts\RedirectIdentityProvider;
use App\Domain\Identity\Exceptions\IdentityRejected;
use App\Domain\Identity\Services\IdentityProviderRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

/**
 * Sign-in through redirect-based identity providers (Google in V1).
 */
class ExternalLoginController extends Controller
{
    public function __construct(private readonly IdentityProviderRegistry $providers) {}

    public function redirect(Request $request, string $provider): SymfonyRedirectResponse
    {
        return $this->provider($provider)->redirect($request);
    }

    public function callback(Request $request, string $provider, LoginUser $loginUser): RedirectResponse
    {
        $identityProvider = $this->provider($provider);

        try {
            $identity = $identityProvider->resolveCallback($request);
            $user = $loginUser->handle($identity, $identityProvider->requiresHostedDomain());
        } catch (IdentityRejected $rejection) {
            // Never log tokens or full e-mail addresses.
            Log::warning('Sign-in rejected.', [
                'provider' => $provider,
                'reason' => $rejection->reason->value,
                'domain' => isset($identity) ? $identity->emailDomain() : null,
            ]);

            return redirect()->route('login')->withErrors([
                'auth' => __('auth.errors.'.$rejection->reason->value),
            ]);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    private function provider(string $key): RedirectIdentityProvider
    {
        $provider = $this->providers->find($key);

        abort_if($provider === null, 404);

        return $provider;
    }
}
