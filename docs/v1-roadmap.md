# V1 Roadmap

> Status: **M0–M11 done, v1.0.0 released** ([release notes](releases/v1.0.0.md)); the first rollout follows the deployment guide §10. Milestones are small and independently
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

### M7 — Groups, permissions and user usage ✅
- Groups with policy, rate limits, concurrent stream limit, alias permissions.
- User budget override.
- Sidebar budget indicator, budget-exhausted UX, user usage page.
- Playwright: budget exhausted, language switch.
- As built: admin screens for groups and budget policies (with "apply to
  this month"), `ada:user:budget` for individual limits (the users screen
  follows in M8), and an institution setting for what users see of their
  budget (amounts and percentage, or percentage only).

### M8 — Administration ✅
- Users (table: name, e-mail, group, budget, spent, remaining, last active,
  status; actions: change group, override budget, disable, change role,
  view usage).
- Individual budget override in the users screen, manual adjustments
  (groups and budget policies were done in M7).
- Audit log viewer.
- Playwright: admin model and budget management.
- As built: users screen (search, filters by group/role/status, sorting by
  last activity or spending) and a user page (group, individual budget,
  enable/disable, role, manual adjustment, usage); administrators manage
  users and groups, super administrators additionally roles, adjustments and
  system settings. Last activity is recorded at most every five minutes.

### M9 — Dashboard and reports ✅
- KPIs: monthly spend, active users, requests, average spend per user.
- Reports: total, by user, group, provider, model; requests and tokens by
  model; daily/monthly spend; filters (date range, user, group, provider, model).
- Counter deviation / overshoot panel.
- Decision point: daily aggregate table if raw queries are too slow.
- (CSV export: post-V1.)
- As built: the admin overview is the dashboard (this month vs. last month,
  daily spending, top models and groups, users at their limit, overshoot
  alert); `/admin/reports` has date range and group/provider/model/user
  filters, a breakdown by model/group/provider/user, spending per day or
  month, overshoots and counter deviation. Decision: raw queries over the
  indexed `usage_events` ledger (`App\Domain\Reports\UsageStatistics`);
  no aggregate table until reports become slow. Days are the institution's
  days (15-minute UTC buckets assigned in PHP; no MySQL time zone tables).

### M10 — Hardening ✅
- Security headers + CSP nonces, rate limits review, retention prune command,
  first-login usage acknowledgment, `ada:doctor`. ✅ (see
  [security.md](security.md) §5, §7, §9, §12 as built)
- Complete E2E suite, axe accessibility checks, bundle size budget. ✅
- Internal security review (OWASP ASVS L2 core). ✅
  ([security-review.md](security-review.md); open items go to M11)

### M11 — Production deployment ✅
- Production Docker image and compose file (built and smoke-tested in CI);
  bare-metal guide (Ubuntu 24.04, Nginx SSE config, PHP-FPM sizing, cron,
  backups) in English and Turkish. ✅ ([deployment.md](deployment.md),
  [deployment.tr.md](deployment.tr.md); no Supervisor: V1 has no queue jobs)
- Upgrade/backup/restore procedures, `APP_KEY` handling
  (`ada:credentials:reencrypt`). ✅
- Open review items: JSON logs (F2), trusted proxies (F3). ✅
- First institution rollout (Beykoz University): pilot group → wider rollout
  — checklist in the deployment guide §10; done by the institution.
- v1.0.0 release. ✅ ([release notes](releases/v1.0.0.md))

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

## v1.1

Chosen from the candidates below, one pull request per step:

| Step | Scope | Status |
|---|---|---|
| N1 | Institution-wide monthly cap (hard, enforced by the budget engine), alerts at 80 % / 100 % by e-mail, mail settings and test e-mail | ✅ |
| N2 | CSV export of reports; monthly summary report by e-mail | ✅ |
| N3 | Generic OpenAI-compatible provider (Chat Completions: OpenRouter, Ollama, vLLM, Groq, LM Studio), optional API key | ✅ |
| N4 | Attachments part 1: content parts in the provider layer, uploads, images (vision models), text and code files | ✅ |
| N5 | Attachments part 2: PDF (native or extracted text), Word, Excel and PowerPoint text extraction | ✅ |

Then the v1.1.0 release notes ✅ ([docs/releases/v1.1.0.md](releases/v1.1.0.md)).

## v1.2

Planned with the pilot's feedback, one pull request per step:

| Step | Scope | Status |
|---|---|---|
| P1 | Easy pricing: models chosen from a built-in price catalog with sources; the manual form becomes "Advanced" | ✅ |
| P2 | Sign-in with OIDC, with a Microsoft Entra ID preset (next to Google SAML) | ✅ |
| P3 | Chat: search, pinned conversations, export (Markdown, print / PDF) | ✅ |
| P4 | Institutional assistants part 1: instructions, model, groups, gallery | ✅ |
| P5 | Institutional assistants part 2: fixed documents added to the context | ✅ |

Then the v1.2.0 release notes ✅ ([docs/releases/v1.2.0.md](releases/v1.2.0.md)).

## v1.3

Chosen with the pilot, one pull request per step:

| Step | Scope | Status |
|---|---|---|
| Q1 | Web search part 1: the providers' search tools (Anthropic, OpenAI, Gemini), search prices in the catalog, searches in the budget and the usage records | ✅ |
| Q2 | Web search part 2: "Web" button, search progress and sources in the chat, alias, model and assistant settings, reports | ✅ |
| Q3 | Budget alerts for users at 80 % and 100 % (in the app and by e-mail) | 🚧 |
| Q4 | Answer feedback (thumbs up / down with a reason), satisfaction by alias, model and assistant without content | |
| Q5 | Sharing a read-only snapshot of a conversation with signed-in colleagues | |

Then the v1.3.0 release notes.

## Candidates for V2

- OpenAI-compatible `/v1/chat/completions` with personal API tokens (same budget).
- LDAP.
- Azure OpenAI, AWS Bedrock as dedicated drivers.
