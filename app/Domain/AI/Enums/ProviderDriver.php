<?php

namespace App\Domain\AI\Enums;

enum ProviderDriver: string
{
    case OpenAI = 'openai';
    case Anthropic = 'anthropic';
    case Gemini = 'gemini';
    /** Any endpoint speaking OpenAI Chat Completions: OpenRouter, Ollama, vLLM, Groq, LM Studio… */
    case OpenAICompatible = 'openai_compatible';
    /** Azure OpenAI v1 API (Responses) of the institution's own Azure resource. */
    case AzureOpenAI = 'azure_openai';

    public function label(): string
    {
        return match ($this) {
            self::OpenAI => 'OpenAI',
            self::Anthropic => 'Anthropic',
            self::Gemini => 'Google Gemini',
            self::OpenAICompatible => 'OpenAI-compatible (Chat Completions)',
            self::AzureOpenAI => 'Azure OpenAI',
        };
    }

    /**
     * Empty for the OpenAI-compatible and Azure drivers: their address
     * (the server, the Azure resource) must be entered.
     */
    public function defaultBaseUrl(): string
    {
        return match ($this) {
            self::OpenAI => 'https://api.openai.com/v1',
            self::Anthropic => 'https://api.anthropic.com/v1',
            self::Gemini => 'https://generativelanguage.googleapis.com/v1beta',
            self::OpenAICompatible, self::AzureOpenAI => '',
        };
    }

    public function needsBaseUrl(): bool
    {
        return $this->defaultBaseUrl() === '';
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
            self::AzureOpenAI => 'AZURE_OPENAI_API_KEY',
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
     * common format for it, so they get the PDF's text instead; so does
     * Azure OpenAI.
     */
    public function sendsDocuments(): bool
    {
        // Azure: file input depends on the deployed model and region.
        return ! in_array($this, [self::OpenAICompatible, self::AzureOpenAI], true);
    }

    /**
     * Whether the provider has an endpoint that counts input tokens. Without
     * one, input is estimated with the driver's (larger) safety margin.
     * Azure's v1 API does not document the Responses input-token count.
     */
    public function countsTokens(): bool
    {
        return ! in_array($this, [self::OpenAICompatible, self::AzureOpenAI], true);
    }

    /**
     * Whether Ada offers the provider's built-in web search. Chat Completions
     * servers have none; Azure's (Grounding with Bing) is a separate,
     * separately billed Foundry tool.
     */
    public function searchesWeb(): bool
    {
        return ! in_array($this, [self::OpenAICompatible, self::AzureOpenAI], true);
    }
}
