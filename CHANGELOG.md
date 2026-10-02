# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Web search, part 1 (no user interface yet): a message can use the
  provider's own search tool (Anthropic web search, OpenAI web search,
  Gemini Grounding with Google Search). The answer streams the search
  queries (`search`) and the cited pages (`source`) and keeps the sources
  with the message. Models have a search capability and a price per 1,000
  searches (catalog: Anthropic and OpenAI $10, Gemini $14); aliases allow
  search with up to 5 searches per message. The budget reserves the allowed
  searches and charges those the provider billed; usage records keep their
  number and price ([provider-architecture.md §10](docs/provider-architecture.md#10-web-search)).

## [1.2.0] - 2026-10-02

Easy model pricing from a built-in price catalog, sign-in with Microsoft
Entra ID and other OpenID Connect providers, users added by e-mail address,
search, pinning and export in the chat, and institutional assistants with
instructions and documents. Release notes:
[docs/releases/v1.2.0.md](docs/releases/v1.2.0.md).

### Added

- Administration → Users: a menu on each row disables or enables the account
  (with a confirmation) and, for super administrators, changes the role,
  without opening the user's page.
- Institutional assistants: super administrators write instructions on top
  of a model alias, choose groups, an icon and up to four starter prompts
  (Administration → Assistants). Users open them from the Assistants
  gallery; the conversation keeps the assistant's model and instructions,
  which follow the alias system prompt ([docs/assistants.md](docs/assistants.md)).
- Assistant documents: fixed PDF, Office or text files whose text is sent
  with the assistant's instructions (up to `ADA_ASSISTANT_MAX_DOCUMENT_TOKENS`,
  default 50,000 tokens). The form shows the tokens and cost per message;
  Anthropic models cache them.
- Chat: pin conversations (they stay on top of the sidebar), search your
  own conversations by title, message text or file name (Ctrl+K / ⌘K), and
  export a conversation as Markdown or print it / save it as PDF.
- Administrators add users by e-mail address (Administration → Users → Add
  users), with group and role, and see who has not signed in yet. Added
  addresses can sign in even outside the allowed domains; with "Anyone with
  an address in an allowed domain can sign in" turned off, only listed users
  can sign in. Unused invitations can be removed.
- Easy model pricing: in Admin → Models, a model is added by choosing it
  from Ada's price catalog, without typing prices. The catalog
  (`resources/catalog/models.json`) holds official prices with their source
  and date; `ADA_MODEL_CATALOG` points to an institution's own copy. The
  former form is the "Advanced" mode.
- When a release updates catalog prices, the model list and `ada:doctor`
  point out the models concerned; "Use new prices" takes them over.
- The catalog covers Anthropic, OpenAI (GPT-6 Astra, GPT-6.1 Sol, GPT-6
  Luna) and Google Gemini (3.1 Pro Preview, 3.8 Flash, 3.5 and 3.1
  Flash-Lite), including price changes the provider has announced.
- Sign-in with OpenID Connect, next to or instead of Google SAML: a preset
  for Microsoft Entra ID (single tenant, tenant pinned, guest accounts
  refused) and a generic mode for Keycloak, Okta and others. Authorization
  code flow with PKCE; ID tokens verified against the provider's keys
  (RS256/ES256). Configured in `.env` (`OIDC_*`); Administration → Sign-in
  shows the redirect URI and tests the connection, `ada:doctor` checks it
  ([authentication.md §1a](docs/authentication.md#1a-openid-connect-microsoft-entra-id-generic)).

## [1.1.0] - 2026-10-01

An institution-wide spending cap with e-mail alerts, CSV exports and a
monthly report, a provider for OpenAI-compatible servers, and attachments in
the chat (images, PDF, Office, text and code files). Release notes:
[docs/releases/v1.1.0.md](docs/releases/v1.1.0.md).

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

- **Attachments in the chat**: images (PNG, JPEG, WebP, GIF) for models
  with image support, and text and code files for every model. Attach with
  the paperclip, by dragging or by pasting a screenshot; each file shows its
  estimated token cost. Images go to the provider inline (OpenAI, Anthropic,
  Gemini, OpenAI-compatible), text files as fenced blocks in the message.
  Attachments are sent again while their message is in the context, up to
  15 MB of images per request (older images are replaced by a note).
  Uploads are checked by content (no SVG), stored privately and visible
  only to their owner; they are deleted with their conversation by
  `ada:retention:prune`, which also removes unsent uploads after a day.
- **PDF, Word, Excel and PowerPoint attachments** (.pdf, .docx, .xlsx,
  .pptx, up to 10 MB). Their text is extracted once on upload (PDF with
  smalot/pdfparser; Office files with Ada's own reader, which refuses zip
  bombs and XML entity tricks) and cut at 200 000 characters. PDFs go to
  models with "Files" support as the PDF itself (OpenAI, Anthropic,
  Gemini), so scanned pages and figures are read too; other models and
  OpenAI-compatible servers get the extracted text. A scanned PDF without
  text needs a model with "Files" support. Legacy .doc/.xls/.ppt files and
  OCR are not supported.

### Fixed

- Per-route rate limits no longer share one counter per user: sending
  chat messages could make the usage notice ("I understand") answer
  "Too many requests", and sign-in attempts counted against each other.

### Upgrade notes

- Attachments need larger request bodies. Docker images include the new
  limits; for the host nginx in front of Docker
  (`deploy/nginx/ada-docker-proxy.conf`) and for installations without
  Docker, set `client_max_body_size 12m;` in nginx and
  `upload_max_filesize = 11M` and `post_max_size = 12M` in PHP-FPM
  (`deploy/php-fpm/ada.conf`), then reload both.

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

[Unreleased]: https://github.com/ardacetin/adachat/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/ardacetin/adachat/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/ardacetin/adachat/releases/tag/v1.0.0
