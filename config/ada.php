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
        // Comma-separated list of e-mail domains allowed to sign in.
        'allowed_domains' => array_values(array_filter(array_map(
            static fn (string $domain): string => strtolower(trim($domain)),
            explode(',', (string) env('AUTH_ALLOWED_DOMAINS', '')),
        ))),

        // Create users automatically on their first successful sign-in. When
        // false, only users pre-created by an admin (or ada:user:promote) can
        // sign in.
        'auto_provision' => (bool) env('AUTH_AUTO_PROVISION', true),

        // SAML 2.0 sign-in through the institution's identity provider — in
        // V1 a custom SAML app in the Google Workspace admin console. Values
        // come from the IdP metadata ("Download metadata" in Google Admin).
        'saml' => [
            // IdP entity ID, e.g. https://accounts.google.com/o/saml2?idpid=C0123abcd
            'idp_entity_id' => env('SAML_IDP_ENTITY_ID'),
            // IdP SSO URL, e.g. https://accounts.google.com/o/saml2/idp?idpid=C0123abcd
            'idp_sso_url' => env('SAML_IDP_SSO_URL'),
            // IdP signing certificate: PEM text (with or without the BEGIN/END
            // lines) in SAML_IDP_CERT, or a file path in SAML_IDP_CERT_PATH.
            'idp_x509_cert' => env('SAML_IDP_CERT'),
            'idp_x509_cert_path' => env('SAML_IDP_CERT_PATH'),
            // Attribute names mapped in the SAML app ("Attribute mapping").
            'attributes' => [
                'first_name' => env('SAML_ATTRIBUTE_FIRST_NAME', 'first_name'),
                'last_name' => env('SAML_ATTRIBUTE_LAST_NAME', 'last_name'),
                // Optional: an attribute with an identifier that is never
                // given to another person (e.g. an employee ID). Without it
                // the e-mail address identifies the account.
                'subject' => env('SAML_ATTRIBUTE_SUBJECT'),
            ],
            // Label of the sign-in button.
            'label' => env('SAML_LOGIN_LABEL', 'Google'),
        ],

        // OpenID Connect sign-in, e.g. Microsoft Entra ID, Keycloak or Okta
        // (docs/authentication.md). Can be used next to SAML.
        'oidc' => [
            'enabled' => (bool) env('OIDC_ENABLED', false),
            // Label of the sign-in button; default "Microsoft" with the entra preset.
            'label' => env('OIDC_LABEL'),
            // Entra ID: https://login.microsoftonline.com/<tenant ID>/v2.0
            'issuer' => env('OIDC_ISSUER'),
            'client_id' => env('OIDC_CLIENT_ID'),
            'client_secret' => env('OIDC_CLIENT_SECRET'),
            // entra (single tenant, see docs) or generic (email_verified required).
            'preset' => env('OIDC_PRESET', 'generic'),
            'scopes' => env('OIDC_SCOPES', 'openid email profile'),
        ],

        // Password-less development login. Only ever active in the local and
        // testing environments, regardless of this flag.
        'dev_login' => (bool) env('ADA_DEV_LOGIN', false),

        // Sessions end this many minutes after sign-in, even when active
        // (SESSION_LIFETIME is the idle timeout). Default: 7 days.
        'max_session_minutes' => (int) env('SESSION_MAX_LIFETIME', 10080),
    ],

    'http' => [
        // Reverse proxies (load balancer, TLS terminator, the nginx of the
        // Docker image) whose X-Forwarded-* headers are believed: a
        // comma-separated list of addresses or CIDR ranges, or * for any
        // (only when the application cannot be reached except through the
        // proxy). Empty: no proxy is trusted, which is right when nginx
        // passes requests to PHP-FPM directly on the same server.
        'trusted_proxies' => env('TRUSTED_PROXIES'),
    ],

    // The model price catalog the "easy" model form uses. An institution can
    // point to its own copy, e.g. to add models or negotiated prices.
    'catalog' => [
        'path' => env('ADA_MODEL_CATALOG', resource_path('catalog/models.json')),
    ],

    'assistants' => [
        // Text an assistant's documents may add to every request, in tokens
        // (estimate). Documents are sent again with each message.
        'max_document_tokens' => (int) env('ADA_ASSISTANT_MAX_DOCUMENT_TOKENS', 50000),
    ],

    'web_search' => [
        // Upper bound for an alias's searches per message.
        'max_uses_limit' => 5,
        // Search results become input tokens; the reservation sets this
        // many aside per allowed search.
        'reserve_tokens_per_search' => (int) env('ADA_WEB_SEARCH_RESERVE_TOKENS', 4000),
    ],

    'chat' => [
        // Longest message a user can send, in characters.
        'max_message_chars' => (int) env('ADA_MAX_MESSAGE_CHARS', 32000),
    ],

    'sharing' => [
        // Every link and every copy stores the whole conversation again.
        'max_links_per_conversation' => (int) env('ADA_SHARE_MAX_LINKS', 10),
        // Links created plus copies made by one user per day.
        'daily_limit' => (int) env('ADA_SHARE_DAILY_LIMIT', 50),
    ],

    'attachments' => [
        // Files per message and unsent ("pending") files per user.
        'max_per_message' => 5,
        'max_pending' => 20,

        // Upload limits in MB. 5 MB is the strictest provider limit for images
        // (Anthropic); the web server and PHP limits must allow the largest.
        'max_image_mb' => (float) env('ADA_ATTACHMENT_MAX_IMAGE_MB', 5),
        'max_text_mb' => (float) env('ADA_ATTACHMENT_MAX_TEXT_MB', 1),
        'max_document_mb' => (float) env('ADA_ATTACHMENT_MAX_DOCUMENT_MB', 10),

        // Text taken from one PDF or Office file (longer text is cut, with a
        // note to the model), and rows read per spreadsheet sheet.
        'max_text_chars' => 200000,
        'max_sheet_rows' => 2000,

        // Input-token estimate per PDF page when the PDF is sent natively
        // (providers also look at each page as an image).
        'pdf_page_tokens' => 1500,

        // Images larger than this on either side are refused (Anthropic: 8000).
        'max_image_side' => 8000,

        // Input-token estimate per image, for trimming and for the estimate
        // path; the provider count endpoints count images exactly.
        'image_tokens' => 1600,

        // Attachment bytes sent in one request (Gemini allows 20 MB inline).
        // Older images beyond this are replaced by a short note.
        'max_request_mb' => 15,

        // Unsent attachments are deleted after this many hours.
        'pending_hours' => 24,
    ],

    'providers' => [
        // Maximum duration of one generation request (streaming), seconds.
        'timeout' => (int) env('ADA_PROVIDER_TIMEOUT', 300),

        // A turn the provider pauses during its own tools (Anthropic's
        // pause_turn during web search) is continued at most this many
        // times, within the same time limit and output cap.
        'max_continuations' => 2,

        // Token-count endpoints must answer quickly; they run before every request.
        'counter_timeout' => (int) env('ADA_COUNTER_TIMEOUT', 3),

        // Fallback API keys when no credential is stored in the admin panel.
        'env_keys' => [
            'openai' => env('OPENAI_API_KEY'),
            'anthropic' => env('ANTHROPIC_API_KEY'),
            'gemini' => env('GEMINI_API_KEY'),
            'openai_compatible' => env('OPENAI_COMPATIBLE_API_KEY'),
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
            // No count endpoint: the conservative estimate plus this margin.
            'openai_compatible' => 0.25,
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
