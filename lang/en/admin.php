<?php

return [

    'saved' => 'Settings saved.',
    'policy_in_use' => 'This budget policy is used by a group and cannot be deleted.',
    'group_not_deletable' => 'The default group and groups with members cannot be deleted.',
    'last_super_admin' => 'The last active super administrator cannot be demoted or disabled.',
    'credit_exceeds_spent' => 'A credit cannot be larger than what was spent this month.',
    'primary_color_contrast' => 'This colour is too light: it needs a contrast of at least 3:1 against white so buttons stay readable.',
    'alias_max_tokens' => 'The output limit cannot exceed the model maximum (:max tokens).',

    'provider_check' => [
        'ok' => 'Connection successful: the API key works.',
        'failed' => 'Connection failed: :reason',
    ],

    // App\Domain\AI\Exceptions\ProviderException::code()
    'provider_errors' => [
        'provider_unavailable' => 'the key was rejected or the provider is unavailable.',
        'rate_limited' => 'the provider is rate limiting requests.',
        'overloaded' => 'the provider is overloaded.',
        'context_too_long' => 'the request is too long for this model.',
        'content_filtered' => 'the provider filtered the content.',
        'timeout' => 'the provider did not answer in time.',
        'invalid_request' => 'the provider rejected the request.',
        'token_count_unavailable' => 'token counting is unavailable.',
    ],

];
