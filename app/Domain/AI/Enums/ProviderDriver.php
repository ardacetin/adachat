<?php

namespace App\Domain\AI\Enums;

enum ProviderDriver: string
{
    case OpenAI = 'openai';
    case Anthropic = 'anthropic';
    case Gemini = 'gemini';

    public function label(): string
    {
        return match ($this) {
            self::OpenAI => 'OpenAI',
            self::Anthropic => 'Anthropic',
            self::Gemini => 'Google Gemini',
        };
    }

    public function defaultBaseUrl(): string
    {
        return match ($this) {
            self::OpenAI => 'https://api.openai.com/v1',
            self::Anthropic => 'https://api.anthropic.com/v1',
            self::Gemini => 'https://generativelanguage.googleapis.com/v1beta',
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
        };
    }
}
