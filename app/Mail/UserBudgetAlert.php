<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A user reached 80 % or 100 % of their monthly budget.
 */
class UserBudgetAlert extends Mailable
{
    /**
     * @param  array{spent: string, limit: string}|null  $amounts  null when the institution shows percentages only
     */
    public function __construct(
        public readonly string $institution,
        public readonly int $threshold,
        public readonly ?array $amounts,
        public readonly string $resetsOn,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.user_budget.subject_'.$this->threshold, ['institution' => $this->institution]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.user-budget-alert', with: [
            'url' => route('usage'),
        ]);
    }
}
