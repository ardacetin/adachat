# Security

> Status: **Accepted; applied through M5** (sign-in checks, roles, encrypted
> provider keys, audit log, append-only ledger). Threat-oriented overview; the public
> vulnerability disclosure policy is in [`SECURITY.md`](../SECURITY.md).

## 1. Assets and actors

| Asset | Why it matters |
|---|---|
| Provider API keys | Direct financial loss, abuse under the institution's name |
| Conversation content | Personal and possibly confidential data (research, HR, students) |
| Budgets and usage ledger | Financial correctness, fairness, audit |
| Admin capabilities | Can change budgets, roles, providers |
| Sessions | Account takeover |

Actors: anonymous internet users; authenticated staff (curious or abusive);
admins (trusted for accounting, **not** for content); super admins;
operators with server access (ultimately trusted); compromised dependencies.

## 2. Authentication (SAML 2.0)

- SP-initiated SAML with onelogin/php-saml in strict mode: signed response or
  assertion from the configured IdP certificate, issuer, audience,
  destination/recipient and validity window checked; schema validation, no
  DOCTYPE (XXE).
- Every AuthnRequest ID is single-use and expires after 10 minutes (cache),
  so responses cannot be replayed or injected; unsolicited (IdP-initiated)
  responses are not trusted and restart an SP-initiated sign-in.
- E-mail domain ∈ allowed domains (see [authentication.md](authentication.md)).
- No password login → no credential stuffing surface. Break-glass via CLI only.
- Login route throttled per IP.
- Future OIDC: ID token signature (JWKS), `iss`, `aud`, `exp`, `nonce`.

## 3. Sessions

- `Secure`, `HttpOnly`, `SameSite=Lax` cookies; HTTPS only (HSTS).
- Regenerate session id on login; invalidate on logout.
- Disabled users: sessions deleted immediately; `EnsureUserIsActive` on each
  request.
- Idle and absolute lifetimes configurable: `SESSION_LIFETIME` (idle,
  default 480 minutes) and `SESSION_MAX_LIFETIME` (after sign-in, default
  7 days; enforced by `EnsureUserIsActive`, M10).

## 4. CSRF

- Laravel CSRF middleware for all state-changing routes. Inertia sends
  `X-XSRF-TOKEN` automatically; the SSE `fetch` sends it explicitly.
- The chat stream is `POST` (never a state-changing `GET`).
- The SAML ACS (`POST /auth/saml/acs`) is exempt from CSRF tokens because
  the IdP posts cross-site; it is authenticated by the response signature and
  the single-use request ID.

## 5. XSS

- React escapes by default; `dangerouslySetInnerHTML` is forbidden by lint rule
  except in audited wrappers (none planned).
- Markdown: no raw HTML, safe URL transform, no remote images in V1
  ([frontend-architecture.md §5](frontend-architecture.md#5-markdown-rendering)).
- Branding: logos accepted as PNG/WebP/JPEG only (favicons: square PNG),
  stored under random names on the public disk. SVG is **not** accepted in
  V1 (it can carry scripts); if added later it must be sanitised
  (`enshrined/svg-sanitize`) and served with a restrictive CSP. Colours are
  validated as `#rrggbb` and converted server-side into numeric OKLCH values
  (no raw CSS injection). The appearance cookie is validated against the
  enum before it reaches the inline script.
- Content Security Policy (as built, M10: `App\Http\Middleware\SecurityHeaders`):
  `script-src 'self' 'nonce-…'` — Vite's tags and the inline appearance
  script carry a per-request nonce, nothing else runs (no `unsafe-eval`, no
  `wasm-unsafe-eval`: code highlighting uses Shiki's JavaScript regex
  engine); `style-src 'self' 'unsafe-inline'` because UI libraries inject
  `<style>` tags at runtime and React sets style attributes (CSS cannot run
  code); `default-src`/`connect-src 'self'`, `object-src 'none'`,
  `frame-ancestors 'none'`, `form-action 'self'`, `base-uri 'self'`; no
  third-party scripts. Not sent while the Vite dev server runs. The
  Playwright specs fail on any CSP violation.

## 6. Provider credentials

- Stored encrypted (`encrypted` cast, AES-256-CBC + HMAC with `APP_KEY`) or in
  `.env`. Never sent to the browser; masked as `sk-…8f2a` using a separately
  stored `last_four`.
- Never logged: HTTP client logging off for providers; exception context
  scrubbed of `Authorization`, `x-api-key`, `x-goog-api-key` headers and
  request bodies; audit entries record "credential rotated", never values.
- `APP_KEY` protects credentials **and** sessions: back it up securely; losing
  it means re-entering provider keys. Key rotation supported via
  `APP_PREVIOUS_KEYS` plus a `ada:credentials:reencrypt` command.
- Recommendation for institutions: dedicated provider projects/keys for Ada
  with provider-side spend limits as a second safety net.

## 7. Budget abuse

| Threat | Control |
|---|---|
| Parallel requests to overspend | Row-locked reservations ([budget-engine.md](budget-engine.md)) |
| Huge prompts | Input counted with provider token counters before reservation; context window limit; request size limit |
| Huge outputs | `max_output_tokens` always set and capped to remaining budget |
| Many concurrent streams | Per-group `max_concurrent_streams`, checked under the budget lock |
| Request flooding | Per-user `requests_per_minute` of the group for chat, plus per-route limits and per-user ceilings (below) |
| Holding FPM workers | Max stream duration; per-user concurrent stream limit |
| Counter endpoint unavailable | `on_counter_failure = estimate` (large margin) or `reject` |
| Institution-wide overspend | Admin dashboard alerts; provider-side hard limits recommended; institution-wide cap proposed for v1.1 |

Rate limits as built (M10), per user when signed in, otherwise per IP:

| Route(s) | Limit per minute |
|---|---|
| All signed-in pages and actions (`throttle:app`) | 300 |
| Administration (`throttle:admin`) | 120 |
| Chat: send / regenerate (plus the group's requests per minute) | 60 each |
| Chat: stop | 120 |
| Login page / SAML redirect / ACS | 60 / 20 / 20 |
| Usage notice acknowledgment | 10 |
| Provider connection check | 10 |

## 8. Authorization and admin privilege boundaries

- Every controller action calls a Policy/Gate; UI `can` flags are cosmetic.
- Role boundaries per [authentication.md §4](authentication.md#4-roles-and-authorization);
  admins cannot escalate to super admin or change their own role; last super
  admin protected.
- Route model binding scoped to the owner for conversations/messages
  (`/c/{conversation}` 404s for non-owners — no existence leak).
- UUIDv7 identifiers in URLs; no sequential ids for user content.

## 9. Prompt privacy

- **Accounting ≠ content.** Usage events contain tokens and costs, never
  text. Admin reports are built only from `usage_events`.
- No admin screen or API returns message content. Viewing another user's
  conversation is not a V1 feature; if a future legal-hold feature is added it
  must require super admin, a reason, and an audit entry visible to auditors.
- Prompts and responses are **never logged** (application logs, exception
  reports, queue payloads). Error reporting integrations (if any) must scrub
  request bodies.
- Operators with database access can read content; this is disclosed in the
  documentation. Application-level encryption of message content is a
  possible later option (trade-off: search, backups, key management).
- Data leaves the institution to the configured AI providers. Institutions
  should review provider data-processing terms (zero-retention options, region)
  and local law (e.g. KVKK in Turkey, GDPR in the EU). Ada supports showing
  `privacy_url` / `terms_url` and a first-login acknowledgment (recommended
  for V1). As built (M10): on by default; users acknowledge the built-in
  notice (or the institution's own text per language, plain text) before
  anything else (`EnsureAcknowledged`); changing the text or "ask everyone
  again" raises the version and everyone acknowledges again. Admin →
  Privacy.
- Retention: conversation retention and usage retention configured separately
  (see [database-design.md §7](database-design.md#7-retention-readiness)).

## 10. Audit logging

- Explicit `AuditLogger::record($action, $subject, $old, $new)` calls in
  domain actions (not automatic model auditing, which risks capturing secrets).
- Audited actions: budget policy/override/adjustment changes, user disabled/
  enabled, role changed, group changed, provider created/updated,
  credential rotated, model/alias enabled/disabled/changed, prices changed,
  institution settings changed, auth settings changed, CLI promotions.
- Redaction: models declare redacted attributes; values of keys matching
  `secret|key|token|password` are replaced with `[redacted]`.
- Append-only (application guard + optional DB triggers). Actor, IP and user
  agent recorded.

## 11. API and application logging

- Log channel: structured JSON in production (as built in M11: the `json`
  channel, daily files kept `LOG_DAILY_DAYS`; `stderr` JSON in Docker).
- Logged: request metadata (route, user id, status, duration), provider
  request ids, error codes, budget decisions (amounts, not content).
- Never logged: prompts, completions, API keys, OAuth tokens, session ids,
  full e-mail lists in bulk.
- Future external API: personal tokens hashed (Sanctum), scoped, revocable,
  subject to the same budget and rate limits.

## 12. Transport and headers

- HTTPS only; HSTS; `X-Content-Type-Options: nosniff`;
  `Referrer-Policy: strict-origin-when-cross-origin`;
  `X-Frame-Options: DENY` / `frame-ancestors 'none'`;
  `Permissions-Policy` minimal; CSP as above.
- As built (M10): `X-Content-Type-Options`, `X-Frame-Options: DENY`,
  `Referrer-Policy`, `Permissions-Policy` (camera, microphone, geolocation,
  payment, usb off), `Cross-Origin-Opener-Policy: same-origin`, and HSTS
  (one year) on https requests.
- Behind a reverse proxy, `X-Forwarded-*` headers are trusted only from
  `TRUSTED_PROXIES` (M11); otherwise a client could fake its address in the
  audit log and rate limits, or the https flag.
- `APP_DEBUG=false` and `APP_ENV=production` verified by `ada:doctor`;
  debug tools (e.g. Telescope) not installed in production.

## 13. Injection

- Eloquent/query builder bindings only; raw SQL reviewed and parameterized
  (e.g. the period upsert).
- Form Requests validate every input; model alias slugs and settings keys
  validated against allow-lists.
- Prompt injection: model output is untrusted data rendered safely; V1 has
  no tools or agents, so injected instructions cannot trigger actions.

## 14. Dependency and supply-chain security

- `composer audit` and `npm audit` in CI; Dependabot/Renovate for updates.
- Lock files committed; minimal dependency policy (each new package
  justified in the PR).
- GitHub secret scanning and push protection enabled; `.env.example`
  contains placeholders only.
- Signed releases/tags recommended; Docker images built in CI from pinned
  base images.

## 15. Security testing

- Feature tests for every policy (role matrix, owner-only content).
- SAML response validation and domain restriction tests.
- Markdown XSS tests (raw HTML, `javascript:` links).
- Budget concurrency and abuse tests.
- Header/CSP assertions.
- Before V1 release: internal review against OWASP ASVS Level 2 core
  sections and a third-party review if the institution can provide one.
  The internal review (M10) is [security-review.md](security-review.md).
