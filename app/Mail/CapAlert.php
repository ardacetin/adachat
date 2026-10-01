<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The institution reached 80 % or 100 % of its monthly spending cap.
 */
class CapAlert extends Mailable
{
    public function __construct(
        public readonly string $institution,
        public readonly int $threshold,
        public readonly string $usedUsd,
        public readonly string $capUsd,
        public readonly string $resetsOn,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.cap_alert.subject_'.$this->threshold, ['institution' => $this->institution]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.cap-alert', with: [
            'url' => route('admin.index'),
        ]);
    }
}
