# Authentication

> Status: **Implemented** — M2 (Google OAuth), replaced by SAML 2.0 with a
> Google Workspace SAML app; allowed domains are admin-managed since M3.
> Location: `app/Domain/Identity`.

V1 signs users in with **SAML 2.0** against the institution's identity
provider — a **custom SAML app in the Google Workspace admin console** —
restricted to configured e-mail domains. There is **no password login**. The
protocol sits behind `RedirectIdentityProvider`, so generic OIDC, Microsoft
Entra ID and LDAP can be added as adapters.

> Changed after M5: the first design used Google OAuth (Socialite, client ID
> and secret). The institution chose a Google Workspace SAML app instead; the
> OAuth adapter and `laravel/socialite` were removed.

## 1. V1 flow (SP-initiated SAML, Google Workspace)

```
Browser                    Ada (SP)                              Google (IdP)
   │ GET /auth/saml/redirect  │                                     │
   │─────────────────────────►│ build AuthnRequest (ID stored in    │
   │                          │ the cache for 10 min, single use)   │
   │◄──── 302 SSO URL?SAMLRequest (HTTP-Redirect binding) ──────────│
   │──────────────────────────────── sign-in / existing session ───►│
   │◄──────────────────────── auto-submitted form (HTTP-POST binding)
   │ POST /auth/saml/acs      │                                     │
   │─────────────────────────►│ 1. InResponseTo = a request Ada made│
   │                          │    (pulled from the cache: no replay)
   │                          │ 2. validate signature, issuer,      │
   │                          │    audience, destination, recipient,│
   │                          │    validity window (onelogin/php-saml)
   │                          │ 3. NameID (e-mail) + name attributes│
   │                          │ 4. enforce domain policy            │
   │                          │ 5. find/provision user              │
   │                          │ 6. reject disabled users            │
   │                          │ 7. session regenerate + login       │
   │◄──── 302 / (chat) ───────│                                     │
```

IdP-initiated sign-in (opening Ada from the Google apps menu) sends a
response Ada never asked for. It is **not** trusted: Ada answers with a
redirect to `/auth/saml/redirect`, and the SP-initiated round trip completes
without user interaction while the Google session exists.

### Server-side validation

Implemented in `Providers\SamlIdentityProvider` with
[onelogin/php-saml](https://github.com/SAML-Toolkits/php-saml) in strict mode:

1. `InResponseTo` names an AuthnRequest this Ada instance issued in the last
   10 minutes; the ID is removed on first use, so a captured response cannot
   be replayed. (The request ID is kept in the **cache**, not the session:
   browsers do not send the `SameSite=Lax` session cookie with the IdP's
   cross-site POST. `CACHE_STORE` must therefore be shared — database or
   redis, not `array`; `ada:install` warns.)
2. The response **or** the assertion is signed with the configured IdP
   certificate (unsigned responses are always rejected); XML is schema-
   validated and DOCTYPEs are refused (no XXE).
3. Issuer = IdP entity ID; Audience = Ada's entity ID; Destination and
   Recipient = Ada's ACS URL (derived from `APP_URL`, so it also works behind
   a TLS-terminating proxy); `NotBefore`/`NotOnOrAfter` with 3 minutes of
   clock drift.
4. The NameID is a valid e-mail address; its domain is in
   `AuthSettings.allowed_domains`. SAML has no `hd` claim — the IdP only
   asserts accounts of its own organisation, and the app can be limited to
   organisational units in Google Admin.

The ACS route is exempt from CSRF tokens (the IdP posts cross-site); the
checks above authenticate the request instead.

Failures raise `IdentityRejected` with a `RejectionReason` and redirect to
the login page with a translated, non-revealing message
(`auth.errors.<reason>`): `invalid_state` (unknown/expired/replayed request),
`provider_error` (invalid response; the exact reason is logged),
`domain_not_allowed`, `not_provisioned`, `account_disabled`,
`account_conflict`. Rejections are logged with provider, reason and e-mail
**domain** only.

### Account linking and provisioning

- Lookup by `user_identities (provider='saml', subject=<NameID e-mail>)`.
  Google's NameID is the primary e-mail; if a user's address is renamed in
  Workspace, the new address is a new subject and links by e-mail as below.
- If no identity exists and `AuthSettings.auto_provision` is true: create the
  user (role `user`, default group) and the identity in one transaction.
- Linking an existing user row by e-mail (pre-created by an admin or by
  `ada:user:promote`, or signed in earlier with the removed OAuth adapter)
  happens when no SAML identity is attached yet and the e-mail matches
  exactly.
- The name comes from the `first_name` / `last_name` attributes (names
  configurable), otherwise the e-mail's local part; it is refreshed on each
  login.
- Same e-mail already linked to a different subject: `account_conflict`; an
  admin resolves it. Ada never merges accounts automatically.

### Configuration

| Setting | Where | Why |
|---|---|---|
| `SAML_IDP_ENTITY_ID`, `SAML_IDP_SSO_URL`, `SAML_IDP_CERT` (or `SAML_IDP_CERT_PATH`) | `.env` | Needed before anyone can log in; values from the IdP metadata. |
| `SAML_ATTRIBUTE_FIRST_NAME`, `SAML_ATTRIBUTE_LAST_NAME`, `SAML_LOGIN_LABEL` | `.env` (optional) | Attribute names mapped in the SAML app; button label (default "Google"). |
| `allowed_domains` | `AuthSettings` (DB), initial value from `AUTH_ALLOWED_DOMAINS` | Admin-manageable, multiple domains supported. |
| `auto_provision` | `AuthSettings`, initial value from `AUTH_AUTO_PROVISION` | Allows pre-registration-only deployments. |

Ada's own SP values are derived from `APP_URL` (which must be the public
`https://` address):

| Value | URL |
|---|---|
| ACS URL | `https://<your-ada-host>/auth/saml/acs` |
| Entity ID (also the SP metadata URL) | `https://<your-ada-host>/auth/saml/metadata` |

**Administration → Sign-in** shows both with copy buttons, together with the
IdP values in use and the certificate's SHA-256 fingerprint and expiry date;
`php artisan ada:install` prints them too.

### Setting up the Google Workspace SAML app

1. Google Admin → **Apps → Web and mobile apps → Add app → Add custom SAML
   app**; name it (e.g. "Ada Chat").
2. On *Google Identity Provider details*, **download the metadata** (or copy
   the SSO URL, Entity ID and certificate) and set in Ada's `.env`:
   `SAML_IDP_SSO_URL`, `SAML_IDP_ENTITY_ID`, and the certificate in
   `SAML_IDP_CERT` (PEM, one line or with `\n`) or as a file in
   `SAML_IDP_CERT_PATH` (a file path in `SAML_IDP_CERT` is accepted too; the
   file must be readable by the PHP user). Google's values look like
   `SAML_IDP_SSO_URL=https://accounts.google.com/o/saml2/idp?idpid=…` and
   `SAML_IDP_ENTITY_ID=https://accounts.google.com/o/saml2?idpid=…` — the
   SSO URL is the one with `/idp`.
3. *Service provider details*: **ACS URL** and **Entity ID** as above;
   *Start URL* empty; **Signed response** optional (Ada accepts a signed
   response or a signed assertion); **Name ID format `EMAIL`**, **Name ID
   *Basic Information → Primary email***.
4. *Attribute mapping* (optional, for display names): *First name →
   `first_name`*, *Last name → `last_name`*.
5. Turn the app **ON** for the organisational units / groups that may use
   Ada (User access). Google rejects everyone else before Ada sees them.
6. Set `AUTH_ALLOWED_DOMAINS`, run `php artisan config:clear` (or
   `optimize`) and `php artisan ada:install` to check the configuration.

If the login page still says sign-in is not configured, the running
configuration lacks one of the three IdP values or the certificate file is
not readable: check with `php artisan config:show ada.auth.saml` and clear a
cached configuration (`php artisan config:clear`, or `config:cache` again)
after every `.env` change.

Google's SAML certificate is valid for five years; renew it in Google Admin
before the expiry date shown in the admin panel and update `.env`.

## 2. Bootstrapping and recovery

- `php artisan ada:install` ensures the default group exists and reports
  missing configuration (APP_KEY, allowed domains, identity provider). From
  M3 on it also seeds settings; the default budget policy follows in M5.
  The default group itself is created by its migration, so it always exists.
- `php artisan ada:user:promote someone@example.edu --role=super_admin`
  creates or updates a user record so that their first sign-in gets the
  role. This is also the **break-glass** path: there is no password login to
  fall back to, and server access is the recovery mechanism.
- `php artisan ada:user:budget someone@example.edu --limit=25` sets an
  individual monthly limit (`--clear` returns to the group policy; the
  current month is updated unless `--next-period` is given). Audited.
- Development only: a `POST /dev/login` route to sign in as any existing
  user without the IdP. It is registered only when `APP_ENV` is `local` or
  `testing` **and** `ADA_DEV_LOGIN=true`; the controller re-checks both.
  `ada:doctor` (M10) fails if it is reachable in production.

## 3. Sessions

- Driver: Redis in production, database in development.
- Cookies: `Secure`, `HttpOnly`, `SameSite=Lax`. The SAML ACS POST is
  cross-site, so it does not carry the session cookie; SAML request state
  lives in the cache and the login creates a fresh session.
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
    public function key(): string;                          // 'saml' (V1), later e.g. 'oidc-entra'
    public function label(): string;                        // sign-in button, e.g. "Google"
    public function isEnabled(): bool;                      // configured → offered on the login page
    public function requiresHostedDomain(): bool;           // Google OAuth "hd" check (false for SAML)
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
| Other SAML IdPs | `SamlIdentityProvider` already works with any SAML 2.0 IdP; several IdPs would need per-IdP keys and settings |
| LDAP / AD | `CredentialIdentityProvider` using LdapRecord; password form shown only when enabled; login throttling |

`AuthSettings` would then hold a list of enabled providers; the login page
renders one button per redirect provider.

## 6. Tests

- `tests/Feature/Auth/SamlSignInTest.php` plays the IdP: it generates a key
  pair, answers Ada's real AuthnRequest with a signed response and posts it
  to the ACS. Covered: sign-in with a signed response and with a signed
  assertion only; replay refused; responses to unknown requests refused;
  unsolicited responses restart an SP-initiated sign-in; unsigned, tampered,
  wrong key, wrong audience, destination or issuer, and expired responses
  refused; domain policy applied; SP metadata; admin page values.
- `ExternalLoginTest` (protocol-independent, with a fake provider):
  provisioning, linking, domain policy, disabled users, `account_conflict`,
  `not_provisioned`, session regeneration.
- `RolesTest`: gates per role; `DevLoginTest`: `/dev/login` only in
  `local`/`testing` with `ADA_DEV_LOGIN` on.
