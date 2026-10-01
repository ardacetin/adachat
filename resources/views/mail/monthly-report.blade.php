@php
    $totals = $summary['totals'];
    $money = fn (string $amount) => '$'.$amount;
@endphp
<x-mail::message>
# {{ __('mail.monthly.heading', ['month' => $month]) }}

{{ __('mail.monthly.intro', ['institution' => $institution, 'month' => $month]) }}

<x-mail::table>
| | |
|:--|--:|
| {{ __('mail.monthly.spend') }} | {{ $money($totals['cost_usd']) }} |
| {{ __('mail.monthly.requests') }} | {{ number_format($totals['requests']) }} |
| {{ __('mail.monthly.users') }} | {{ number_format($totals['users']) }} |
@if ($totals['adjustments_usd'] !== '0.00')
| {{ __('mail.monthly.adjustments') }} | {{ $money($totals['adjustments_usd']) }} |
@endif
| {{ __('mail.monthly.users_at_limit') }} | {{ $summary['users_at_limit'] }} |
@if ($summary['cap'] !== null)
| {{ __('mail.monthly.cap') }} | {{ $summary['cap']['percent'] }} % / {{ $money($summary['cap']['cap_usd']) }} |
@endif
</x-mail::table>

@if ($summary['overshoots'] > 0)
{{ __('mail.monthly.overshoots', ['count' => $summary['overshoots']]) }}
@endif

@foreach (['groups', 'models'] as $section)
@if ($summary[$section] !== [])
## {{ __('mail.monthly.top_'.$section) }}

<x-mail::table>
| {{ __('mail.monthly.name') }} | {{ __('mail.monthly.requests') }} | {{ __('mail.monthly.spend') }} |
|:--|--:|--:|
@foreach ($summary[$section] as $row)
| {{ $row['label'] }} | {{ number_format($row['requests']) }} | {{ $money($row['cost_usd']) }} |
@endforeach
</x-mail::table>
@endif
@endforeach

{{ __('mail.monthly.attachments') }}

<x-mail::button :url="$url">
{{ __('mail.monthly.button') }}
</x-mail::button>

{{ __('mail.footer') }}
</x-mail::message>
