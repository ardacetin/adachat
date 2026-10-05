# Authentication

> Status: **Implemented** — M2 (Google OAuth), replaced by SAML 2.0 with a
> Google Workspace SAML app; allowed domains are admin-managed since M3;
> OpenID Connect with a Microsoft Entra ID preset since v1.2 (§1a).
> Location: `app/Domain/Identity`.

Ada signs users in with **SAML 2.0** against the institution's identity
provider — a **custom SAML app in the Google Workspace admin console** —
and/or with **OpenID Connect** (Microsoft Entra ID, Keycloak, Okta…),
restricted to configured e-mail domains. Both can be on at the same time;
the login page shows one button per provider. There is **no password
login**. The protocols sit behind `RedirectIdentityProvider`; LDAP can be
added as an adapter.

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

   The request is also bound to the browser that started it: the redirect
   sets a random value in the `ada_saml_binding` cookie (10 minutes,
   path `/auth/saml/acs`, `Secure`, `HttpOnly`, `SameSite=None` so it
   comes back with the IdP's POST), and the cache keeps its SHA-256 hash
   next to the request ID. A response posted from a browser without that
   cookie is refused, so nobody can start a sign-in and have another
   browser complete it (login CSRF). Ada must therefore be served over
   HTTPS; browsers accept `Secure` cookies on `http://localhost` only.
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
- **Reused addresses.** With the e-mail as subject, Ada cannot tell a new
  holder of an address from the previous one: whoever the IdP asserts for
  `j.smith@…` signs into the account of `j.smith@…`, with its role, budget
  and conversations. A disabled account is refused (`account_disabled`), so
  **disable the Ada account of everyone who leaves**, and of the old address
  after a rename, before the address can be given to someone else. Better,
  send an identifier that is never reused as an attribute and set
  `SAML_ATTRIBUTE_SUBJECT` to its name (Google Admin: map *Employee
  details → Employee ID* or a custom attribute; Entra ID: `user.objectid`).
  The account then follows that identifier: a renamed person keeps their
  account, and a new holder of an old address gets `account_conflict`
  instead of the previous holder's account. Accounts stored under the
  address move to the identifier on their next sign-in, so disable leavers'
  accounts before switching. Responses without the attribute are refused.
  `ada:doctor` warns while it is not set.
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

### Adding users (who may sign in)

Two settings decide who gets in, both in the administration panel:

- **Administration → Sign-in → "Anyone with an address in an allowed
  domain can sign in"** (`AuthSettings.auto_provision`). On: every verified
  address in the allowed domains gets an account on its first sign-in. Off:
  only the users listed under Users can sign in.
- **Administration → Users → Add users.** Administrators enter e-mail
  addresses (one per line, optionally `Name <address>`), a group and, as a
  super administrator, a role. Ada creates the accounts (`invited_at`,
  `invited_by`, audit `user.invited`); the person signs in with the
  identity provider and the account is linked by the verified address.
  Addresses that already have an account are skipped.

Added addresses may sign in **even when their domain is not in the allowed
domains** (e.g. a guest lecturer with a partner address, when the identity
provider can authenticate them). The identity provider must still assert a
verified address. Until the first sign-in the user shows as "Invited"; such
an unused account can be removed again (audit `user.invitation_removed`).
Accounts that have been used are disabled instead, never deleted, so their
usage stays on record. Administrators cannot add administrators or remove
super administrators; `ada:user:promote` remains the break-glass path.

### Configuration

| Setting | Where | Why |
|---|---|---|
| `SAML_IDP_ENTITY_ID`, `SAML_IDP_SSO_URL`, `SAML_IDP_CERT` (or `SAML_IDP_CERT_PATH`) | `.env` | Needed before anyone can log in; values from the IdP metadata. |
| `SAML_ATTRIBUTE_FIRST_NAME`, `SAML_ATTRIBUTE_LAST_NAME`, `SAML_LOGIN_LABEL` | `.env` (optional) | Attribute names mapped in the SAML app; button label (default "Google"). |
| `SAML_ATTRIBUTE_SUBJECT` | `.env` (recommended) | Attribute with an identifier that is never reused (e.g. `employee_id`). Without it the e-mail address identifies the account (see *Reused addresses*). |
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

## 1a. OpenID Connect (Microsoft Entra ID, generic)

`OidcIdentityProvider` implements the authorization code flow with PKCE:

```
Browser                      Ada                                  IdP
  │ GET /auth/oidc/redirect   │                                     │
  │──────────────────────────▶│ state, nonce, PKCE verifier → session (one-time, 10 min)
  │ 302 authorization_endpoint?response_type=code&state&nonce&code_challenge (S256)
  │◀──────────────────────────│                                     │
  │──────────────────────────────────────────────────────────────▶│ sign-in
  │ 302 /auth/oidc/callback?code&state                              │
  │◀──────────────────────────────────────────────────────────────│
  │──────────────────────────▶│ state must match a pending sign-in (then removed)
  │                           │ POST token_endpoint (code, verifier, client secret)
  │                           │──────────────────────────────────▶│
  │                           │◀──────────────── id_token ─────────│
  │                           │ verify signature (JWKS) and claims → LoginUser
```

- **Discovery:** `{OIDC_ISSUER}/.well-known/openid-configuration`; its
  `issuer` must equal `OIDC_ISSUER` exactly. The document and the key set
  (`jwks_uri`) are cached for an hour; a token signed with an unknown key ID
  makes Ada fetch the key set again once (key rotation).
- **ID token:** the signature is verified with PHP's OpenSSL extension
  (`App\Domain\Identity\Oidc\IdTokenVerifier`). Only **RS256** and **ES256**
  are accepted; `none`, HMAC (`HS256`, which anyone holding the client secret
  could forge) and tokens with `crit` headers are refused. Checked claims:
  `iss`, `aud` (must contain the client ID), `azp` (when present or with
  several audiences), `exp`, `nbf` and `iat` with 60 seconds of clock
  leeway, `nonce`, a non-empty `sub`.
- **Client authentication** at the token endpoint: `client_secret_basic`
  when the provider supports it (the default), otherwise
  `client_secret_post`.
- **Identity:** `subject` = `sub`. Generic preset: `email` with
  `email_verified=true` (otherwise `email_not_verified`). Name from `name`,
  else `given_name family_name`, else the e-mail's local part.
- Errors are logged with the failed check (e.g. `aud`), never with the token
  or the claims; users see "sign-in failed".

**Microsoft Entra ID preset** (`OIDC_PRESET=entra`):

- Only a **single-tenant** issuer is accepted:
  `https://login.microsoftonline.com/<tenant ID>/v2.0`. `common`,
  `organizations` and `consumers` are configuration errors. The `tid` claim
  must be that tenant.
- Entra has no `email_verified` claim, and the `email` attribute of an
  account can be set to an arbitrary address. Ada therefore uses `email`
  only together with the optional claim `xms_edov=true` ("e-mail domain
  owner verified"); otherwise it uses `preferred_username`, which for members
  of the tenant is the user principal name, whose domain must be verified in
  the tenant. **Guest accounts** (`idp` claim of another directory, `#EXT#`
  names) are refused.
- The allowed e-mail domains still apply on top of the tenant check.

**Account linking.** As with SAML, a first sign-in links an existing Ada
account with the same (verified, allowed-domain) e-mail address that has no
identity *at this provider* yet. A person who used Google SAML and later
signs in with Entra ID therefore keeps their account, budget and
conversations. Both identity providers must be the institution's own.

### Configuration (`.env`)

| Setting | Meaning |
|---|---|
| `OIDC_ENABLED` | `true` to offer the button. |
| `OIDC_ISSUER` | Entra: `https://login.microsoftonline.com/<tenant ID>/v2.0`; Keycloak: `https://<host>/realms/<realm>`; Okta: `https://<org>.okta.com` (or the authorization server's issuer). |
| `OIDC_CLIENT_ID`, `OIDC_CLIENT_SECRET` | From the app registration. The secret stays in `.env`; it is never stored in the database, shown or logged. |
| `OIDC_PRESET` | `entra` or `generic` (default). |
| `OIDC_LABEL` | Button text, default "Microsoft" (entra) or "SSO". |
| `OIDC_SCOPES` | Default `openid email profile`. |

Redirect URI to register: `https://<your-ada-host>/auth/oidc/callback`
(derived from `APP_URL`). **Administration → Sign-in** shows it with a copy
button, the values in use (client ID shortened, never the secret) and a
**Test connection** button that loads the discovery document and keys and
compares the clocks. `ada:doctor` runs the same checks; `ada:install` prints
the redirect URI.

### Setting up Microsoft Entra ID

1. Entra admin center → **App registrations → New registration**.
   - Supported account types: **Accounts in this organizational directory
     only** (single tenant).
   - Redirect URI: platform **Web**, `https://<your-ada-host>/auth/oidc/callback`.
2. **Overview:** copy the *Application (client) ID* (`OIDC_CLIENT_ID`) and
   the *Directory (tenant) ID* (for `OIDC_ISSUER`).
3. **Certificates & secrets → New client secret:** copy the value into
   `OIDC_CLIENT_SECRET`. Note the expiry date and renew it in time; with an
   expired secret sign-in fails and the log shows `invalid_client`.
4. **Token configuration → Add optional claim → ID:** `email` and
   `xms_edov` (so verified e-mail addresses can be used; without them Ada
   uses the user principal name).
5. Optional: **Enterprise applications → Ada → Properties → Assignment
   required = Yes**, then assign the pilot users or groups.
6. `.env`:

   ```
   OIDC_ENABLED=true
   OIDC_PRESET=entra
   OIDC_ISSUER=https://login.microsoftonline.com/<tenant ID>/v2.0
   OIDC_CLIENT_ID=<application ID>
   OIDC_CLIENT_SECRET=<secret value>
   ```

7. `php artisan config:cache` (if used), then Administration → Sign-in →
   **Test connection**, and `php artisan ada:doctor`.

### Generic providers (Keycloak, Okta, …)

Create a confidential web client with the authorization code flow, PKCE
allowed, and the redirect URI above. The provider must sign ID tokens with
RS256 or ES256 and include `email` and `email_verified` (Keycloak: the
`email` client scope; mark addresses verified or use a verified-email
policy). Set `OIDC_PRESET=generic` and `OIDC_ISSUER` to the issuer shown in
the provider's discovery document.

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
- As built (M8): `App\Policies\UserPolicy` (`update` = group and individual
  budget, `changeStatus`, `changeRole`, `adjustBudget`) and
  `App\Domain\Identity\Services\UserAdministration`. Administrators manage
  users and groups but not super administrators; nobody changes their own
  role or status; the last *active* super administrator cannot be demoted or
  disabled. Individual budgets have no cap (every change is audited).
  Disabling deletes the user's database sessions and rotates the "remember
  me" token. The audit log is readable by administrators; the user pages
  show accounts, budgets and usage, never conversation content.

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
| Generic OIDC | Implemented in v1.2 (§1a) |
| Microsoft Entra ID | Implemented in v1.2 as an OIDC preset (§1a) |
| Other SAML IdPs | `SamlIdentityProvider` already works with any SAML 2.0 IdP; several IdPs would need per-IdP keys and settings |
| LDAP / AD | `CredentialIdentityProvider` using LdapRecord; password form shown only when enabled; login throttling |

`AuthSettings` would then hold a list of enabled providers; the login page
renders one button per redirect provider.

## 6. Tests

- `tests/Feature/Auth/OidcSignInTest.php` plays the OpenID provider with
  `Http::fake` and generated RSA and EC keys. Covered: the authorization
  request (state, nonce, PKCE); sign-in with RS256 and ES256 tokens and the
  code redemption (verifier, client authentication); single-use state;
  unknown state; signature with another key, `alg=none`, HS256, wrong
  issuer, audience, authorized party, tenant or nonce, expired, not yet
  valid or future tokens, unknown key IDs, `crit` headers; key rotation;
  provider errors and refused code redemptions; the Entra e-mail rules
  (`xms_edov`, guests); `email_verified` for the generic preset; the domain
  policy; configuration errors; the admin page (no secret) and the
  connection test; `ada:doctor`.
- `tests/e2e/oidc.spec.ts` signs in through the OpenID provider played by
  `tests/e2e/mock-provider.mjs`.
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
