<x-mail::message>
# {{ $texts['heading'] }}

{{ $texts['body'] }}

{{ $texts['sign_in'] }}

<x-mail::button :url="$url">
{{ $texts['button'] }}
</x-mail::button>

{{ __('mail.footer') }}
</x-mail::message>
