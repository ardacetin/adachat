# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Institution-wide monthly cap** (Admin → Institution): all users together
  can spend at most this much per month, enforced by the budget engine as
  strictly as a user's limit (the institution's month is kept under a row
  lock in the same transactions). A request that only the institution cannot
  afford is refused with its own message. The overview shows the month's use
  of the cap.
- E-mail alerts at 80 % and 100 % of the cap to the new notification
  addresses (`ada:budget:cap-alerts`, every five minutes), a "Send a test
  e-mail" button and an `ada:doctor` check for the mail settings.
- `ada:budget:reconcile` also checks the institution totals.
- **CSV export** on the reports page ("Download CSV"): the full breakdown by
  user, group, provider or model (every row, not only the top 100) and the
  spending per day or month, with the page's filters. UTF-8 with a BOM; in
  Turkish with ";" and decimal commas for Turkish Excel. Exports are
  audited; cells that a spreadsheet would run as formulas are defused.
- **Monthly report by e-mail**: after each month (institution time zone) the
  notification addresses receive a summary (spending, requests, active
  users, top groups and models, users at their limit, overshoots, the cap)
  with the per-user and per-day CSV attached. `ada:reports:monthly`
  (hourly, sends each month once; `--month=YYYY-MM --to=…` on demand).
- **OpenAI-compatible provider** (Chat Completions): connect OpenRouter,
  Groq, Ollama, vLLM, LM Studio and other servers that speak the OpenAI Chat
  Completions API. The base URL is required, the API key optional (no
  `Authorization` header without one). These servers have no token count
  endpoint, so input is estimated with a 25 % margin and settled with the
  usage the server reports. Self-hosted models can be priced at 0: their use
  is recorded but costs nothing against budgets. Setup examples in
  [provider-architecture.md §9](docs/provider-architecture.md#9-openai-compatible-servers).

## [1.0.0] - 2026-10-01

First release: a self-hosted AI gateway and chat for one institution, with
institutional sign-in, several AI providers behind one interface, per-user
budgets that cannot be overspent, and administration without access to
anyone's conversations. Release notes:
[docs/releases/v1.0.0.md](docs/releases/v1.0.0.md).

### Added

**Sign-in and accounts**

- SAML 2.0 sign-in with a custom SAML app in Google Workspace
  (SP-initiated; strict response validation with onelogin/php-saml,
  single-use request IDs against replay). No passwords in Ada.
- Allowed e-mail domains, optional automatic account creation on first
  sign-in into the default group, disabled accounts, an absolute session
  lifetime (`SESSION_MAX_LIFETIME`, default 7 days) next to the idle timeout.
- Roles: user, administrator, super administrator; `ada:user:promote` for
  break-glass role assignment.

**Chat**

- Streaming answers (Server-Sent Events) with stop, regenerate and copy;
  conversations with rename and delete; Markdown without raw HTML; code
  highlighting loaded on demand.
- Users choose among the institution's model aliases ("Fast", "Advanced");
  aliases are available only to the groups they are assigned to.
- Long conversations are trimmed to fit the model's context window.
- A usage notice before first use (built-in or the institution's text,
  asked again when it changes).
- English and Turkish user interface; light and dark mode.

**AI providers**

- OpenAI (Responses API), Anthropic (Messages API) and Google Gemini through
  direct HTTP adapters with streaming, cancellation and normalised token
  usage.
- Input tokens counted with each provider's count endpoint before every
  request, with safety margins and a configurable fallback.
- Provider API keys encrypted with `APP_KEY`, shown only masked, rotated
  with history; "Test connection" and `ada:provider:check`.
- Models with exact prices (input, output, cached input, cache write),
  context window and output limit; cost hints and a live cost calculator on
  the model form.

**Budgets**

- Monthly budgets per user from budget policies, set per group, with
  individual limits and manual adjustments; periods in the institution's
  time zone.
- Every request reserves its maximum cost under a row lock before it is
  sent and is settled with the actual usage afterwards: parallel requests
  cannot overspend a budget (tested with multiple processes). Answers are
  capped to the remaining budget.
- Exact decimal money arithmetic, price snapshots in an append-only usage
  ledger, nightly reconciliation, expiry of abandoned reservations.
- Per-group requests per minute and parallel answers per user.
- Users see their budget (amounts and percentage, or percentage only) and a
  usage page; an exhausted budget shows when it renews.

**Administration**

- Institution settings and branding: name, logos, favicon, primary colour
  (WCAG-checked theme), default language and time zone.
- Providers, models, model aliases, groups, budget policies and users
  (search and filters, group, individual budget, disable, role,
  adjustments). Administrators never see conversation content.
- Dashboard (spending, requests, active users, top models and groups, users
  at their limit) and reports by date range, group, provider, model and
  user, with budget overshoots and token-count deviation.
- An append-only audit log of every administrative change, with secrets
  redacted, and a viewer with filters.
- Privacy settings: usage notice, retention of deleted conversations,
  optional expiry of conversations, retention of usage records; a nightly
  `ada:retention:prune`.

**Security**

- Security headers and a nonce-based Content Security Policy, per-user rate
  limits, CSRF protection on every state-changing request, trusted proxies
  (`TRUSTED_PROXIES`).
- Prompts, answers and API keys are never logged; JSON logs in production.
- Internal OWASP ASVS 4.0.3 Level 2 review
  ([docs/security-review.md](docs/security-review.md)).

**Operations**

- Production Docker image and `compose.production.yml` (application,
  scheduler, MySQL 8.4, Redis), built and smoke-tested in CI.
- Deployment guide in English and Turkish: sizing, Docker and Ubuntu 24.04
  installation, nginx settings for streamed answers, backups, restore,
  upgrades, `APP_KEY` handling and an institution rollout checklist;
  example nginx, PHP-FPM, cron and backup files in `deploy/`.
- `ada:install`, `ada:doctor` (configuration and health check, also for
  monitoring), `ada:credentials:reencrypt` (after an `APP_KEY` rotation),
  `ada:user:budget`.

**Quality**

- Pest test suite on MySQL (391 tests), Playwright end-to-end tests against
  a mock provider including axe accessibility checks (WCAG 2.1 AA), Larastan
  level 7, a JavaScript bundle size budget and dependency audits in CI.

[Unreleased]: https://github.com/ardacetin/adachat/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/ardacetin/adachat/releases/tag/v1.0.0
