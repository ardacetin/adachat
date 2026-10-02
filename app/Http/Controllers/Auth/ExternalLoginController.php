<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\Actions\LoginUser;
use App\Domain\Identity\Contracts\RedirectIdentityProvider;
use App\Domain\Identity\Exceptions\IdentityRejected;
use App\Domain\Identity\Exceptions\SignInMustRestart;
use App\Domain\Identity\Providers\SamlIdentityProvider;
use App\Domain\Identity\Services\IdentityProviderRegistry;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

/**
 * Sign-in through redirect-based identity providers: SAML 2.0 (Google
 * Workspace) and OpenID Connect (Microsoft Entra ID and others).
 */
class ExternalLoginController extends Controller
{
    public function __construct(private readonly IdentityProviderRegistry $providers) {}

    public function redirect(Request $request, string $provider): SymfonyRedirectResponse
    {
        return $this->provider($provider)->redirect($request);
    }

    /**
     * The IdP's answer: the SAML assertion consumer service (HTTP-POST
     * binding) or the OpenID Connect redirect URI (GET with the code).
     */
    public function callback(Request $request, string $provider, LoginUser $loginUser): RedirectResponse
    {
        $identityProvider = $this->provider($provider);

        try {
            $identity = $identityProvider->resolveCallback($request);
            $user = $loginUser->handle($identity, $identityProvider->requiresHostedDomain());
        } catch (SignInMustRestart) {
            return redirect()->route('auth.redirect', $provider);
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
        EnsureUserIsActive::startSession($request);

        return redirect()->intended(route('home'));
    }

    /**
     * SAML service provider metadata; its URL is also Ada's SP entity ID.
     */
    public function samlMetadata(SamlIdentityProvider $saml): Response
    {
        return response($saml->metadata(), 200, ['Content-Type' => 'application/samlmetadata+xml']);
    }

    private function provider(string $key): RedirectIdentityProvider
    {
        $provider = $this->providers->find($key);

        abort_if($provider === null, 404);

        return $provider;
    }
}
