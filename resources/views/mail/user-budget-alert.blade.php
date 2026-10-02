<x-mail::message>
# {{ __('mail.user_budget.heading_'.$threshold) }}

{{ __('mail.user_budget.body_'.$threshold, ['date' => $resetsOn]) }}

@if ($amounts !== null)
{{ __('mail.user_budget.amounts', ['spent' => '$'.$amounts['spent'], 'limit' => '$'.$amounts['limit']]) }}
@endif

<x-mail::button :url="$url">
{{ __('mail.user_budget.button') }}
</x-mail::button>

{{ __('mail.user_budget.settings') }}

{{ __('mail.footer') }}
</x-mail::message>
