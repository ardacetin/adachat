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

];
