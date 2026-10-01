# Architecture

> Status: **Accepted; implemented through M5** (foundation, identity,
> settings/branding, providers, budget engine). Conversations and streaming
> chat follow in M6; the roadmap tracks the rest.

Ada Chat is an **institutional AI gateway with a chat interface**. It is not a
"ChatGPT clone": the chat UI is one consumer of a core that handles identity,
authorization, budget enforcement, usage accounting and provider access. The
same core must later serve an OpenAI-compatible API (V2) without changes to the
budget or accounting logic.

```
User ─► Authentication ─► Authorization ─► Budget Engine (reserve)
                                               │
                                               ▼
                                     AI Provider Layer ─► OpenAI / Anthropic / Gemini
                                               │
                                               ▼
                         Usage Accounting (settle) ─► Streaming response to user
```

Related documents:

- [database-design.md](database-design.md)
- [budget-engine.md](budget-engine.md)
- [authentication.md](authentication.md)
- [provider-architecture.md](provider-architecture.md)
- [frontend-architecture.md](frontend-architecture.md)
- [security.md](security.md)
- [v1-roadmap.md](v1-roadmap.md)

---

## 1. Guiding principles

1. **Self-hosted first.** No mandatory cloud dependency besides the AI provider
   APIs themselves. Runs on a plain Ubuntu + Nginx + PHP-FPM + MySQL + Redis
   server; Docker is supported but optional.
2. **Institution-neutral code.** No institution name, domain, colour or logo in
   code. Everything institution-specific comes from settings (database) seeded
   from environment variables at install time.
3. **Money is correctness-critical.** Budget and usage data are treated like
   financial records: exact decimal arithmetic, row-level locking, append-only
   ledgers, reconciliation.
4. **Privacy by design.** Accounting data and conversation content are
   separated. Seeing *how much* someone spent never implies seeing *what* they
   wrote.
5. **One deployable application.** Laravel + Inertia + React in a single
   repository and a single deployment unit.
6. **Boring technology.** Prefer Laravel conventions and first-party packages;
   add dependencies only when they remove real complexity.

## 2. System overview

```
Browser
  │  HTML/JSON (Inertia)          SSE stream (fetch POST)
  ▼                                   ▼
React + TypeScript + shadcn/ui (single Vite bundle, code-split per page)
  │
  ▼
Nginx ──► PHP-FPM ──► Laravel application
                        ├── HTTP layer (controllers, form requests, Inertia responses, SSE)
                        ├── Domain layer (app/Domain/*)
                        │     Identity · Institution · AI · Conversations
                        │     Budget · Usage · Audit
                        ├── Eloquent models (app/Models)
                        └── Console (scheduler, maintenance commands)
                              │
            ┌─────────────────┼─────────────────────┐
            ▼                 ▼                     ▼
       MySQL 8.4         Redis (prod)        AI provider APIs
  (source of truth,   (cache, sessions,     (HTTPS, streaming)
   budget locks)      rate limits, queue)
```

## 3. Technology stack

| Layer | Choice | Notes |
|---|---|---|
| Language | PHP 8.4+ | |
| Framework | Laravel 13 | |
| Database | **MySQL 8.4 LTS**, InnoDB, `utf8mb4` | Only officially supported database. MariaDB is not tested. |
| Cache / sessions / rate limiting / queue | Redis in production; `database` drivers in development | Budget correctness never depends on Redis. |
| Frontend | React 19, TypeScript, Inertia.js v3, Tailwind CSS v4, shadcn/ui, Vite+ | Based on the official Laravel React starter kit. |
| AI SDK | Direct HTTP adapters (Laravel HTTP client + SSE parser) behind Ada's own interface | See [provider-architecture.md](provider-architecture.md). |
| Auth | SAML 2.0 (onelogin/php-saml) against a Google Workspace SAML app, behind Ada's own interface | See [authentication.md](authentication.md). |
| Tests | Pest (PHP), Vitest (TS), Playwright (E2E) | PHP tests run against MySQL, never SQLite. |

### Why MySQL is the single supported database

Supporting several databases would require testing locking semantics, decimal
behaviour and collations on each. The budget engine relies on InnoDB row locks
(`SELECT … FOR UPDATE`), exact `DECIMAL` arithmetic and `CHECK` constraints
(MySQL ≥ 8.0.16). We therefore support and test exactly one engine: MySQL 8.4
LTS. Details and MySQL-specific decisions are in
[database-design.md](database-design.md).

## 4. Laravel application structure

We keep Laravel's conventional directories and add one `app/Domain` directory
for business logic. The brief proposed both `app/Domain` and `app/Services`;
having two homes for business logic tends to blur boundaries, so **all business
logic lives in `app/Domain`**.

```
app/
├── Domain/
│   ├── Identity/          # authentication providers, login, provisioning, roles
│   ├── Institution/       # institution settings, branding, theme token generation
│   ├── AI/                # provider contracts & adapters, model registry, aliases
│   ├── Conversations/     # conversations, messages, chat generation orchestration
│   ├── Budget/            # budget periods, reservations, policies, enforcement
│   ├── Usage/             # cost calculation, usage events, reporting queries
│   └── Audit/             # audit logger, redaction
├── Models/                # Eloquent models (Laravel convention, factories, IDE support)
├── Policies/              # authorization policies
├── Http/
│   ├── Controllers/       # thin; delegate to Domain actions
│   ├── Middleware/
│   └── Requests/          # validation
├── Console/Commands/      # ada:* maintenance commands
└── Providers/             # service providers (bindings for Domain contracts)
```

Each domain folder uses the same internal layout, created only when needed:

```
Domain/Budget/
├── Actions/        # single-purpose use cases (ReserveBudget, SettleReservation, ...)
├── Contracts/      # interfaces other domains depend on
├── Data/           # immutable DTOs and value objects (Usd, TokenUsage, ...)
├── Exceptions/     # domain exceptions (BudgetExhausted, ...)
├── Enums/
└── Services/       # stateful collaborators used by several actions
```

**Eloquent models stay in `app/Models`.** Moving them into domain folders
works but fights Laravel tooling (factories, `make:model`, IDE helpers,
community packages) for little benefit. Domains own the *behaviour*; models are
persistence.

### Domain boundaries and dependency direction

```
            Conversations
           /      |      \
          ▼       ▼       ▼
         AI     Budget   Usage
          \       |       /
           ▼      ▼      ▼
         Institution · Identity · Audit  (leaf / cross-cutting)
```

| Domain | Owns | Must not depend on |
|---|---|---|
| Identity | users, identities, roles, groups, login | Conversations, AI |
| Institution | institution/auth/chat settings, branding | everything else |
| AI | providers, credentials, models, aliases, provider adapters, input token counters, streaming normalization | Budget, Usage, Conversations |
| Budget | budget policies, periods, reservations, enforcement | HTTP, providers, Conversations |
| Usage | cost calculation, usage events, reports | HTTP, providers |
| Conversations | conversations, messages, `ChatGenerationService` | HTTP (so the V2 API can reuse it) |
| Audit | audit log writing & redaction | everything else |

Rules:

- Controllers are thin: validate → authorize → call a Domain action → return an
  Inertia response or stream.
- `Budget` and `Usage` never know about HTTP, SSE or provider SDKs. They receive
  normalized value objects (`TokenUsage`, `PricingSnapshot`, `Usd`).
- `ChatGenerationService` has no knowledge of HTTP. It yields normalized events;
  the HTTP layer turns them into SSE. The future `/v1/chat/completions`
  endpoint will turn the same events into OpenAI-format chunks.

## 5. Request flows

### 5.1 Inertia page request

```
GET /c/{conversation}
  → auth + active-user middleware
  → ConversationController@show (ConversationPolicy::view — owner only)
  → Inertia::render('chat/show', { conversation, messages, aliases })
  + shared props: auth.user, institution (branding), budget summary, locale, translations meta
```

Initial page loads return full HTML with the Inertia page object; subsequent
navigation is JSON. The conversation list in the sidebar uses Inertia
deferred / merge props so it does not delay the first paint.

### 5.2 Chat message (streaming)

```
POST /chat/messages   (fetch, X-XSRF-TOKEN header, Accept: text/event-stream;
                       body: conversation_id or none for a new one, model_alias_id, content)
  1. Validate input, authorize (owner, alias allowed for user's group, user active)
  2. Rate limit (per-user requests/minute from group)
  3. Build ChatRequest (history truncated to model context window)
  3b. InputTokenCounter::count(ChatRequest)
         ── provider token-count endpoint for the exact payload
            (estimator only as a fallback)
  4. Budget::reserve()           ── short DB transaction, row lock, commits
                                    reservation = counted input cost × (1 + margin)
                                                + max possible output cost
                                    (max_output_tokens capped to remaining budget)
  5. Persist user message + assistant message (status=streaming)
  6. Provider stream ─► normalize ─► SSE "delta" events ─► browser
         (assistant content flushed to DB about every second)
  7. finally: Budget::settle() / release()  ── short DB transaction
  8. SSE "message.completed" (or "error")
```

As built (M6): `MessageController` streams what
`ChatGenerationService::send()` yields; `regenerate()` (`POST
/chat/messages/{message}/regenerate`) runs steps 3–8 again for the last answer
as a sibling of it (same `parent_message_id`). Refusals before anything is
stored (budget, rate limit, alias not allowed, context too long) are plain JSON
responses with a stable error code, not a stream.

Locks are held only inside the millisecond-scale reserve and settle
transactions, **never for the duration of a stream**. Token counting (step 3b)
happens *before* the lock is taken, so a slow count-tokens call never holds a
lock. Full details: [budget-engine.md](budget-engine.md).

### 5.3 Hard budget enforcement in one paragraph

Ada aims for the strongest enforcement a pre-paid, streaming API allows. The
input side of every request is **measured**, not guessed: before the request
is sent, the exact payload is counted with the provider's own token-count
endpoint (`InputTokenCounter`, AI domain). The output side is **bounded**:
`max_output_tokens` is always sent to the provider and is lowered to what the
remaining budget can pay for. The reservation is therefore
`input_cost + maximum_possible_output_cost` (plus a configurable per-provider
safety margin for counters known to deviate slightly). Heuristic estimation is
only a fallback when no counter is available, and can be disabled
(`reject` policy) by institutions that prefer refusing a request to risking an
overshoot.

## 6. Streaming architecture

**Decision:** stream directly from the HTTP request using Server-Sent Events
over a `fetch` POST. No queue worker and no WebSocket server are involved.

| Option | Verdict |
|---|---|
| **SSE from the request (chosen)** | One request, one lifecycle; `finally` blocks run in the same process that reserved the budget; works with plain PHP-FPM + Nginx. |
| Queue job + WebSockets (Reverb/Pusher) | Extra always-on service, extra failure modes (job ran but socket dropped), harder self-hosting. No benefit at university scale. |
| Long polling | Worse UX and more requests. |
| Native `EventSource` | Supports GET only; we need POST with a body and CSRF. |

Server side:

- `ignore_user_abort(true)` so that the PHP process keeps control after the
  browser disconnects; after each flush, `connection_aborted()` is checked and
  the provider stream is stopped (to stop incurring cost).
- A hard maximum stream duration (config, default 5 minutes) bounds worker
  usage and reservation TTLs.
- Headers: `Content-Type: text/event-stream`, `Cache-Control: no-cache`,
  `X-Accel-Buffering: no` (disables Nginx buffering).
- The assistant message row is created before streaming starts
  (`status = streaming`) and its content is flushed periodically, so a page
  reload during generation shows partial output and never loses it.

Client side: a small `useChatStream` hook built on `fetch` + `ReadableStream`
+ `eventsource-parser`, with an `AbortController` for "Stop". See
[frontend-architecture.md](frontend-architecture.md).

SSE event protocol (Ada → browser):

| Event | Payload | Meaning |
|---|---|---|
| `message.started` | `conversation_id`, `user_message_id`, `assistant_message_id`, `model_alias_id`, `output_capped` | Messages persisted, generation starting |
| `delta` | `text` | Incremental assistant text |
| `message.completed` | `assistant_message_id`, `status`, `finish_reason`, `usage` (tokens, cost) | Generation finished (or stopped) and settled |
| `error` | `code` (stable, translatable), `retryable`, `assistant_message_id` | Generation failed; budget released or settled |

**Stop** is `POST /chat/messages/{message}/cancel`: it sets a short-lived cache
flag (`chat:cancel:{id}`) that the streaming process checks every 0.5 seconds,
next to `connection_aborted()`. The provider stream is closed, the partial
answer is kept with `status = cancelled` and what was generated is charged.
The browser aborts the fetch itself only if the stream has not ended 5 seconds
after the request. **Regenerate** creates a new assistant message with the same
`parent_message_id`; the conversation shows the active branch (the parent chain
of the newest message). Tool calls would be new event types.

Provider streams are parsed line by line (`SseParser`): PHP's HTTP stream
blocks a `read(8192)` until 8 KiB have arrived, which would hold back small
events and make the answer appear all at once.

Answers interrupted by a dead process (still `streaming` when their
reservation expires) are settled by `ada:budget:expire-reservations`: the
stored partial text is charged as an estimate and the message is marked
`failed` with the error code `generation_interrupted`.

Local development: `php artisan serve` handles one request at a time unless
`PHP_CLI_SERVER_WORKERS` is set; set it (e.g. `4`) so that Stop can reach the
server during a stream.

### Capacity note

Each active stream occupies one PHP-FPM worker for its duration. For a
university (tens of concurrent streams at peak) this is fine with a suitably
sized `pm.max_children`. The scale-out path, if ever needed, is Laravel Octane
(FrankenPHP/Swoole) without changes to domain code. Octane is **not** part of V1.

## 7. Time handling

- All `created_at`/`updated_at` and other time columns are MySQL `DATETIME`
  declared explicitly with `$table->dateTime(...)` in migrations (not
  `timestamps()`, which creates `TIMESTAMP` columns that overflow in 2038).
- The application timezone (`config/app.php`) and the MySQL connection
  timezone are both **UTC**; every stored value is UTC.
- The institution timezone is used in exactly two places: computing budget
  period boundaries, and formatting dates for display.

## 8. Data stores

- **MySQL** is the single source of truth: users, settings, conversations,
  budget periods and reservations, usage ledger, audit log.
- **Redis** (production): cache (settings, model registry), sessions, rate
  limiters, queue, cancel flags. Losing Redis loses sessions and rate-limit
  counters, **never money**.
- **Local disk** (`storage/app/public`): uploaded logos and favicons.

## 9. Background processing

Scheduler (`php artisan schedule:run` every minute via cron or a container):

| Command | Frequency | Purpose |
|---|---|---|
| `ada:budget:expire-reservations` | every minute | Release/settle stale reservations |
| `ada:budget:reconcile` | daily | Verify `spent_usd` equals the usage ledger; report differences |
| `ada:retention:prune` | daily | Apply conversation retention policy (when enabled) |

Queue (low volume in V1): audit/report exports later, e-mail notifications.
Streaming does **not** use the queue.

## 10. Configuration model

| Kind | Where | Examples |
|---|---|---|
| Secrets needed before anyone can log in | `.env` only | `APP_KEY`, DB credentials, SAML IdP settings |
| Institution & product settings | Database (typed settings), seeded from `.env` by `ada:install` | name, logo, colours, allowed domains, default locale, timezone |
| Provider API keys | Database (encrypted) with `.env` fallback | OpenAI, Anthropic, Gemini keys |
| Operational tuning | `config/ada.php` backed by `.env` | stream max duration, reservation TTL, per-provider token-count safety margins, counter failure policy |

After installation, **the database is the source of truth** for institution
settings; environment variables only provide initial values. This avoids the
confusing situation where an admin changes a value in the UI and a deploy
silently reverts it.

## 11. Deployment

Two supported paths, same application ([deployment.md](deployment.md),
Turkish [deployment.tr.md](deployment.tr.md)):

**Standard (bare metal / VM)** — Ubuntu 24.04 LTS, Nginx, PHP 8.4-FPM,
MySQL 8.4, Redis, Node.js (build only), cron (scheduler). Nginx honours
`X-Accel-Buffering: no` on the chat stream, `fastcgi_read_timeout` is above
the maximum stream duration and `text/event-stream` is not gzipped. Example
files in `deploy/` (nginx site, PHP-FPM pool, cron entry, backup script,
production `.env`).

**Docker** — one image (`Dockerfile`, multi-stage: Composer → front-end build
on Node → PHP-FPM + nginx under supervisord as `www-data` on port 8080) used
by the `app` and `scheduler` services of `compose.production.yml`, plus
`mysql:8.4` and `redis`. The container runs migrations and caches the
configuration when it starts; TLS terminates at the host's reverse proxy.
CI builds the image and smoke-tests the compose stack. Docker is optional;
nothing in the application assumes it.

There is no queue worker in V1: nothing is dispatched to a queue.

Operational commands: `ada:install` (first-run setup), `ada:user:promote`
(break-glass role assignment), `ada:doctor` (checks debug mode, HTTPS, log
rotation, database, scheduler heartbeat, sign-in, providers),
`ada:credentials:reencrypt` (after an `APP_KEY` rotation),
`ada:retention:prune`.

## 12. Extensibility points (not V1)

| Future feature | Where it plugs in |
|---|---|
| OIDC / Entra ID / SAML / LDAP | New `IdentityProvider` adapters (Identity domain) |
| OpenRouter, Azure OpenAI, Bedrock, local OpenAI-compatible | New `ChatProvider` adapters (AI domain) |
| OpenAI-compatible external API with personal tokens | New HTTP controller over `ChatGenerationService`; same budget and usage path |
| File attachments / multimodal | `message_attachments` table + capability flags on models |
| RAG, tools, web search | New stream event types and orchestration inside Conversations |
| Non-monthly budget periods | `budget_periods.period_start/period_end` already generic |
| Data retention | Retention settings + prune command; usage ledger decoupled from messages |
