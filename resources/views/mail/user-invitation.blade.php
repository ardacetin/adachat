<x-mail::message>
# {{ __('mail.invitation.heading', ['app' => $app]) }}

{{ __('mail.invitation.body', ['name' => $invitedBy, 'institution' => $institution, 'app' => $app]) }}

{{ __('mail.invitation.sign_in', ['email' => $email]) }}

<x-mail::button :url="$url">
{{ __('mail.invitation.button') }}
</x-mail::button>

{{ __('mail.footer') }}
</x-mail::message>
