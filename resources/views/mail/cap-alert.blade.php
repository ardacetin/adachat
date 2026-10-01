<x-mail::message>
# {{ __('mail.cap_alert.heading_'.$threshold) }}

{{ __('mail.cap_alert.body_'.$threshold, ['institution' => $institution, 'used' => '$'.$usedUsd, 'cap' => '$'.$capUsd, 'date' => $resetsOn]) }}

{{ __('mail.cap_alert.what_now') }}

<x-mail::button :url="$url">
{{ __('mail.cap_alert.button') }}
</x-mail::button>

{{ __('mail.footer') }}
</x-mail::message>
