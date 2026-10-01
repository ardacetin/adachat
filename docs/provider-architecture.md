# Provider Architecture

> Status: **Implemented (M4)**. Location: `app/Domain/AI`.

The provider layer turns a normalized `ChatRequest` into a normalized stream
of events and a normalized `TokenUsage`, for any supported AI provider. It
knows nothing about budgets, users, prices or HTTP responses to the browser.

```
ChatGenerationService (Conversations, M6)
        │  ChatRequest
        ▼
ProviderManager ──► HttpChatProvider (implements ChatProvider + InputTokenCounter)
        │                 ├── OpenAIChatProvider     (Responses API)
        │                 ├── AnthropicChatProvider  (Messages API)
        │                 └── GeminiChatProvider     (Generative Language API)
        │                          └── Laravel HTTP client + SseParser
        │
TokenCounting ──► provider endpoint counter (same adapter)
                  └── EstimatedInputTokenCounter (fallback only)
```

## 1. Contracts

```php
namespace App\Domain\AI\Contracts;

interface ChatProvider
{
    /** Yields TextDelta / ReasoningDelta / UsageReported, ends with one Finished. */
    public function stream(ChatRequest $request, ?CancellationToken $cancellation = null): iterable;

    public function complete(ChatRequest $request): ChatResult;
}

interface InputTokenCounter
{
    /** Input tokens of the exact request that will be sent, without margin. */
    public function count(ChatRequest $request): int;
}

interface CancellationToken
{
    public function isCancelled(): bool;
}
```

The margin is not the counter's concern: `Services\TokenCounting` wraps the
raw count in an `InputTokenCount` with the method used and the configured
margin (§6).

Value objects (`App\Domain\AI\Data`):

```php
final readonly class ChatRequest {
    public function __construct(
        public string $model,                 // provider model id
        public array $messages,               // list<ChatMessage>
        public int $maxOutputTokens,          // always set; decided by the budget engine
        public ?string $systemPrompt = null,
        public ?float $temperature = null,
    ) {}
}

final readonly class TokenUsage {           // all fields DISJOINT
    public int $input;                      // non-cached input
    public int $cachedInput;                // cache reads
    public int $cacheWrite;                 // cache writes
    public int $output;                     // visible output
    public int $reasoning;                  // hidden reasoning/thinking
}

final readonly class InputTokenCount {
    public int $tokens;
    public InputCountMethod $method;        // ProviderEndpoint | Estimated
    public float $marginRatio;              // from config/ada.php
    public function reservedTokens(): int;  // ceil(tokens × (1 + marginRatio))
}
```

Stream events:

| Event | Fields |
|---|---|
| `TextDelta` | `text` |
| `ReasoningDelta` | `text` (not shown in V1; allows future display) |
| `UsageReported` | `TokenUsage` (once, just before `Finished`, when the provider reported usage) |
| `Finished` | `FinishReason` (`stop`, `length`, `content_filter`, `cancelled`, `error`), `providerRequestId` |

**Change from the brief:** `calculateCost()` is **not** part of the provider
interface. Providers report tokens; `App\Domain\Usage\CostCalculator` turns
`TokenUsage` + `PricingSnapshot` into money. This keeps pricing (database,
admin-managed, snapshotted) in one place and keeps adapters free of financial
logic.

## 2. Adapters

### 2.1 Decision: direct HTTP

M0 proposed [Prism PHP](https://prismphp.com) as the default adapter with
native adapters as a fallback. For M4 the maintainers chose **direct HTTP
adapters** instead: Laravel's HTTP client with a streamed body and a small SSE
parser (`Http\SseParser`). Reasons:

- The budget engine depends on details a unified library tends to abstract
  away: every usage field (cache reads/writes, reasoning tokens), the exact
  output-cap parameter, and the providers' token-count endpoints built from
  the *same* payload as generation.
- Cancellation must close the upstream connection immediately.
- The adapters are small (≈ 150 lines each) and fully covered by fixture
  contract tests; there is no third-party upgrade cadence to track.

Everything stays behind Ada's own contracts, so adopting a library later for
long-tail providers remains possible without touching the rest of Ada.

`Providers\HttpChatProvider` holds the shared plumbing (auth headers,
timeouts, error mapping, streaming, `complete()` on top of `stream()`,
`count()`, and a `checkConnection()` model-listing call). Each adapter only
supplies its payload, URLs, count body and event translation.

| | OpenAI | Anthropic | Gemini | OpenAI-compatible |
|---|---|---|---|---|
| Default base URL | `https://api.openai.com/v1` | `https://api.anthropic.com/v1` | `https://generativelanguage.googleapis.com/v1beta` | none (required) |
| Generation | `POST /responses` (`stream: true`, `store: false`) | `POST /messages` (`stream: true`) | `POST /models/{m}:streamGenerateContent?alt=sse` | `POST /chat/completions` (`stream: true`, `stream_options.include_usage`) |
| Auth header | `Authorization: Bearer` | `x-api-key` + `anthropic-version` | `x-goog-api-key` | `Authorization: Bearer`, only when a key is set |
| System prompt | `instructions` | `system` | `systemInstruction` | first message, role `system` |
| Output cap | `max_output_tokens` | `max_tokens` | `generationConfig.maxOutputTokens` | `max_tokens` |
| Token count | `POST /responses/input_tokens` | `POST /messages/count_tokens` | `POST /models/{m}:countTokens` (`generateContentRequest`) | none: estimated (§6) |
| Images (v1.1) | `input_image` with a data URL | `image` block, base64 source | `inline_data` part | `image_url` with a data URL |
| Connection check | `GET /models` | `GET /models` | `GET /models` | `GET /models` |

The base URL is per provider row (`providers.base_url`), so regional
endpoints or proxies can be configured without code changes. Gateways and
local servers that speak Chat Completions use the OpenAI-compatible driver
(§9).

### 2.2 Per-provider notes

| Topic | OpenAI | Anthropic | Gemini | OpenAI-compatible |
|---|---|---|---|---|
| Output cap | `max_output_tokens` — includes reasoning | `max_tokens` — includes thinking | `maxOutputTokens` — includes thinking | `max_tokens` — server-dependent |
| Usage in stream | `response.completed` / `response.incomplete` | `message_start` (input, cache) + `message_delta` (output) | `usageMetadata` on chunks (last wins) | `usage` on the last chunk (`include_usage`) |
| Finish reason | `completed` → stop; `incomplete_details.reason` `max_output_tokens` → length, `content_filter` → content_filter | `stop_reason` `end_turn`/`stop_sequence`/`tool_use` → stop, `max_tokens` → length, `refusal` → content_filter | `STOP` → stop, `MAX_TOKENS` → length, `SAFETY`/`RECITATION`/`BLOCKLIST`/`PROHIBITED_CONTENT`/`SPII` → content_filter | `finish_reason` `length` → length, `content_filter` → content_filter, others → stop; `[DONE]` without a reason → stop |
| Cached input | `cached_tokens` is a **subset** of input → adapter subtracts | cache read/write reported **separately** | `cachedContentTokenCount` subset → subtract | `prompt_tokens_details.cached_tokens` subset → subtract |
| Reasoning | `reasoning_tokens` subset of output → adapter splits | thinking billed as output (not split) | `thoughtsTokenCount` (separate) | `completion_tokens_details.reasoning_tokens` subset → split; text from `delta.reasoning_content` / `delta.reasoning` |
| Request id | `x-request-id` header | `request-id` header | `responseId` | `x-request-id` header, else the chunk `id` |

The adapter's job is to turn each provider's conventions into the disjoint
`TokenUsage` fields.

## 3. Streaming normalization

- Adapters yield `StreamEvent`s as soon as provider chunks arrive; no
  buffering.
- `SseParser` checks the `CancellationToken` before every read and after
  every event; when set (client abort or Stop), it stops reading and closes
  the upstream body. The adapter then yields `UsageReported` (if any usage was
  seen) and `Finished(cancelled)`.
- Errors inside an otherwise successful stream (`error` events,
  `response.failed`) are thrown as Ada exceptions (§5).
- If the stream ends without `UsageReported`, the orchestrator settles with
  input from the reservation's counted tokens and output counted from the
  generated text (flagged `is_estimated`). See
  [budget-engine.md §10](budget-engine.md#10-failure-handling).

## 4. Provider manager and credentials

- `ProviderManager::forModel(AiModel)` / `forProvider(Provider)` resolve the
  adapter by `providers.driver` and inject the decrypted credential, base URL
  and timeouts (`ada.providers.timeout`, `ada.providers.counter_timeout`).
- Credentials: active row in `provider_credentials` (Laravel `encrypted`
  cast) → fallback to `.env` (`OPENAI_API_KEY`, `ANTHROPIC_API_KEY`,
  `GEMINI_API_KEY`, `OPENAI_COMPATIBLE_API_KEY`). Decrypted secrets live only
  in memory for the request. The OpenAI-compatible driver alone may have no
  key at all (local servers); then no `Authorization` header is sent.
- `Services\CredentialVault` stores and rotates keys: the new row stores
  `last_four`, the previous active row is deactivated (history kept), and
  `provider.credential_rotated` is audit-logged without the value.
- Keys are never returned to the frontend; the admin UI shows `••••8f2a`
  only. "Test connection" (and `php artisan ada:provider:check {slug}`) runs
  a model-listing call, which spends no tokens.
- Provider calls are not logged. Exception messages contain only the
  provider name, HTTP status, the provider's error code and a truncated
  message — never headers, keys or request bodies (`Http\ErrorMapper`).

## 5. Errors

| Ada exception | Typical provider signal | Budget action | `code()` |
|---|---|---|---|
| `ProviderAuthFailed` | 401/403 | release | `provider_unavailable` (admins alerted; users are not told about keys) |
| `ProviderRateLimited` | 429 | release | `rate_limited` (retryable) |
| `ProviderOverloaded` | 529 / 503 | release | `overloaded` (retryable) |
| `ContextLengthExceeded` | 400/413 with a context-length message | release | `context_too_long` |
| `InvalidProviderRequest` | other 4xx | release | `invalid_request` |
| `ContentFiltered` | safety block / refusal finish | settle (tokens consumed) | `content_filtered` |
| `ProviderTimeout` | connect/read timeout | release or settle (§budget-engine) | `timeout` |
| `ProviderUnavailable` | 5xx / network | release or settle | `provider_unavailable` |

`TokenCountUnavailable` (`token_count_unavailable`) is raised by
`TokenCounting` when counting fails and estimation is not allowed. Codes are
stable strings sent over SSE; the frontend translates them.

## 6. Input token counting

The budget engine needs the input size **before** sending the request. Each
provider has a counter that counts the exact normalized `ChatRequest` that will
be sent (system prompt, full truncated history, tool definitions when added).

### 6.1 Implementations

Counting is implemented by each adapter's `count()` method: the count body is
derived from the **same** `payload()` as generation, so what is counted is what
is sent (contract tests assert this parity).

**OpenAI** — `POST /responses/input_tokens` with the generation payload minus
`stream`, `store` and `max_output_tokens`. Default margin 0.

**Anthropic** — `POST /messages/count_tokens` with `model`, `system`,
`messages`. Anthropic documents the result as an estimate that may differ
slightly from billed input, so the default margin is 5 %. The endpoint has its
own rate limits; a 429 triggers the counter failure policy, not a generation
failure.

**Gemini** — `POST /models/{model}:countTokens` with
`generateContentRequest` (`model`, `contents`, `systemInstruction`). Default
margin 0.

**OpenAI-compatible** — Chat Completions has no count endpoint. Input is
estimated with `EstimatedInputTokenCounter` (below) and a 25 % margin; this
is the normal path for the driver, so nothing is logged and the
`on_counter_failure` policy does not apply. The estimate only sizes the
reservation: the request is settled with the usage the server reports in its
last chunk.

**`EstimatedInputTokenCounter`** —
`ceil(utf8_bytes / 2) + 8 per message`, a deliberately pessimistic floor.
For providers with a count endpoint it is a fallback only (50 % margin), used
when the counter failed and `on_counter_failure = estimate`.

Margins: `config/ada.php` → `budget.input_count_margins`
(`openai`, `anthropic`, `gemini`, `openai_compatible`, `estimated`).

### 6.2 Resolution chain

```
TokenCounting::count(model, request):
    driver without a count endpoint (OpenAI-compatible)  → method Estimated (driver margin, not logged)
    provider endpoint (adapter->count)                   → method ProviderEndpoint
    on failure, if ADA_ON_COUNTER_FAILURE = estimate     → method Estimated (warning logged, no content)
    otherwise throw TokenCountUnavailable                → request refused, retryable
```

### 6.3 Operational concerns

- Timeout: 3 s (`ada.providers.counter_timeout`), no retry; counting happens
  outside DB transactions.
- Cost: count endpoints are free at the time of writing; if a provider ever
  charges for counting, it is recorded as `other_cost_usd`.
- Caching by `(model, sha256(payload))` for retries/regenerations is planned
  with the chat flow (M6).
- Monitoring: each usage event stores `reserved_input_tokens` and
  `input_count_method`; the admin dashboard shows counted-vs-billed deviation
  per provider/model so margins can be tuned from evidence.
- Privacy: counting sends the same content to the same provider as the
  generation itself; no additional data exposure.

## 7. Model registry and aliases

- `ai_models`: concrete provider models with pricing and capabilities
  (admin-only). See [database-design.md](database-design.md).
- `model_aliases`: what users see — localized name and description, backing
  `ai_model_id`, per-alias `max_output_tokens`, optional system prompt.
  Permissions are granted to groups **per alias**.
- Changing an alias's backing model is instant for users and audit-logged.
- Model capabilities (`supports_vision`, `supports_files`, `supports_tools`,
  `supports_reasoning`, `context_window`) drive UI features and history
  truncation; nothing is hard-coded per provider name.
- Deprecations: `ai_models.metadata.deprecated_at` shows admin warnings (later).
- Admin screens (super admin only, audited): Providers, Models, Aliases under
  `/admin`. An alias's `max_output_tokens` may not exceed its model's.

Model selector UX: primary line = alias name ("Advanced"), secondary =
description ("Best for research and analysis"), optional detail (tooltip) =
"Claude Sonnet · Anthropic" when `show_model_details` is enabled.

## 8. Pricing

Prices per million tokens live on `ai_models` (`input`, `output`,
`cached_input`, `cache_write`, optional tiers in `metadata`). Admins maintain
them; Ada ships **no** hard-coded prices, only an optional seeder with example
models that admins must confirm. At settlement the prices are snapshotted onto
the usage event.

## 9. OpenAI-compatible servers

The `openai_compatible` driver speaks the OpenAI **Chat Completions** dialect
(`POST {base}/chat/completions`), which most gateways and self-hosted
servers offer. Add it under Admin → Providers with the type
"OpenAI-compatible (Chat Completions)". The base URL is required and ends in
`/v1`; the API key is optional. Then add the server's models under Models
with the model name exactly as the server expects it.

| Service | Base URL | API key | Notes |
|---|---|---|---|
| OpenRouter | `https://openrouter.ai/api/v1` | required | Model names such as `meta-llama/llama-3.3-70b-instruct`; enter OpenRouter's prices. |
| Groq | `https://api.groq.com/openai/v1` | required | |
| Ollama | `http://<host>:11434/v1` | none | Models as in `ollama list`, e.g. `llama3.1:8b`. |
| vLLM | `http://<host>:8000/v1` | optional (`--api-key`) | The model name given to `vllm serve`. |
| LM Studio | `http://<host>:1234/v1` | none | |

- **Network:** Ada must reach the server. From the production Docker image,
  `localhost` is the container itself: use the host's address or a service
  on the same Docker network. Prefer HTTPS when the server is not on the same
  host or a private network: prompts and answers travel to it.
- **Prices:** enter the price per million tokens the service charges. For
  your own servers enter `0`: requests are still reserved, settled and
  recorded, but cost nothing against budgets.
- **Token counting:** estimated before the request, settled with the reported
  usage (§6). A server that reports no usage is settled with Ada's estimate
  (flagged in the usage records).
- **Context window and output cap:** set them on the model as the server is
  configured (for Ollama, the `num_ctx` of the model), so that history
  truncation and output capping are correct.
- **Errors:** HTTP errors and `{"error": …}` chunks inside the stream are
  mapped like the other providers (§5); numeric codes such as OpenRouter's
  `429` count as rate limits.
- **Reasoning:** `reasoning_content` (DeepSeek, vLLM) and `reasoning`
  (OpenRouter) deltas are shown as reasoning.

## 10. Adding a provider

1. Add a `driver` value (and to the `providers_driver_check` constraint).
2. Extend `HttpChatProvider` (payload, URLs, count body, event translation),
   or implement `ChatProvider` + `InputTokenCounter` directly; declare a
   margin.
3. Implement usage normalization to disjoint `TokenUsage`.
4. Add contract tests with recorded fixtures.

Candidates for native drivers: Azure OpenAI, AWS Bedrock, Mistral. Services
with a Chat Completions endpoint need no new driver (§9). A driver without a
count endpoint declares it with `ProviderDriver::countsTokens()` and gets the
estimate path of §6.

## 11. Tests

- `tests/Unit/AI/ProviderContractTest.php` runs one contract suite against all
  four adapters with SSE fixtures (`tests/Fixtures/providers`) written from
  the providers' documented formats: delta order, disjoint usage, finish
  reasons, cancellation, error mapping (401/429/503/529/400/500/timeout),
  outgoing request shape, and count-body parity.
- `tests/Unit/AI/SseParserTest.php`: multi-line data, comments, partial
  chunks, cancellation.
- `tests/Feature/AI`: credential encryption/rotation/`.env` fallback,
  token-counting fallback chain and `reject` policy.
- `tests/Feature/AI/LiveProviderTest.php` (opt-in): with
  `ADA_LIVE_PROVIDER_TESTS=1` and provider keys in the environment, streams a
  short answer and counts tokens against each real API. Skipped by default and
  in CI; run it on the first deployment with real keys, since fixtures are not
  recordings.
