@component('mail::message')
{{ $theme['label'] }}

# {{ $content['title'] ?? 'Shared PLNR plan' }}

{{ $sharedBy?->name ?? 'A friend' }} shared a plan with you.

@if (!empty($planSession->city))
**City**

{{ $planSession->city }}
@endif

@if (!empty($viewUrl))
<x-mail::button :url="$viewUrl" :color="$theme['slug']">
View the plans shared with you
</x-mail::button>
@endif

Enjoy your plans.
@endcomponent
