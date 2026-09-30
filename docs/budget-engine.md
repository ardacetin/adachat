# Budget Engine

> Status: **Proposed (M0)**. The budget engine is correctness-critical and
> must not be implemented without the tests listed in §12.

Location: `app/Domain/Budget` (enforcement) and `app/Domain/Usage` (cost
calculation, ledger). Tables: `budget_policies`, `budget_periods`,
`budget_reservations`, `usage_events` — see
[database-design.md](database-design.md).

## 1. Goals

1. A user cannot start a request they cannot afford — input is **counted by
   the provider** before sending, output is **capped** to what remains.
2. Concurrent requests cannot jointly exceed the limit.
3. Money is never permanently locked by failed or abandoned requests.
4. Every charge is recorded immutably with the prices valid at that time.
5. Monthly renewal happens automatically, with no dependence on a cron job
   running exactly at midnight.

## 2. Core formula

```
available = limit_usd − spent_usd − reserved_usd
```

All values are `Usd` value objects (`brick/math` `BigDecimal`, scale 10).
Floats are never used.

## 3. Effective limit and policy resolution

```
effective_limit(user) =
    user.monthly_limit_override_usd
    ?? user.group.budget_policy.monthly_limit_usd
```

Every user has exactly one group; a default group with a default policy always
exists (created by `ada:install`). There is no "unlimited" budget in V1.

## 4. Budget period lifecycle

- Period = calendar month in the **institution timezone**
  (`InstitutionSettings.timezone`), stored in UTC as a half-open interval
  `[period_start, period_end)`. Example for `Europe/Istanbul`:
  October 2026 = `[2026-09-30 21:00:00Z, 2026-10-31 21:00:00Z)`.
- **Lazy creation**: the row for the current period is created on the first
  request (or first budget display) within that period. There is no rollover
  job; "October 1 00:00" simply means that the next request resolves a new
  period and creates a fresh row with `spent = reserved = 0`.
- **Limit snapshot**: `limit_usd` is copied from the effective limit when the
  row is created. Past periods are never modified.
- **Mid-period policy changes**: when an admin changes a policy, group
  membership or a user override, the action offers "apply to current period",
  which updates `limit_usd` of the current open period(s) inside a locked
  transaction and writes an audit log entry. Default: apply (this matches
  admin expectations: "I gave this researcher $25").
- Optional scheduler job may pre-create periods for active users on the 1st
  to make reports complete; correctness does not depend on it.

```php
function currentPeriod(User $user, CarbonImmutable $now): BudgetPeriod
{
    [$start, $end] = PeriodCalculator::monthContaining($now, institutionTz());

    // Auto-committed, OUTSIDE the locking transaction (see §7.2).
    DB::statement(
        'INSERT INTO budget_periods (user_id, period_start, period_end, limit_usd,
                                     spent_usd, reserved_usd, created_at, updated_at)
         VALUES (?, ?, ?, ?, 0, 0, ?, ?)
         ON DUPLICATE KEY UPDATE id = id',
        [$user->id, $start, $end, effectiveLimit($user), $now, $now]
    );

    return BudgetPeriod::where('user_id', $user->id)
        ->where('period_start', $start)->firstOrFail();
}
```

`INSERT IGNORE` is intentionally not used: it downgrades *all* errors
(including constraint violations) to warnings.

## 5. Input token counting and reservation amount

Design principle: **measure the input, bound the output.** Heuristic
estimation is a fallback, never the primary mechanism.

### 5.1 `InputTokenCounter`

Contract in the AI domain (`App\Domain\AI\Contracts\InputTokenCounter`),
resolved per provider driver:

| Implementation | Source | Accuracy |
|---|---|---|
| `OpenAIInputTokenCounter` | OpenAI input-token counting endpoint for the exact request payload; local `o200k`-family tokenizer as secondary path when the endpoint is unavailable for a model | Exact / near-exact |
| `AnthropicInputTokenCounter` | Anthropic `POST /v1/messages/count_tokens` with the exact payload (system prompt, messages, tools) | Documented as an estimate that may differ slightly → safety margin |
| `GeminiInputTokenCounter` | Gemini `models/{model}:countTokens` with the exact `contents` + system instruction | Exact for the model |
| `EstimatedInputTokenCounter` | Conservative byte-based heuristic, no network call | **Fallback only** |

Each counter returns an `InputTokenCount { tokens, method, marginRatio }`
where `method ∈ {provider_endpoint, local_tokenizer, estimated}`. Details of
each adapter and endpoint verification (M4 spike) are in
[provider-architecture.md](provider-architecture.md#6-input-token-counting).

Rules:

- The counter receives **the same normalized `ChatRequest` that will be sent**
  (after history truncation, alias system prompt, parameters), so what is
  counted is what is billed.
- Counting happens **before** the budget transaction; no lock is held during
  the network call.
- Counter calls have a short timeout (config, default 3 s) and their own
  retry-once policy.
- Results may be cached for identical payloads (key = model + payload hash,
  short TTL) — useful for retries/regenerations.
- Latency: one extra round trip (typically 100–300 ms) before the first token.
  This is an accepted cost of hard enforcement; the UI shows the "thinking"
  state immediately, so perceived latency is minimal.

### 5.2 Safety margins

Per provider (and overridable per model) in `config/ada.php`:

```php
'budget' => [
    'input_count_margins' => [      // ratio added to counted input tokens
        'openai'    => 0.00,
        'anthropic' => 0.05,        // count_tokens is documented as an estimate
        'gemini'    => 0.00,
        'estimated' => 0.50,        // heuristic fallback, deliberately generous
    ],
    'on_counter_failure' => 'estimate',   // 'estimate' | 'reject'
    'min_useful_output_tokens' => 256,
],
```

Margins are tuned from real data: every settled `usage_event` stores
`reserved_input_tokens` next to the billed input tokens, so the admin
dashboard can show the observed counter deviation per provider/model.

### 5.3 Counter failure policy

When the provider counter fails (timeout, rate limit on the count endpoint,
unsupported model):

- `estimate` (default): fall back to `EstimatedInputTokenCounter` with its
  large margin; the reservation records `input_count_method = estimated`.
- `reject`: refuse the request with a retryable error. For institutions that
  prefer refusing a request over any risk of overshoot.

### 5.4 Reservation amount

```
input_tokens_reserved = ceil(counted_input_tokens × (1 + margin))
input_cost            = input_tokens_reserved × input_price           (cache discounts ignored → worst case)
max_output_cost       = max_output_tokens × output_price              (reasoning tokens count as output)
reservation           = input_cost + max_output_cost
```

- **Output** is bounded exactly: `max_output_tokens` is always sent to the
  provider and the provider cannot exceed it (OpenAI `max_output_tokens` /
  `max_completion_tokens` include reasoning; Anthropic `max_tokens` includes
  thinking; Gemini `maxOutputTokens` likewise — verified per adapter in
  contract tests).
- **Tiered pricing** (higher price above N input tokens): the tier is chosen
  from the counted input, using the higher tier if the margin crosses the
  threshold.

### 5.5 Output capping

If the full reservation does not fit into `available`, the engine **lowers
`max_output_tokens`** to what the remaining budget can pay for:

```
if input_cost > available: reject BudgetExhausted
max_affordable_output = floor((available − input_cost) / output_price_per_token)
max_output_tokens     = min(alias_cap, model_cap, max_affordable_output)
if max_output_tokens < min_useful_output_tokens: reject BudgetExhausted
```

A user with $0.03 left can still ask a short question instead of being blocked
by a worst-case reservation for an 8k-token answer. When capping happened,
the UI shows "the answer may be shortened because of your remaining budget".

### 5.6 Enforcement guarantee

With a provider counter, the reservation covers the maximum cost the provider
can bill for the request (counted input + margin, capped output). Therefore:

> `spent + reserved ≤ limit` holds for all admitted requests, provided the
> provider bills no more input than it counted (+ margin) and respects
> `max_output_tokens`.

If a provider nevertheless bills more (counter deviation beyond the margin, or
fallback estimation), the actual cost is still recorded in full — accounting
never lies — and the event is flagged as an **overshoot** (`total_cost >
reservation`). Overshoots are:

- visible in the admin dashboard and logged as warnings with the provider and
  model, so the margin can be increased;
- self-limiting: the user's `available` becomes ≤ 0 and further requests are
  rejected until the next period;
- avoidable entirely by setting `on_counter_failure = reject`.

## 6. Reservation algorithm

```php
function reserve(User $user, AiModel $model, ChatRequest $draft): Reservation
{
    // 1. Measure input OUTSIDE any transaction (network call, §5.1).
    $inputCount = $counters->for($model)->count($draft);   // provider endpoint → fallback per §5.3

    $period = currentPeriod($user, now());               // §4, auto-committed

    // 2. Short locked transaction.
    return DB::transaction(function () use ($user, $model, $draft, $period, $inputCount) {
        DB::statement('SET SESSION innodb_lock_wait_timeout = 5');

        // Row lock on a single existing index record (no gap lock).
        $period = BudgetPeriod::whereKey($period->id)->lockForUpdate()->first();

        // Concurrent stream limit, evaluated under the same lock.
        $active = BudgetReservation::where('budget_period_id', $period->id)
            ->where('status', 'active')->count();
        if ($active >= $user->group->max_concurrent_streams) {
            throw new TooManyConcurrentRequests();
        }

        $available = $period->limit->minus($period->spent)->minus($period->reserved);
        [$maxOutput, $amount] = ReservationSizer::fit($inputCount, $model, $draft, $available); // §5.4–5.5
        // throws BudgetExhausted when input alone or MIN_USEFUL_OUTPUT does not fit

        $period->reserved = $period->reserved->plus($amount);
        $period->save();

        return BudgetReservation::create([
            'budget_period_id'       => $period->id,
            'user_id'                => $user->id,
            'ai_model_id'            => $model->id,
            'amount_usd'             => $amount,
            'input_tokens'           => $inputCount->reservedTokens(),
            'input_count_method'     => $inputCount->method,
            'input_safety_margin'    => $inputCount->marginRatio,
            'max_output_tokens'      => $maxOutput,
            'status'                 => 'active',
            'expires_at'             => now()->addSeconds(config('ada.stream.max_seconds') + 120),
        ]);
    }, attempts: 3);                                       // retries on deadlock (1213)
}
```

## 7. Concurrency control (MySQL / InnoDB)

### 7.1 Why row locking works

- All money-changing operations for a user go through `SELECT … FOR UPDATE`
  on that user's `budget_periods` row. Two concurrent requests for the same
  user serialize on that row; the second one reads the already-increased
  `reserved_usd` and fails if the budget is gone.
- InnoDB locking reads always read the **latest committed** row version, even
  under the default `REPEATABLE READ` isolation, so no stale snapshot is used.
- The lock is taken via the primary key of an **existing** row, so InnoDB
  locks only that index record — no gap locks.
- Different users never contend. Transactions are milliseconds long; **no lock
  is held while streaming**.

### 7.2 Avoiding gap-lock deadlocks

`SELECT … FOR UPDATE` on a *non-existent* row takes a gap lock; two
concurrent "select-for-update-then-insert" transactions for a new period then
deadlock. Therefore the period row is created by an auto-committed no-op upsert
**before** the locking transaction (§4).

### 7.3 Lock ordering

Every transaction that touches both tables locks in the same order:
`budget_periods` → `budget_reservations`. Deadlocks that still occur (error
1213) are retried by `DB::transaction(..., attempts: 3)`; lock waits are
bounded by `innodb_lock_wait_timeout = 5` for these sessions.

### 7.4 Why not Redis locks

A Redis lock would add a second source of truth and fail open or closed when
Redis restarts. The database row is already the thing we must update
atomically, so it is the natural lock. Redis is used only for rate limiting.

## 8. Settlement

Called exactly once per reservation, from the generation's `finally` path or
from the cleanup job.

```php
function settle(string $reservationId, TokenUsage $usage, SettleContext $ctx): UsageEvent
{
    return DB::transaction(function () use ($reservationId, $usage, $ctx) {
        $reservation = BudgetReservation::findOrFail($reservationId);   // plain read for period id

        $period = BudgetPeriod::whereKey($reservation->budget_period_id)
            ->lockForUpdate()->first();                                  // lock 1: period
        $reservation = BudgetReservation::whereKey($reservationId)
            ->lockForUpdate()->first();                                  // lock 2: reservation

        if ($reservation->status === 'settled') {                        // idempotent
            return UsageEvent::where('reservation_id', $reservationId)->first();
        }

        $pricing = PricingSnapshot::fromModel($reservation->aiModel);
        $cost    = CostCalculator::calculate($usage, $pricing);          // Usage domain

        if ($reservation->status === 'active') {
            $period->reserved = $period->reserved->minus($reservation->amount);
        }
        // 'expired' → reserved was already released by cleanup; still charge (late settlement)

        $period->spent = $period->spent->plus($cost->total);
        $overshoot = $cost->total->isGreaterThan($reservation->amount);   // §5.6 → flag + warn
        $period->save();

        $reservation->update([
            'status' => 'settled',
            'settled_amount_usd' => $cost->total,
            'settled_at' => now(),
        ]);

        return UsageEvent::create([
            'type' => 'charge',
            'reservation_id' => $reservationId,
            'user_id' => $reservation->user_id,
            'group_id' => $ctx->groupId,
            'budget_period_id' => $period->id,
            'conversation_id' => $ctx->conversationId,
            'message_id' => $ctx->messageId,
            /* tokens, price snapshots, costs, is_estimated, provider_request_id, status */
        ]);
    }, attempts: 3);
}
```

Notes:

- The charge goes to the **reservation's** period, even if the stream crossed
  midnight into a new month.
- `UNIQUE(reservation_id)` on `usage_events` makes double settlement
  impossible even if two code paths race.

## 9. Release

Used when the provider consumed nothing billable (e.g. request rejected before
generation, authentication error, connection refused).

```
BEGIN
  lock period; lock reservation
  if reservation.status != 'active': return            # idempotent
  period.reserved -= reservation.amount
  reservation.status = 'released', status_reason = <reason>
COMMIT
```

## 10. Failure handling

| Situation | Detection | Budget action |
|---|---|---|
| Provider rejects request (4xx/5xx before any output) | Adapter exception before first token | **Release** |
| Provider error mid-stream | Adapter exception after output | **Settle** with reported usage if any, otherwise estimate from generated text (`is_estimated = true`) |
| Provider timeout | HTTP timeout / max stream duration reached | As above (release if nothing generated, otherwise settle) |
| User clicks Stop / browser closed / network drop | `connection_aborted()` after flush, or cancel flag | Stop provider stream, **settle** with usage so far |
| PHP exception in Ada | `try/finally` in `ChatGenerationService` | Settle or release in `finally` |
| PHP process killed (OOM, deploy, FPM restart) | Nothing runs | **Cleanup job** (§11) |
| Queue failure | N/A for chat (streaming does not use the queue) | — |
| Token-count endpoint fails | Counter timeout / error | No reservation yet; fall back to estimator or reject (§5.3) |

Estimating output tokens after an abort: providers usually send final usage
only at the end of a stream (OpenAI with `stream_options.include_usage`,
Anthropic `message_delta`, Gemini `usageMetadata`). When the stream is cut
before that, input tokens are taken from the reservation's counted input and
output tokens are counted from the generated text (provider counter where
possible, otherwise a conservative bytes-per-token ratio). The provider still bills us for tokens it generated, so charging an
estimate is more accurate than charging nothing.

## 11. Stale reservation cleanup

Scheduler: `ada:budget:expire-reservations` every minute.

```
for each reservation where status = 'active' and expires_at < now() (batched):
    message = assistant message linked to this reservation
    if message exists and has partial content:
        settle(reservation, estimateUsage(message), is_estimated = true)
        message.status = 'failed', error_code = 'generation_interrupted'
    else:
        lock period; lock reservation; if still active:
            period.reserved -= amount
            reservation.status = 'expired'
```

`expires_at = created_at + max_stream_seconds + margin`, so a healthy stream
can never be expired while running (the stream itself is hard-stopped at
`max_stream_seconds`). A late settlement for an already expired reservation is
accepted (§8) and charges `spent` without touching `reserved`.

## 12. Reservation state machine

```
             ┌──────────► settled   (normal completion, abort with usage, late settle)
  active ────┼──────────► released  (nothing billable consumed)
             └──────────► expired ──► settled (late settlement only)
```

The brief's `pending` state is not needed: a reservation becomes `active`
atomically in the same transaction that increases `reserved_usd`.

## 13. Cost calculation (Usage domain)

```
input_cost  = input_tokens        × input_price        / 1_000_000
            + cached_input_tokens × cached_input_price / 1_000_000
            + cache_write_tokens  × cache_write_price  / 1_000_000
output_cost = (output_tokens + reasoning_tokens) × output_price / 1_000_000
total       = input_cost + output_cost + other_cost
```

Rounded **up** to 10 decimals. Token normalization (e.g. OpenAI reports
`cached_tokens` as a *subset* of `prompt_tokens`, while Anthropic reports
cache reads separately) happens in the provider adapters, so `TokenUsage`
fields are always disjoint. See [provider-architecture.md](provider-architecture.md).

## 14. Pricing snapshots

Each `usage_event` stores the per-million prices used and the computed costs.
Changing `ai_models` prices affects only future requests. Price changes are
audit-logged. Rationale and comparison with a versioned price table:
[database-design.md §6](database-design.md#6-pricing-snapshots-vs-versioned-price-table).

## 15. Reconciliation

`ada:budget:reconcile` (daily) compares, per period:

```
budget_periods.spent_usd  ==  SUM(usage_events.total_cost_usd WHERE budget_period_id = …)
budget_periods.reserved_usd == SUM(budget_reservations.amount_usd WHERE status = 'active')
```

Differences are reported (log + admin dashboard warning); they are **not**
silently corrected. A `--fix-reserved` option may recompute `reserved_usd`
(safe, derived from active reservations) with an audit log entry.

## 16. Manual adjustments

Admins (super admin only) can credit or debit a user's current period with an
`adjustment` usage event (`reason` required). The adjustment and the
`spent_usd` update happen in one locked transaction and are audit-logged.

## 17. Test plan

| Test | Type |
|---|---|
| Reservation sizing: counted input + margin, max output cost, output capping, minimum useful output, tiered pricing | Unit |
| Counter selection per provider; fallback to estimator on timeout/429; `reject` policy refuses request | Unit / Feature (HTTP fakes) |
| Counter adapters send the exact request payload (system prompt, history, parameters) that the chat request uses | Contract (recorded fixtures) |
| Counter contract tests against live APIs (opt-in, CI secret): counted vs billed input within margin | Integration (manual/nightly) |
| Overshoot: billed cost > reservation is recorded in full, flagged, and blocks the next request | Feature |
| Cost calculator incl. cached/cache-write/reasoning tokens and rounding | Unit |
| Reserve → settle updates `reserved`/`spent` correctly | Feature (MySQL) |
| Budget exhaustion rejects with `BudgetExhausted` | Feature |
| **Concurrency**: N parallel processes reserving against one user; assert `spent + reserved ≤ limit` and no negative values | Feature, Symfony Process workers against MySQL |
| Monthly rollover across timezone boundary; stream crossing midnight charges old period | Feature (time travel) |
| Provider error before output → release; mid-stream → settle | Feature (fake provider) |
| Stream disconnect → settle with estimated usage | Feature |
| Stale reservation cleanup (with and without partial content), late settlement after expiry | Feature |
| Idempotent settlement (double call, parallel call) | Feature |
| Price change does not alter historical events | Feature |
| Policy change "apply to current period" | Feature |
| Reconciliation detects injected mismatch | Feature |
| `usage_events` update/delete rejected | Feature |
