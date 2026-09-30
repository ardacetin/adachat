# Security review (OWASP ASVS 4.0.3, Level 2 core)

> Internal review before V1 (roadmap M10). It maps the core ASVS Level 2
> chapters to how Ada meets them, with the evidence (code and tests) and the
> open items. It is not a substitute for an independent review, which
> institutions should commission before production use if they can.
>
> Reviewed: 2026-09-30, on `main` after M10. Threat model and controls:
> [security.md](security.md).

Legend: **Met** · **Partly** (met with a documented limitation or an open
item) · **Deployment** (the operator's configuration decides) · **N/A**.

## Summary

| Chapter | Status | Open items |
|---|---|---|
| V1 Architecture | Met | — |
| V2 Authentication | Met (delegated) | Enforce 2-step verification at the IdP (D1) |
| V3 Session management | Met | — |
| V4 Access control | Met | — |
| V5 Validation, sanitization, encoding | Met | — |
| V6 Stored cryptography | Met | Key rotation procedure documented (D2) |
| V7 Errors and logging | Partly | Structured JSON logs in production (F2) |
| V8 Data protection | Met | Content at rest is not encrypted by the app (accepted, see §V8) |
| V9 Communications | Deployment | HTTPS and proxy trust in the M11 deployment (F3) |
| V10 Malicious code | Met | — |
| V11 Business logic | Met | — |
| V12 Files and resources | Met | — |
| V13 API and web service | Met | — |
| V14 Configuration | Met | — |

## V1 Architecture, design and threat modeling — Met

- Documented architecture, trust boundaries and threat model:
  [architecture.md](architecture.md), [security.md](security.md) §1.
- Domain boundaries: authentication (`App\Domain\Identity`), budgets
  (`App\Domain\Budget`), providers (`App\Domain\AI`); controllers stay thin.
- Accounting is separated from content: `usage_events` never hold text
  ([database-design.md](database-design.md) §5.14).

## V2 Authentication — Met (delegated to the IdP)

- No passwords in Ada: sign-in only through SAML 2.0 with the institution's
  IdP (Google Workspace). Strict response validation (signature, issuer,
  audience, destination, validity window), single-use request IDs against
  replay, unsolicited responses restarted as SP-initiated
  (`SamlIdentityProvider`, `tests/Feature/Auth/SamlSignInTest.php`).
- Allowed e-mail domains and auto-provisioning are configurable; disabled
  accounts cannot sign in (`LoginUser`, `EnsureUserIsActive`).
- The development login exists only in `local`/`testing` and only when
  `ADA_DEV_LOGIN=true`; `ada:doctor` fails if it is on in production.
- **D1 (deployment):** multi-factor authentication is the IdP's job; require
  2-step verification in Google Admin for the users of the SAML app.

## V3 Session management — Met

- Server-side sessions (database/Redis), `HttpOnly`, `SameSite=Lax`,
  `Secure` behind https (`ada:doctor` warns otherwise); the session ID is
  regenerated at sign-in and invalidated at sign-out.
- Disabling a user deletes their sessions and rotates the "remember me"
  token (`UserAdministration`, tested in `UserAdminTest`).
- Idle timeout `SESSION_LIFETIME` (default 480 minutes) and an absolute
  lifetime after sign-in `SESSION_MAX_LIFETIME` (default 7 days), enforced
  by `EnsureUserIsActive` (`tests/Feature/Auth/SessionLifetimeTest.php`).
  F1 was found in this review and fixed before V1.

## V4 Access control — Met

- Deny by default: every route requires authentication except sign-in and
  the SAML endpoints; administration requires `access-admin`, system
  settings `manage-system`.
- Object-level checks: `ConversationPolicy` (owner only, no administrator
  override), `UserPolicy` (administrators cannot act on super
  administrators, nobody changes their own role or status, the last active
  super administrator stays), alias access limited to the user's group
  (`AliasAccess`).
- Tests: role matrix and ownership in `tests/Feature/Admin/*`,
  `tests/Feature/Chat/ChatStreamingTest.php`; no admin response contains
  conversation content (`UserAdminTest`).

## V5 Validation, sanitization and encoding — Met

- All input validated with explicit rules (form requests / `validate()`),
  including allow-lists (roles, statuses, report dimensions, locales).
- Database access through bindings; the few raw SQL fragments interpolate
  only column names chosen in code, never input (`UsageStatistics`,
  `BudgetPeriods`).
- Output: React escapes by default; Markdown rendering skips raw HTML and
  unsafe URLs, remote images are shown as links; the usage notice is plain
  text. No `dangerouslySetInnerHTML` except the Shiki output, which Shiki
  generates from escaped code.
- CSRF protection on every state-changing request (the SSE `fetch` sends
  the XSRF header); the SAML ACS is exempt and authenticated by the
  signature instead.

## V6 Stored cryptography — Met

- Provider API keys are encrypted with the application key (Laravel
  encryption, AES-256-CBC + HMAC), never sent to the browser, shown only
  masked, never logged or audited (`CredentialVault`, `AuditLogger`
  redaction).
- **D2 (documented):** rotating `APP_KEY`: set the old key in
  `APP_PREVIOUS_KEYS`, deploy the new `APP_KEY`, then re-save each provider
  key (Admin → Providers) so it is encrypted with the new key; remove the
  previous key afterwards. Sessions are invalidated by the rotation.

## V7 Error handling and logging — Partly

- `APP_DEBUG=false` in production (`ada:doctor`); errors shown to users are
  generic and translated, with stable codes.
- Security-relevant events are audited (who, what, before/after, IP, user
  agent) and readable by administrators (Admin → Audit log); secrets are
  redacted by key name.
- Prompts, answers and API keys are never logged; provider failures are
  logged with the provider's error code and message only (tested).
- **F2 (open, M11):** production logs use the plain `single` channel.
  Proposed: a JSON formatter and daily rotation in the production
  configuration shipped with M11.

## V8 Data protection — Met

- Privacy by design: administrators see usage and costs, never messages.
- Retention: conversations deleted by users are removed after 30 days,
  optional expiry of all conversations, usage records 24 months
  (`ada:retention:prune`, Admin → Privacy).
- A usage notice explains where data goes before first use.
- Sensitive responses are not cached by browsers (`Cache-Control` on the
  SSE stream; authenticated pages are not public).
- Accepted limitation: message content is stored unencrypted in the
  database; operators with database access can read it
  ([security.md](security.md) §9). Protect backups accordingly.

## V9 Communications — Deployment

- HSTS on https requests; secure cookies behind https; `ada:doctor`
  requires an https `APP_URL` in production.
- **F3 (open, M11):** TLS termination and trusted proxies are part of the
  production deployment (reverse proxy configuration,
  `TrustProxies`/`trustProxies()` for the proxy's address, redirect http to
  https at the proxy).

## V10 Malicious code — Met

- Dependencies pinned by lock files; `composer audit` and `npm audit` in
  CI; Dependabot; GitHub Actions pinned to commit SHAs; secret scanning.
- Content Security Policy with per-request script nonces; no third-party
  scripts; the Playwright specs fail on any CSP violation.

## V11 Business logic — Met

- Budgets cannot be overspent by parallel requests: reservations under row
  locks, tested with multiple processes
  (`tests/Concurrency/ConcurrentReservationTest.php`).
- Limits on output size (capped to the remaining budget), concurrent
  answers per user, requests per minute per group and per-user ceilings on
  all routes ([security.md](security.md) §7).

## V12 Files and resources — Met

- Only administrators upload files (logos, favicon): PNG/JPEG/WebP (favicon
  PNG), size and dimension limits, random names, stored on the public disk;
  SVG is not accepted.
- No user file uploads in V1; no server-side requests to user-supplied URLs
  (provider base URLs are set by super administrators).

## V13 API and web service — Met

- No public API in V1. The chat stream is a same-origin `POST` with CSRF
  protection and the same authorization as the pages.

## V14 Configuration — Met

- Security headers on every response (`SecurityHeaders`, tested);
  `ada:doctor` checks debug mode, https, the development login, the cache,
  the scheduler, sign-in and AI configuration.
- `.env.example` contains no secrets; credentials are not committed.

## Open items

| ID | Item | Plan |
|---|---|---|
| F1 | Absolute session lifetime | Fixed in M10 (`SESSION_MAX_LIFETIME`) |
| F2 | JSON logs with rotation in production | M11 production configuration |
| F3 | TLS termination and trusted proxies | M11 deployment |
| D1 | 2-step verification at the IdP | Deployment guide (M11) |
| D2 | `APP_KEY` rotation | Documented above |
