# Security

> Status: **Proposed (M0)**. Threat-oriented overview; the public
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

## 2. Authentication and OAuth

- Socialite with session-bound `state`; no stateless mode.
- Server-side checks: `email_verified`, `hd` ∈ allowed domains, e-mail domain
  ∈ allowed domains, stable `sub` (see [authentication.md](authentication.md)).
- No password login → no credential stuffing surface. Break-glass via CLI only.
- Login route throttled per IP.
- Future OIDC: ID token signature (JWKS), `iss`, `aud`, `exp`, `nonce`.

## 3. Sessions

- `Secure`, `HttpOnly`, `SameSite=Lax` cookies; HTTPS only (HSTS).
- Regenerate session id on login; invalidate on logout.
- Disabled users: sessions deleted immediately; `EnsureUserIsActive` on each
  request.
- Idle and absolute lifetimes configurable.

## 4. CSRF

- Laravel CSRF middleware for all state-changing routes. Inertia sends
  `X-XSRF-TOKEN` automatically; the SSE `fetch` sends it explicitly.
- The chat stream is `POST` (never a state-changing `GET`).
- OAuth callback protected by `state`.

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
- Content Security Policy with nonces for the inline branding style and
  Vite scripts; `default-src 'self'`; `connect-src 'self'`; no third-party
  scripts.

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
| Request flooding | Per-user `requests_per_minute` (Laravel RateLimiter, Redis) + per-IP limits |
| Holding FPM workers | Max stream duration; per-user concurrent stream limit |
| Counter endpoint unavailable | `on_counter_failure = estimate` (large margin) or `reject` |
| Institution-wide overspend | Admin dashboard alerts; provider-side hard limits recommended; institution-wide cap proposed for v1.1 |

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
  for V1).
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

- Log channel: structured JSON in production; request id propagated.
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
- OAuth domain restriction tests.
- Markdown XSS tests (raw HTML, `javascript:` links).
- Budget concurrency and abuse tests.
- Header/CSP assertions.
- Before V1 release: internal review against OWASP ASVS Level 2 core
  sections and a third-party review if the institution can provide one.
