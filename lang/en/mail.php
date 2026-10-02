<?php

return [
    'footer' => 'Ada Chat — this message was sent automatically.',

    'user_budget' => [
        'subject_80' => ':institution: you have used 80 % of your monthly AI budget',
        'subject_100' => ':institution: your monthly AI budget is used up',
        'heading_80' => '80 % of your monthly budget is used',
        'heading_100' => 'Your monthly budget is used up',
        'body_80' => 'You have used 80 % of your monthly budget for Ada Chat. When it is used up, new messages are refused until it renews on :date.',
        'body_100' => 'You have used your whole monthly budget for Ada Chat. New messages are refused until it renews on :date. Ask your administrator if you need more.',
        'amounts' => 'Used so far: :spent of :limit.',
        'button' => 'See your usage',
        'settings' => 'You can turn these e-mails off in Settings → Notifications.',
    ],
    'cap_alert' => [
        'subject_80' => ':institution: 80 % of the monthly AI budget is used',
        'subject_100' => ':institution: the monthly AI budget is used up',
        'heading_80' => '80 % of the monthly budget is used',
        'heading_100' => 'The monthly budget is used up',
        'body_80' => ':institution has used :used of its :cap monthly cap for AI usage. At :cap, nobody can start new requests until the month renews on :date.',
        'body_100' => ':institution has reached its :cap monthly cap for AI usage (:used). New requests are refused until the month renews on :date.',
        'what_now' => 'You can raise the cap in Admin → Institution, or check who uses what on the overview and the reports.',
        'button' => 'Open the overview',
    ],

    'test' => [
        'subject' => ':institution: test message from Ada Chat',
        'heading' => 'E-mail works',
        'body' => 'This test message from Ada Chat (:institution) arrived, so the mail settings are correct. Cap alerts and monthly reports will be sent to this address.',
    ],

    'monthly' => [
        'subject' => ':institution: AI usage report for :month',
        'heading' => 'Usage report for :month',
        'intro' => 'Here is how :institution used Ada Chat in :month. The figures are costs and counts only; message content is never included.',
        'spend' => 'Spending',
        'requests' => 'Requests',
        'users' => 'Active users',
        'adjustments' => 'Manual adjustments',
        'users_at_limit' => 'Users who used up their budget',
        'cap' => 'Institution cap used',
        'overshoots' => ':count requests cost more than their reservation; see the reports for details.',
        'top_groups' => 'Top groups',
        'top_models' => 'Top models',
        'name' => 'Name',
        'attachments' => 'Attached: spending per user and per day as CSV (opens in Excel and other spreadsheets).',
        'button' => 'Open the reports',
    ],
];
