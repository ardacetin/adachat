<?php

namespace App\Domain\Identity\Providers;

use App\Domain\Identity\Contracts\RedirectIdentityProvider;
use App\Domain\Identity\Data\ExternalIdentity;
use App\Domain\Identity\Exceptions\IdentityRejected;
use App\Domain\Identity\Exceptions\RejectionReason;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Contracts\Factory as Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Google Workspace sign-in (OAuth 2.0 / OpenID Connect) via Socialite.
 *
 * Claims are read from Google's userinfo endpoint using the access token
 * obtained server-side from the authorization code, never from the browser.
 */
final class GoogleIdentityProvider implements RedirectIdentityProvider
{
    /**
     * @param  list<string>  $allowedDomains
     */
    public function __construct(
        private readonly Socialite $socialite,
        private readonly array $allowedDomains,
    ) {}

    public function key(): string
    {
        return 'google';
    }

    public function isEnabled(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));
    }

    public function requiresHostedDomain(): bool
    {
        // Only Google Workspace accounts carry "hd"; personal accounts never do.
        return true;
    }

    public function redirect(Request $request): RedirectResponse
    {
        $parameters = ['prompt' => 'select_account'];

        // "hd" only pre-selects the account chooser; it is not a security control.
        if (count($this->allowedDomains) === 1) {
            $parameters['hd'] = $this->allowedDomains[0];
        }

        return $this->driver()
            ->scopes(['openid', 'email', 'profile'])
            ->with($parameters)
            ->redirect();
    }

    public function resolveCallback(Request $request): ExternalIdentity
    {
        if ($request->filled('error')) {
            throw new IdentityRejected(RejectionReason::ProviderError);
        }

        try {
            $user = $this->driver()->user();
        } catch (InvalidStateException $exception) {
            throw new IdentityRejected(RejectionReason::InvalidState, $exception);
        } catch (GuzzleException $exception) {
            throw new IdentityRejected(RejectionReason::ProviderError, $exception);
        }

        return $this->toExternalIdentity($user);
    }

    public function toExternalIdentity(AbstractUser $user): ExternalIdentity
    {
        /** @var array<string, mixed> $claims */
        $claims = $user->getRaw();

        $subject = self::stringClaim($claims, 'sub');
        $email = self::stringClaim($claims, 'email');

        if ($subject === null || $email === null) {
            throw new IdentityRejected(RejectionReason::ProviderError);
        }

        $hostedDomain = self::stringClaim($claims, 'hd');
        $emailVerified = ($claims['email_verified'] ?? false) === true;
        $name = self::stringClaim($claims, 'name') ?? $email;
        $picture = self::stringClaim($claims, 'picture');

        return new ExternalIdentity(
            provider: $this->key(),
            subject: $subject,
            email: mb_strtolower($email),
            emailVerified: $emailVerified,
            hostedDomain: $hostedDomain,
            name: $name,
            avatarUrl: $picture,
            safeClaims: [
                'hd' => $hostedDomain,
                'email_verified' => $emailVerified,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private static function stringClaim(array $claims, string $name): ?string
    {
        $value = $claims[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function driver(): AbstractProvider
    {
        /** @var AbstractProvider $driver */
        $driver = $this->socialite->driver('google');

        return $driver;
    }
}
