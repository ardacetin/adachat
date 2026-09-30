# Provider Architecture

> Status: **Proposed (M0)**. Location: `app/Domain/AI`.

The provider layer turns a normalized `ChatRequest` into a normalized stream
of events and a normalized `TokenUsage`, for any supported AI provider. It
knows nothing about budgets, users, prices or HTTP responses to the browser.

```
ChatGenerationService (Conversations)
        │  ChatRequest
        ▼
ProviderManager ──► ChatProvider (Ada interface)
        │                 │
        │                 ├── PrismChatProvider (default adapter)
        │                 │        └── Prism PHP ──► OpenAI / Anthropic / Gemini
        │                 └── Native adapters (only where Prism falls short)
        │
        └──► InputTokenCounter (Ada interface)
                 ├── OpenAIInputTokenCounter
                 ├── AnthropicInputTokenCounter
                 ├── GeminiInputTokenCounter
                 └── EstimatedInputTokenCounter (fallback only)
```

## 1. Contracts

```php
namespace App\Domain\AI\Contracts;

interface ChatProvider
{
    /** @return iterable<StreamEvent> */
    public function stream(ChatRequest $request, CancellationToken $cancel): iterable;

    public function complete(ChatRequest $request): ChatResult;
}

interface InputTokenCounter
{
    public function count(ChatRequest $request): InputTokenCount;

    public function supports(AiModel $model): bool;
}
```

Value objects (`App\Domain\AI\Data`):

```php
final readonly class ChatRequest {
    public function __construct(
        public string $providerModelId,
        public ?string $systemPrompt,
        /** @var list<ChatMessage> */ public array $messages,
        public int $maxOutputTokens,          // always set; decided by the budget engine
        public ?float $temperature = null,
        public array $options = [],           // provider-specific passthrough (validated)
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
    public InputCountMethod $method;        // ProviderEndpoint | LocalTokenizer | Estimated
    public float $marginRatio;              // from config/ada.php
    public function reservedTokens(): int;  // ceil(tokens × (1 + marginRatio))
}
```

Stream events:

| Event | Fields |
|---|---|
| `TextDelta` | `text` |
| `ReasoningDelta` | `text` (not shown in V1; allows future display) |
| `UsageReported` | `TokenUsage` (may arrive once at the end, or cumulatively) |
| `Finished` | `FinishReason` (`stop`, `length`, `content_filter`, `cancelled`, `error`), `providerRequestId` |

**Change from the brief:** `calculateCost()` is **not** part of the provider
interface. Providers report tokens; `App\Domain\Usage\CostCalculator` turns
`TokenUsage` + `PricingSnapshot` into money. This keeps pricing (database,
admin-managed, snapshotted) in one place and keeps adapters free of financial
logic.

## 2. Adapters

### 2.1 Prism evaluation

[Prism PHP](https://prismphp.com) offers a Laravel-native, unified API for
OpenAI, Anthropic and Gemini including streaming and usage reporting.

| Pros | Cons / risks |
|---|---|
| One API for all three V1 providers; active Laravel ecosystem project | Pre-1.0 style churn in APIs has happened |
| Streaming, system prompts, usage objects, provider options | Usage completeness (cache writes, reasoning tokens) must be verified per provider |
| Saves writing three SSE parsers | Abort/cancellation behaviour inside generators must be verified |
| Easy to add more providers later (OpenRouter, Mistral, Groq, Ollama…) | Ties upgrade cadence to Prism's |

**Decision:** use `PrismChatProvider` as the default adapter, strictly behind
Ada's `ChatProvider` interface. Prism types never leak outside
`App\Domain\AI\Adapters\Prism`. In M4 a spike validates, per provider, with
recorded fixtures and contract tests:

1. Text deltas arrive incrementally.
2. Final usage includes input, output, cached input, cache write and reasoning
   tokens where the provider reports them.
3. `max_output_tokens` is passed through and respected.
4. Breaking out of the stream closes the upstream HTTP connection.
5. Errors map to Ada exceptions (§5).

Any provider that fails the spike gets a native adapter
(`OpenAIChatProvider`, `AnthropicChatProvider`, `GeminiChatProvider`) using
Laravel's HTTP client with streaming + a small SSE parser. The rest of Ada is
unaffected.

### 2.2 Per-provider notes

| Topic | OpenAI | Anthropic | Gemini |
|---|---|---|---|
| Output cap | `max_output_tokens` (Responses) / `max_completion_tokens` — includes reasoning | `max_tokens` — includes thinking | `maxOutputTokens` — includes thinking |
| Usage in stream | final event (Responses) / `stream_options.include_usage` (Chat Completions) | `message_start` (input) + `message_delta` (output) | `usageMetadata` on chunks/final |
| Cached input | `cached_tokens` is a **subset** of input → adapter subtracts | cache read/write reported **separately** | `cachedContentTokenCount` subset → subtract |
| Reasoning | `reasoning_tokens` subset of output → adapter splits | thinking billed as output | `thoughtsTokenCount` |
| Request id | `x-request-id` header | `request-id` header | response id |

The adapter's job is to turn each provider's conventions into the disjoint
`TokenUsage` fields.

## 3. Streaming normalization

- Adapters yield `StreamEvent`s as soon as provider chunks arrive; no
  buffering.
- `CancellationToken` is checked between chunks; when set (client abort or
  cancel flag), the adapter stops iterating and closes the upstream
  connection, then yields `Finished(cancelled)` with whatever usage is known.
- If the stream ends without `UsageReported`, the orchestrator settles with
  input from the reservation's counted tokens and output counted from the
  generated text (flagged `is_estimated`). See
  [budget-engine.md §10](budget-engine.md#10-failure-handling).

## 4. Provider manager and credentials

- `ProviderManager::for(AiModel $model): ChatProvider` resolves the adapter by
  `providers.driver` and injects the decrypted credential.
- Credentials: active row in `provider_credentials` (Laravel `encrypted`
  cast) → fallback to `.env` (`OPENAI_API_KEY`, `ANTHROPIC_API_KEY`,
  `GEMINI_API_KEY`). Decrypted secrets live only in memory for the request.
- Keys are never returned to the frontend; the admin UI shows
  `last_four` only (`sk-…8f2a`). Saving a key does a cheap validation call
  (e.g. list models) and audit-logs `provider.credential_rotated` without the
  value.
- HTTP client logging is disabled for provider calls; exception reports strip
  `Authorization`, `x-api-key`, `x-goog-api-key` headers and request bodies.

## 5. Errors

| Ada exception | Typical provider signal | Budget action | User message key |
|---|---|---|---|
| `ProviderAuthFailed` | 401/403 | release | `chat.errors.provider_unavailable` (admins alerted) |
| `ProviderRateLimited` | 429 | release | `chat.errors.rate_limited` (retryable) |
| `ProviderOverloaded` | 529 / 503 | release | `chat.errors.overloaded` (retryable) |
| `ContextLengthExceeded` | 400 context errors | release | `chat.errors.context_too_long` |
| `ContentFiltered` | safety block / refusal finish | settle (tokens consumed) | `chat.errors.content_filtered` |
| `ProviderTimeout` | connect/read timeout | release or settle (§budget-engine) | `chat.errors.timeout` |
| `ProviderUnavailable` | 5xx / network | release or settle | `chat.errors.provider_unavailable` |

Error codes sent over SSE are stable strings; the frontend translates them.

## 6. Input token counting

The budget engine needs the input size **before** sending the request. Each
provider has a counter that counts the exact normalized `ChatRequest` that will
be sent (system prompt, full truncated history, tool definitions when added).

### 6.1 Implementations

**`OpenAIInputTokenCounter`**
- Primary: OpenAI's input-token counting endpoint for the Responses API
  (`POST /v1/responses/input_tokens`), called with the same payload as the
  generation request.
- Secondary (`local_tokenizer`): a local tokenizer for the model's encoding
  (e.g. `o200k_base`) plus documented per-message overhead, for models or
  deployments (e.g. OpenAI-compatible endpoints) where the endpoint is not
  available.
- The M4 spike confirms endpoint availability per model and whether Ada uses
  the Responses or Chat Completions API for generation; the counter must match
  the API used.

**`AnthropicInputTokenCounter`**
- `POST /v1/messages/count_tokens` with the same `model`, `system`,
  `messages` (and `tools`/`thinking` when used).
- Anthropic documents the result as an estimate that may differ slightly from
  billed input, so a **safety margin** applies (default 5 %, configurable).
- The endpoint has its own rate limits; 429s trigger the counter failure
  policy, not a generation failure.

**`GeminiInputTokenCounter`**
- `POST /v1beta/models/{model}:countTokens` with the same `contents` and
  `systemInstruction`.

**`EstimatedInputTokenCounter`** (fallback only)
- `ceil(utf8_bytes / bytes_per_token_floor) + per_message_overhead`, with a
  conservative floor (≈ 2 bytes/token) and a large margin (default 50 %).
- Used only when the provider counter is unavailable and
  `on_counter_failure = estimate`. Never the primary path.

### 6.2 Resolution chain

```
counterFor(model):
    provider_endpoint counter  (if supports(model))
    → local_tokenizer counter  (OpenAI family only, if configured)
    → Estimated counter        (only if on_counter_failure = 'estimate')
    → otherwise throw TokenCountUnavailable  (request refused, retryable)
```

### 6.3 Operational concerns

- Timeout: 3 s (config), one retry; counting happens outside DB transactions.
- Cost: count endpoints are free at the time of writing; if a provider ever
  charges for counting, it is recorded as `other_cost_usd`.
- Caching: result cached by `(model, sha256(payload))` for a short TTL to make
  retries and regenerations cheap.
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
- Deprecations: `ai_models.metadata.deprecated_at` shows admin warnings.

Model selector UX: primary line = alias name ("Advanced"), secondary =
description ("Best for research and analysis"), optional detail (tooltip) =
"Claude Sonnet · Anthropic" when `show_model_details` is enabled.

## 8. Pricing

Prices per million tokens live on `ai_models` (`input`, `output`,
`cached_input`, `cache_write`, optional tiers in `metadata`). Admins maintain
them; Ada ships **no** hard-coded prices, only an optional seeder with example
models that admins must confirm. At settlement the prices are snapshotted onto
the usage event.

## 9. Adding a provider (future)

1. Add a `driver` value.
2. Implement `ChatProvider` (via Prism if supported) and an
   `InputTokenCounter` (or declare the local-tokenizer / estimator path and a
   margin).
3. Implement usage normalization to disjoint `TokenUsage`.
4. Add contract tests with recorded fixtures.

Candidates: OpenRouter, Azure OpenAI, AWS Bedrock, Mistral, Groq,
OpenAI-compatible local endpoints (vLLM, Ollama). For endpoints without a
counter, the local tokenizer or the `reject` policy applies.

## 10. Tests

- Contract test suite run against every adapter with recorded SSE fixtures:
  delta order, usage normalization (disjoint fields), finish reasons,
  cancellation, error mapping.
- Counter tests: payload parity (the counter request body is derived from the
  same `ChatRequest` as the generation), margin application, fallback chain,
  `reject` policy.
- Opt-in live tests (nightly, CI secrets): counted vs billed input within
  margin for each configured provider.
