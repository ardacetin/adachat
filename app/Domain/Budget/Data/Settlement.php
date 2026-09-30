<?php

namespace App\Domain\Budget\Data;

use App\Domain\AI\Data\TokenUsage;
use App\Domain\Usage\Enums\UsageEventStatus;

/**
 * What a finished (or interrupted) request actually consumed.
 */
final readonly class Settlement
{
    public function __construct(
        public TokenUsage $usage,
        public UsageEventStatus $status = UsageEventStatus::Completed,
        /** Usage estimated because the stream ended without provider usage. */
        public bool $isEstimated = false,
        /** Why the request ended early, e.g. client_abort, provider_error. */
        public ?string $reason = null,
        public ?int $modelAliasId = null,
        public ?string $conversationId = null,
        public ?string $messageId = null,
        public ?string $providerRequestId = null,
    ) {}
}
