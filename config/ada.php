<?php

/*
|--------------------------------------------------------------------------
| Ada Chat configuration
|--------------------------------------------------------------------------
|
| Operational configuration for Ada. Institution-specific values here are
| only *initial* values: once the database settings exist (M3), the admin
| panel is the source of truth. Nothing institution-specific may be
| hard-coded in application code.
|
*/

return [

    'locales' => [
        // Locales the UI is translated into. Every locale listed here must
        // have complete translation files (enforced by tests).
        'available' => ['en', 'tr'],

        // Initial institution default; users can override it in settings.
        'default' => env('DEFAULT_LOCALE', 'en'),

        'fallback' => env('FALLBACK_LOCALE', 'en'),
    ],

    'institution' => [
        'name' => env('INSTITUTION_NAME'),
        'short_name' => env('INSTITUTION_SHORT_NAME'),
        'domain' => env('INSTITUTION_DOMAIN'),
        'timezone' => env('INSTITUTION_TIMEZONE', 'UTC'),
    ],

    'auth' => [
        // Comma-separated list of Google Workspace domains allowed to sign in.
        'allowed_domains' => array_values(array_filter(array_map(
            static fn (string $domain): string => strtolower(trim($domain)),
            explode(',', (string) env('AUTH_ALLOWED_DOMAINS', '')),
        ))),

        // Create users automatically on their first successful sign-in. When
        // false, only users pre-created by an admin (or ada:user:promote) can
        // sign in.
        'auto_provision' => (bool) env('AUTH_AUTO_PROVISION', true),

        // Password-less development login. Only ever active in the local and
        // testing environments, regardless of this flag.
        'dev_login' => (bool) env('ADA_DEV_LOGIN', false),
    ],

    'chat' => [
        // Longest message a user can send, in characters.
        'max_message_chars' => (int) env('ADA_MAX_MESSAGE_CHARS', 32000),
    ],

    'providers' => [
        // Maximum duration of one generation request (streaming), seconds.
        'timeout' => (int) env('ADA_PROVIDER_TIMEOUT', 300),

        // Token-count endpoints must answer quickly; they run before every request.
        'counter_timeout' => (int) env('ADA_COUNTER_TIMEOUT', 3),

        // Fallback API keys when no credential is stored in the admin panel.
        'env_keys' => [
            'openai' => env('OPENAI_API_KEY'),
            'anthropic' => env('ANTHROPIC_API_KEY'),
            'gemini' => env('GEMINI_API_KEY'),
        ],
    ],

    'budget' => [
        // Safety margin added to counted input tokens, per counting source.
        // Anthropic documents count_tokens as an estimate; the heuristic
        // fallback gets a deliberately large margin.
        'input_count_margins' => [
            'openai' => 0.0,
            'anthropic' => 0.05,
            'gemini' => 0.0,
            'estimated' => 0.5,
        ],

        // When a token-count endpoint fails: 'estimate' (conservative
        // heuristic) or 'reject' (refuse the request, retryable).
        'on_counter_failure' => env('ADA_ON_COUNTER_FAILURE', 'estimate'),

        // Monthly limit of the Default policy created on installation (USD,
        // a decimal string: money is never a float). Changed later in the
        // admin panel.
        'default_monthly_limit_usd' => (string) env('ADA_DEFAULT_MONTHLY_LIMIT_USD', '10'),

        // Requests are refused rather than capped below this many output tokens.
        'min_useful_output_tokens' => (int) env('ADA_MIN_USEFUL_OUTPUT_TOKENS', 256),

        // Seconds added to the provider timeout before an unfinished
        // reservation is considered abandoned and expired.
        'reservation_grace_seconds' => 120,

        // MySQL lock wait for budget transactions, seconds.
        'lock_wait_timeout' => 5,
    ],

];
