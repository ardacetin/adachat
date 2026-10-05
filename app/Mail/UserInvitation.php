<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An administrator added the recipient's address. There is no token in the
 * mail: the person signs in with the institution's identity provider and the
 * account is linked by the address.
 */
class UserInvitation extends Mailable
{
    public function __construct(
        public readonly string $institution,
        public readonly string $invitedBy,
        public readonly string $email,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.invitation.subject', ['institution' => $this->institution]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.user-invitation', with: [
            'url' => route('login'),
        ]);
    }
}
