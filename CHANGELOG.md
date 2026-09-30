# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

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
