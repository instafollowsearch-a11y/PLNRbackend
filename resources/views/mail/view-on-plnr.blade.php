@component('mail::message')
{{ $theme['label'] }}

# {{ $content['title'] ?? 'Your PLNR plan' }}

Times are ranges, not exact times. Places and plans can be off. Double-check before you go.

@if (!empty($planSession->city))
**City**

{{ $planSession->city }}
@endif

@if (!empty($viewUrl))
<x-mail::button :url="$viewUrl" :color="$theme['slug']">
View your plans
</x-mail::button>
@endif

Enjoy your plans.
@endcomponent
