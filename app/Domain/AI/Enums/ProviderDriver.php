<?php

namespace App\Domain\AI\Enums;

enum ProviderDriver: string
{
    case OpenAI = 'openai';
    case Anthropic = 'anthropic';
    case Gemini = 'gemini';
    /** Any endpoint speaking OpenAI Chat Completions: OpenRouter, Ollama, vLLM, Groq, LM Studio… */
    case OpenAICompatible = 'openai_compatible';

    public function label(): string
    {
        return match ($this) {
            self::OpenAI => 'OpenAI',
            self::Anthropic => 'Anthropic',
            self::Gemini => 'Google Gemini',
            self::OpenAICompatible => 'OpenAI-compatible (Chat Completions)',
        };
    }

    /**
     * Empty for the OpenAI-compatible driver: its address must be entered.
     */
    public function defaultBaseUrl(): string
    {
        return match ($this) {
            self::OpenAI => 'https://api.openai.com/v1',
            self::Anthropic => 'https://api.anthropic.com/v1',
            self::Gemini => 'https://generativelanguage.googleapis.com/v1beta',
            self::OpenAICompatible => '',
        };
    }

    /**
     * Environment variable used when no credential is stored in the database.
     */
    public function envKey(): string
    {
        return match ($this) {
            self::OpenAI => 'OPENAI_API_KEY',
            self::Anthropic => 'ANTHROPIC_API_KEY',
            self::Gemini => 'GEMINI_API_KEY',
            self::OpenAICompatible => 'OPENAI_COMPATIBLE_API_KEY',
        };
    }

    /**
     * Local servers (Ollama, vLLM) usually run without an API key.
     */
    public function requiresApiKey(): bool
    {
        return $this !== self::OpenAICompatible;
    }

    /**
     * Whether PDFs can be sent as files. Chat Completions servers have no
     * common format for it, so they get the PDF's text instead.
     */
    public function sendsDocuments(): bool
    {
        return $this !== self::OpenAICompatible;
    }

    /**
     * Whether the provider has an endpoint that counts input tokens. Without
     * one, input is estimated with the driver's (larger) safety margin.
     */
    public function countsTokens(): bool
    {
        return $this !== self::OpenAICompatible;
    }
}
