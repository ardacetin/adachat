<x-mail::message>
# {{ __('mail.invitation.heading', ['institution' => $institution]) }}

{{ __('mail.invitation.body', ['name' => $invitedBy, 'institution' => $institution]) }}

{{ __('mail.invitation.sign_in', ['email' => $email]) }}

<x-mail::button :url="$url">
{{ __('mail.invitation.button') }}
</x-mail::button>

{{ __('mail.footer') }}
</x-mail::message>
