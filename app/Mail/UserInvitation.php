<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An administrator added the recipient's address. There is no token in the
 * mail: the person signs in with the institution's identity provider and the
 * account is linked by the address. The wording comes from ContentTexts
 * (Admin → Texts), placeholders already filled in.
 */
class UserInvitation extends Mailable
{
    /**
     * @param  array<string, string>  $texts  subject, heading, body, sign_in, button
     */
    public function __construct(
        public readonly string $institution,
        public readonly string $invitedBy,
        public readonly string $email,
        public readonly array $texts,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->texts['subject']);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.user-invitation', with: [
            'url' => route('login'),
        ]);
    }
}
