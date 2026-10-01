<x-mail::message>
# {{ __('mail.test.heading') }}

{{ __('mail.test.body', ['institution' => $institution]) }}

{{ __('mail.footer') }}
</x-mail::message>
