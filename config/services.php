<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    // The live mail transport. RESEND_API_KEY is deliberately its own variable
    // rather than the AWS_* pair further down: those are the object-storage
    // bucket's, Laravel Cloud configures that disk at runtime from its own
    // injected config, and one credential rotation should not be able to take out
    // either photos or password resets with no obvious connection between the
    // two. Two services, two sets of keys.
    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    // Kept after the move to Resend so MAIL_MAILER=ses remains a one-variable
    // rollback: the identity and its DKIM records are still published. It is a
    // partial rollback — SES never left its sandbox, so it reaches verified
    // identities and nobody else.
    //
    // The rollback needs SES_KEY and SES_SECRET to still be set wherever it is
    // claimed. MailManager::addSesCredentials only injects them when BOTH are
    // non-empty; otherwise the SES client falls back to the ambient AWS
    // credential chain and picks up the AWS_* pair below — the photo bucket's,
    // which has no ses:SendEmail — and fails as an AccessDenied in a worker
    // rather than at boot.
    'ses' => [
        'key' => env('SES_KEY'),
        'secret' => env('SES_SECRET'),
        'region' => env('SES_REGION', 'ap-southeast-2'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
