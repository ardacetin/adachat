<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent from Admin → Institution to check the mail configuration.
 */
class TestMail extends Mailable
{
    public function __construct(public readonly string $institution) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.test.subject', ['institution' => $this->institution]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.test');
    }
}
