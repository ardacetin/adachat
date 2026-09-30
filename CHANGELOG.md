# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- Provider streams are read line by line, so answers are shown as they are
  generated instead of arriving in 8 KiB blocks.

### Changed

- Sign-in uses **SAML 2.0** with a custom SAML app in Google Workspace
  instead of Google OAuth: SP-initiated flow (`/auth/saml/redirect`, ACS
  `/auth/saml/acs`, SP metadata and entity ID `/auth/saml/metadata`),
  strict response validation with onelogin/php-saml, single-use request IDs
  against replay, IdP-initiated responses restarted as SP-initiated. IdP
  settings come from `.env` (`SAML_IDP_*`); the admin Sign-in page and
  `ada:install` show the ACS URL and Entity ID to enter in Google Admin.
  `laravel/socialite` and the `GOOGLE_CLIENT_*` settings were removed.

### Added

- M8 administration: a **users** screen (search; filters by group, role and
  status; this month's budget and spending; last activity) and a user page
  to change the group, set an individual budget, disable or enable the
  account (signs the user out everywhere), change the role and record manual
  adjustments, with the user's usage. An **audit log** viewer with filters.
  Administrators manage users and groups; super administrators also roles,
  adjustments and system settings. The last active super administrator
  cannot be demoted or disabled.
- Last activity is recorded (at most every five minutes).
- Playwright: administration flow (budget policy, group, alias, user group
  and budget, adjustment, audit log) and the administrator role matrix.
- Admin screens for **groups** (budget policy, requests per minute, parallel
  answers, available model aliases; empty non-default groups can be
  deleted) and **budget policies** (monthly limit; unused policies can be
  deleted). Changing a limit or a group's policy can be applied to the
  current month. All changes are audited.
- `php artisan ada:user:budget <email> --limit=<USD> | --clear` for an
  individual monthly limit.
- Users see their budget: a sidebar indicator, a budget-exhausted state in
  the chat with the renewal date, and a usage page (this month by model and
  by day, previous months).
- Institution setting *Budget shown to users*: amounts and percentage, or
  percentage only (no dollar amounts are sent to the browser).
- Playwright: budget indicator, usage page, exhausted budget.
- M6 streaming chat: conversations and messages (UUIDv7, soft-deleted
  conversations, regenerate as sibling answers), `ChatGenerationService`
  (context trimming → token count → budget reserve → provider stream →
  settle/release in `finally`), Server-Sent Events over `fetch` POST
  (`message.started`, `delta`, `message.completed`, `error`), stop via a
  cancel endpoint, partial answers flushed to the database every second and
  settled by the reservation cleanup job if the process dies, per-group
  requests-per-minute limit.
- Chat UI: conversation sidebar (rename, delete), composer, model selector,
  Markdown rendering without raw HTML, lazily highlighted code blocks (Shiki)
  with copy, copy/regenerate actions, TR/EN.
- Aliases are available only to the groups they are assigned to; the alias
  form has a group checklist (new aliases start with the Default group) and
  group changes are audited.
- Playwright end-to-end tests against a mock OpenAI server and a CI `e2e`
  job.
- Architecture documents (M0): architecture, database design, budget engine,
  authentication, provider architecture, frontend architecture, security and
  V1 roadmap.
- README (English and Turkish), security policy, contributing guide.
- License: GNU Affero General Public License v3.0 or later.
- M1 foundation: Laravel 13 + Inertia v3 + React + TypeScript + shadcn/ui based
  on the Laravel React starter kit, without password authentication.
- MySQL 8.4 as the only supported database (UTC, `utf8mb4_0900_ai_ci`,
  `DATETIME` columns), `config/ada.php`, `app/Domain` skeleton.
- English and Turkish UI (i18next) and backend translations, locale
  resolution and a language setting; translation key parity tests and a
  hard-coded UI text check.
- Development-only login, sample seeders, Pest test suite on MySQL, CI
  workflow and a development `docker-compose.yml`.
- M2 identity: Google Workspace sign-in via Socialite behind a
  `RedirectIdentityProvider` abstraction; server-side checks of state,
  `email_verified`, hosted domain (`hd`) and e-mail domain; `user_identities`
  keyed by provider subject; just-in-time provisioning into the default group
  (optional); translated rejection reasons incl. account conflicts.
- Groups table with an always-present default group; `users.group_id`.
- Role gates (`access-admin`, `manage-system`) and shared `can` UI hints.
- `ada:install` (configuration check) and `ada:user:promote` (break-glass
  role assignment).
- M3 institution & branding: typed settings (spatie/laravel-settings) for
  institution and sign-in policy, seeded from `.env` once; admin area
  (super admin) for institution details, language/time zone, primary colour,
  logos and favicon, and allowed domains; WCAG-checked OKLCH theme tokens for
  light and dark mode; institution logo in the sidebar and on sign-in;
  appearance preference saved per user; append-only audit log with
  redaction, recording settings changes and CLI role assignments.
- M4 providers & models: `providers`, `provider_credentials` (encrypted,
  masked, rotation history, `.env` fallback), `ai_models` (exact DECIMAL
  prices, capabilities), `model_aliases` (localized names, per-alias output
  cap) and the `group_model_alias` pivot; direct-HTTP adapters for OpenAI
  (Responses API), Anthropic (Messages API) and Gemini with a shared SSE
  parser, cancellation, disjoint token usage and error mapping; input token
  counting through each provider's count endpoint with configurable margins
  and an `estimate`/`reject` fallback policy; admin screens (super admin,
  audited) for providers, models and aliases with a "Test connection" check;
  `ada:provider:check`; opt-in live provider tests.
- M5 budget engine: `budget_policies` (Default policy on install),
  per-user monthly `budget_periods` in the institution time zone (created
  lazily, limit snapshot), `budget_reservations` (UUIDv7) and the append-only
  `usage_events` ledger with price snapshots; group policy, concurrent stream
  limit and personal limit override columns. Exact USD arithmetic (`Usd`,
  brick/math, 10 decimals, rounded up), tiered pricing, cost calculation,
  reservation sizing (counted input + margin + maximum output, output capped
  to the remaining budget, context-window cap), row-locked reserve / settle /
  release / expire, idempotent and late settlement, overshoot logging,
  adjustments, "apply to current period", `ada:budget:expire-reservations`
  and `ada:budget:reconcile` (scheduled); multi-process concurrency tests.
