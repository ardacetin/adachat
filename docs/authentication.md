# Authentication

> Status: **Implemented (M2)**; allowed domains are admin-managed since M3.
> Location: `app/Domain/Identity`.

V1 supports **Google Workspace sign-in (OAuth 2.0 / OpenID Connect)** only,
restricted to configured domains. There is **no password login**. The
design keeps Google behind an interface so that generic OIDC, Microsoft Entra
ID, SAML and LDAP can be added as adapters.

## 1. V1 flow (Google Workspace)

```
Browser                    Ada                                   Google
   │  GET /auth/google        │                                     │
   │─────────────────────────►│ generate state (session),           │
   │                          │ build URL: scope=openid email profile,
   │                          │ hd=<first allowed domain> (UX hint), │
   │                          │ prompt=select_account               │
   │◄──── 302 ────────────────│                                     │
   │──────────────────────────────── consent / account chooser ────►│
   │◄──────────────────────────────── 302 /auth/google/callback?code&state
   │  GET callback            │                                     │
   │─────────────────────────►│ 1. validate state (Socialite)       │
   │                          │ 2. exchange code (server-to-server) ├──► token endpoint
   │                          │ 3. read verified claims             │
   │                          │ 4. enforce domain policy            │
   │                          │ 5. find/provision user              │
   │                          │ 6. reject disabled users            │
   │                          │ 7. session regenerate + login       │
   │◄──── 302 / (chat) ───────│ 8. audit/login timestamp            │
```

### Server-side validation (never trust the e-mail string alone)

After the code exchange over TLS, the claims come from Google's ID token /
userinfo response, not from the browser. Ada requires **all** of:

1. `state` matches the session value (Socialite; stateless mode is **not**
   used).
2. `email_verified === true`.
3. `hd` claim is present and is in `AuthSettings.allowed_domains`
   (case-insensitive). `hd` is only set for Google Workspace accounts, so
   personal `@gmail.com` accounts are rejected even if an allowed domain
   were misconfigured.
4. The domain part of `email` is also in `allowed_domains`.
5. A stable `sub` is present.

The `hd` request parameter only pre-selects the account chooser; it is a UX
hint, not a security control.

Failures raise `IdentityRejected` with a `RejectionReason` and redirect to
the login page with a translated, non-revealing message
(`auth.errors.<reason>`): `invalid_state`, `provider_error`,
`email_not_verified`, `domain_not_allowed`, `not_provisioned`,
`account_disabled`, `account_conflict`. Rejections are logged with provider,
reason and e-mail **domain** only (never tokens or full addresses).

### Account linking and provisioning

- Lookup by `user_identities (provider='google', subject=sub)`.
- If no identity exists and `AuthSettings.auto_provision` is true: create the
  user (role `user`, default group) and the identity in one transaction.
- Linking an existing user row by e-mail (e.g. pre-created by an admin or by
  `ada:user:promote`) happens only when the e-mail matches exactly **and**
  the checks above passed.
- Name and avatar are refreshed on each login; e-mail changes at the IdP
  update `user_identities.email` and `users.email`.
- If the verified e-mail already belongs to a user linked to a *different*
  subject at the same provider (e.g. a deleted and recreated Google account),
  sign-in is refused with `account_conflict`; an admin resolves it. Ada never
  merges accounts automatically.

### Configuration

| Setting | Where | Why |
|---|---|---|
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, redirect URL | `.env` | Needed before anyone can log in; a secret must not depend on an admin UI that requires login. |
| `allowed_domains` | `AuthSettings` (DB), initial value from `AUTH_ALLOWED_DOMAINS` in `.env` | Admin-manageable, multiple domains supported. |
| `auto_provision` | `AuthSettings`, initial value from `AUTH_AUTO_PROVISION` | Allows pre-registration-only deployments. |

Changes to auth settings are audit-logged. Removing all domains is rejected.

### Setting up Google sign-in

1. In Google Cloud Console, create (or pick) a project owned by the
   institution's Google Workspace organisation.
2. Configure the OAuth consent screen with user type **Internal**. This makes
   Google itself refuse accounts outside the organisation — a second layer on
   top of Ada's own domain checks.
3. Create an OAuth client ID of type *Web application* with the authorised
   redirect URI `https://<your-ada-host>/auth/google/callback`.
4. Set `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` and `AUTH_ALLOWED_DOMAINS`
   in `.env`, then run `php artisan ada:install` to check the configuration.

Since M3 both values live in `AuthSettings` (database) and are edited under
**Administration → Sign-in** by super admins; `.env` only seeds them on the
first migration.

## 2. Bootstrapping and recovery

- `php artisan ada:install` ensures the default group exists and reports
  missing configuration (APP_KEY, allowed domains, identity provider). From
  M3 on it also seeds settings; the default budget policy follows in M5.
  The default group itself is created by its migration, so it always exists.
- `php artisan ada:user:promote someone@example.edu --role=super_admin`
  creates or updates a user record so that their first Google login gets the
  role. This is also the **break-glass** path: there is no password login to
  fall back to, and server access is the recovery mechanism.
- Development only: a `POST /dev/login` route to sign in as any existing
  user without Google. It is registered only when `APP_ENV` is `local` or
  `testing` **and** `ADA_DEV_LOGIN=true`; the controller re-checks both.
  `ada:doctor` (M10) fails if it is reachable in production.

## 3. Sessions

- Driver: Redis in production, database in development.
- Cookies: `Secure`, `HttpOnly`, `SameSite=Lax` (Lax is required so the
  OAuth callback carries the session cookie).
- `session()->regenerate()` on login; full invalidation on logout.
- Lifetime configurable (default 8 h idle, 7 d absolute with "remember").
- `EnsureUserIsActive` middleware on all authenticated routes: disabled users
  are logged out on their next request; disabling a user also deletes their
  sessions (database/Redis session lookup by user id).

## 4. Roles and authorization

| Role | Abilities |
|---|---|
| `super_admin` | Everything `admin` can do + system/auth/institution settings, providers & credentials, models & aliases, budget policies, manual budget adjustments, role changes |
| `admin` | Users (group, disable, budget override within policy), groups, usage reports, audit log (read) |
| `user` | Chat, own conversations, own usage and budget |

- Implemented with Laravel **Gates** (role → ability) and **Policies** per
  model. Controllers call `authorize()`; Inertia shares a computed `can`
  object for UI visibility only (never as the security boundary).
- **No role can read other users' conversations.** `ConversationPolicy` allows
  only the owner; there is no admin route returning message content.
- Admins cannot promote to `super_admin`, cannot change their own role, and
  the last `super_admin` cannot be demoted or disabled.

## 5. Abstraction for future providers

```php
namespace App\Domain\Identity\Contracts;

/** Redirect-based protocols: OAuth2/OIDC, SAML */
interface RedirectIdentityProvider
{
    public function key(): string;                          // 'google', 'oidc-entra', 'saml-university'
    public function isEnabled(): bool;                      // configured → offered on the login page
    public function requiresHostedDomain(): bool;           // Google Workspace "hd" check
    public function redirect(Request $request): RedirectResponse;
    public function resolveCallback(Request $request): ExternalIdentity;  // throws IdentityRejected
}

/** Credential-based protocols: LDAP / Active Directory */
interface CredentialIdentityProvider
{
    public function key(): string;
    public function authenticate(string $username, string $password): ExternalIdentity;
}

final readonly class ExternalIdentity
{
    public function __construct(
        public string $provider,
        public string $subject,
        public string $email,
        public bool $emailVerified,
        public ?string $hostedDomain,
        public string $name,
        public ?string $avatarUrl,
        public array $safeClaims,      // non-secret claims for troubleshooting
        public array $groupHints = [], // future: IdP groups → Ada group mapping
    ) {}
}
```

The protocol-independent `LoginUser` action takes an `ExternalIdentity` and
applies: domain policy → identity lookup/linking → provisioning → active check
→ session login → audit. Each provider adapter only has to produce a trusted
`ExternalIdentity`.

| Future provider | Adapter approach |
|---|---|
| Generic OIDC | Discovery document, ID token signature validation (JWKS), `nonce`, configurable claim mapping |
| Microsoft Entra ID | OIDC adapter preset; tenant restriction via `tid` claim in addition to domain |
| SAML 2.0 | SAML package adapter (signed assertions required, audience/recipient checks) |
| LDAP / AD | `CredentialIdentityProvider` using LdapRecord; password form shown only when enabled; login throttling |

`AuthSettings` would then hold a list of enabled providers; the login page
renders one button per redirect provider.

## 6. Tests

- Allowed domain accepted; other domain rejected; `@gmail.com` (no `hd`)
  rejected; `hd` present but e-mail domain mismatched rejected.
- `email_verified = false` rejected.
- Invalid/missing `state` rejected.
- First login provisions user with default group and role; second login
  reuses identity by `sub` even after e-mail change.
- Disabled user cannot log in and is logged out on next request.
- Role matrix for every admin route; last super admin protection.
- `/dev/login` absent outside `local`/`testing` or when `ADA_DEV_LOGIN` is off.
