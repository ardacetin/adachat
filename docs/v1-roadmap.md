# V1 Roadmap

> Status: **M0–M6 done**, M7 next. Milestones are small and independently
> reviewable. Each milestone ends with green CI, updated docs and a demo.

## Changes compared to the initial brief

- **Budget engine before chat.** A separate "basic (non-streaming) chat"
  milestone is dropped: chat is built streaming-first and never ships without
  budget enforcement.
- Budget engine core (M5) has no UI dependency and can run in parallel with
  M3/M4.
- Groups/permissions are delivered together with the user-facing usage UI
  (M7) because permissions are needed before a pilot.
- Hardening and E2E start earlier than M10 for critical flows; M10 completes
  them.

## Milestones

### M0 — Repository and architecture ✅
Architecture documents, README (EN/TR), SECURITY, CONTRIBUTING, CHANGELOG,
LICENSE (AGPL-3.0-or-later).

### M1 — Foundation ✅
- Laravel 13 + React starter kit; remove password auth scaffolding.
- MySQL 8.4 as default connection (UTC), `dateTime()` columns convention.
- `app/Domain` skeleton, `config/ada.php`.
- i18n infrastructure (i18next namespaces, `lang/{en,tr}`), hard-coded UI
  text check, key parity check.
- Development-only login (pulled forward from M2 so the app is usable
  before Google sign-in exists).
- CI: Pint, Larastan, Pest (MySQL service), Vite+ lint/format
  (oxlint/oxfmt), `tsc`, `composer audit`, `npm audit`.
- Dev environment: `docker-compose.yml` (MySQL, Redis, Mailpit), `.env.example`.
- **Done when:** app boots, CI green, TR/EN switch works on a sample page.

### M2 — Identity ✅
- Google sign-in with `state`, `email_verified`, `hd` and domain checks
  (replaced after M5 by SAML 2.0 with a Google Workspace SAML app — see
  [authentication.md](authentication.md)).
- `user_identities`, JIT provisioning, default group, roles, `EnsureUserIsActive`.
- `ada:install`, `ada:user:promote`, local-only dev login.
- **Done when:** all auth tests in [authentication.md §6](authentication.md#6-tests) pass.

### M3 — Institution settings and branding ✅
- `InstitutionSettings`, `AuthSettings` (spatie/laravel-settings), seeded from `.env`.
- Admin screens: institution settings, auth settings (allowed domains).
- Logo/dark logo/favicon upload, primary colour → OKLCH tokens with contrast check.
- User preferences: language, appearance (persisted).
- Audit logger foundation (used from here on).

### M4 — Providers, models and aliases ✅
- `providers`, `provider_credentials` (encrypted, masked), `ai_models`, `model_aliases`.
- Admin CRUD for providers, credentials, models, aliases, prices.
- `ChatProvider` with direct-HTTP adapters for OpenAI (Responses),
  Anthropic (Messages) and Gemini (decided in M4 instead of Prism — see
  [provider-architecture.md §2.1](provider-architecture.md#21-decision-direct-http)).
- `InputTokenCounter` implementations (OpenAI, Anthropic, Gemini, Estimated),
  margin config, failure policy.
- Contract tests with documented-format SSE fixtures; opt-in live tests.

### M5 — Budget engine core ✅
- `budget_policies`, `budget_periods`, `budget_reservations`, `usage_events`.
- `Usd` value object, `CostCalculator`, `ReservationSizer`, reserve/settle/
  release/expire, append-only guards, reconciliation command, scheduler jobs.
- Full test plan from [budget-engine.md §17](budget-engine.md#17-test-plan),
  including multi-process concurrency tests. As built:
  [budget-engine.md §18](budget-engine.md#18-implementation-m5).

### M6 — Streaming chat end-to-end ✅
- Conversations/messages, `ChatGenerationService`, SSE endpoint, cancel endpoint.
- Integration: token count → reserve → stream → settle/release.
- `useChatStream`, chat layout, sidebar history, composer, model selector,
  markdown + code blocks, stop, regenerate last answer.
- Partial persistence and recovery after reload.
- First Playwright flows (login, new conversation, stream, stop).
- As built: aliases are available only to the groups they are assigned to
  (the alias form has a group checklist; new aliases start with the Default
  group). Playwright runs against a mock OpenAI server (`tests/e2e`).

### M7 — Groups, permissions and user usage
- Groups with policy, rate limits, concurrent stream limit, alias permissions.
- User budget override.
- Sidebar budget indicator, budget-exhausted UX, user usage page.
- Playwright: budget exhausted, language switch.

### M8 — Administration
- Users (table: name, e-mail, group, budget, spent, remaining, last active,
  status; actions: change group, override budget, disable, change role,
  view usage).
- Groups, budget policies (apply to current period), manual adjustments.
- Audit log viewer.
- Playwright: admin model and budget management.

### M9 — Dashboard and reports
- KPIs: monthly spend, active users, requests, average spend per user.
- Reports: total, by user, group, provider, model; requests and tokens by
  model; daily/monthly spend; filters (date range, user, group, provider, model).
- Counter deviation / overshoot panel.
- Decision point: daily aggregate table if raw queries are too slow.
- (CSV export: post-V1.)

### M10 — Hardening
- Security headers + CSP nonces, rate limits review, retention prune command,
  first-login usage acknowledgment, `ada:doctor`.
- Complete E2E suite, axe accessibility checks, bundle size budget.
- Internal security review (OWASP ASVS L2 core).

### M11 — Production deployment
- Production Docker image and compose file; bare-metal guide (Ubuntu 24.04,
  Nginx SSE config, PHP-FPM sizing, Supervisor, cron, backups) in English and
  Turkish.
- Upgrade/backup/restore procedures, `APP_KEY` handling.
- First institution rollout (Beykoz University): pilot group → wider rollout.
- v1.0.0 release.

## Dependency graph

```
M0 → M1 → M2 → M3 ─┐
            └→ M4 ─┼→ M6 → M7 → M8 → M9 → M10 → M11
            └→ M5 ─┘
```

## Explicitly out of scope for V1

RAG, vector databases, MCP, web search, agents, code execution, image
generation, voice/STT/TTS, workflow builder, multi-tenant SaaS, file uploads,
OpenAI-compatible external API (V2), CSV export, AI-generated conversation
titles, conversation branching UI beyond "regenerate last answer".

## Candidates for v1.1 / V2

- Institution-wide monthly spending cap.
- OpenAI-compatible `/v1/chat/completions` with personal API tokens (same budget).
- Generic OIDC / Entra ID, SAML, LDAP.
- File attachments and vision.
- CSV export, scheduled reports.
- Additional providers (OpenRouter, Azure OpenAI, Bedrock, local endpoints).
