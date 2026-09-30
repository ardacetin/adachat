<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    */

    'failed' => 'These credentials do not match our records.',
    'throttle' => 'Too many sign-in attempts. Please try again in :seconds seconds.',

    // Sign-in rejection reasons (App\Domain\Identity\Exceptions\RejectionReason).
    'errors' => [
        'invalid_state' => 'Your sign-in session expired. Please try again.',
        'provider_error' => 'Sign-in with your identity provider failed. Please try again.',
        'email_not_verified' => 'Your e-mail address is not verified by your identity provider.',
        'domain_not_allowed' => 'This account is not allowed to sign in. Please use your institutional account.',
        'not_provisioned' => 'You do not have access yet. Please contact your administrator.',
        'account_disabled' => 'Your account has been disabled. Please contact your administrator.',
        'account_conflict' => 'Your account could not be linked. Please contact your administrator.',
    ],

];
