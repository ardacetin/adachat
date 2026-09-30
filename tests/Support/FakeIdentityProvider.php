<?php

namespace Tests\Support;

use App\Domain\Identity\Contracts\RedirectIdentityProvider;
use App\Domain\Identity\Data\ExternalIdentity;
use App\Domain\Identity\Exceptions\IdentityRejected;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Identity provider double: returns a prepared identity (or rejection)
 * from the callback, so the whole sign-in flow can be tested without
 * talking to a real provider.
 */
final class FakeIdentityProvider implements RedirectIdentityProvider
{
    public ExternalIdentity|IdentityRejected|null $next = null;

    public function __construct(
        private readonly string $key = 'google',
        private readonly bool $requiresHostedDomain = true,
    ) {}

    public static function identity(array $overrides = []): ExternalIdentity
    {
        $values = array_merge([
            'provider' => 'google',
            'subject' => 'google-subject-1',
            'email' => 'ada@example.edu',
            'emailVerified' => true,
            'hostedDomain' => 'example.edu',
            'name' => 'Ada Lovelace',
            'avatarUrl' => 'https://example.edu/ada.png',
            'safeClaims' => ['hd' => 'example.edu', 'email_verified' => true],
        ], $overrides);

        return new ExternalIdentity(...$values);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'Fake IdP';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function requiresHostedDomain(): bool
    {
        return $this->requiresHostedDomain;
    }

    public function redirect(Request $request): RedirectResponse
    {
        return new RedirectResponse('https://idp.test/authorize');
    }

    public function resolveCallback(Request $request): ExternalIdentity
    {
        if ($this->next instanceof IdentityRejected) {
            throw $this->next;
        }

        return $this->next ?? self::identity();
    }
}
