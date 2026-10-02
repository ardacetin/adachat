<?php

namespace App\Domain\AI\Data;

use InvalidArgumentException;

/**
 * A provider-neutral generation request. The same object is used for token
 * counting and for generation, so what is counted is what is sent.
 */
final readonly class ChatRequest
{
    /**
     * @param  list<ChatMessage>  $messages
     */
    public function __construct(
        public string $model,
        public array $messages,
        public int $maxOutputTokens,
        public ?string $systemPrompt = null,
        public ?float $temperature = null,
        // Long fixed instructions (assistant documents): providers with
        // explicit prompt caching mark the system prompt as cacheable.
        public bool $cacheSystemPrompt = false,
        // The provider's built-in web search, with at most this many
        // searches; null leaves it off.
        public ?int $webSearchMaxUses = null,
    ) {
        if ($messages === []) {
            throw new InvalidArgumentException('A chat request needs at least one message.');
        }

        if ($maxOutputTokens < 1) {
            throw new InvalidArgumentException('maxOutputTokens must be positive.');
        }

        if ($webSearchMaxUses !== null && $webSearchMaxUses < 1) {
            throw new InvalidArgumentException('webSearchMaxUses must be positive.');
        }
    }

    public function withMaxOutputTokens(int $maxOutputTokens): self
    {
        return new self($this->model, $this->messages, $maxOutputTokens, $this->systemPrompt, $this->temperature, $this->cacheSystemPrompt, $this->webSearchMaxUses);
    }

    public function withWebSearch(?int $maxUses): self
    {
        return new self($this->model, $this->messages, $this->maxOutputTokens, $this->systemPrompt, $this->temperature, $this->cacheSystemPrompt, $maxUses);
    }
}
