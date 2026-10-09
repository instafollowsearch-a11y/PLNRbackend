@component('mail::message')
{{ $theme['label'] }}

# Coming up soon

<x-mail::panel>
{{ $reminder->scheduled_at?->format('D, M j · g:i A') }}

**{{ $reminder->stop_name }}**

@if (!empty($reminder->activity))
**Activity:** {{ $reminder->activity }}
@endif

@if (!empty($reminder->notes))
*{{ $reminder->notes }}*
@endif
</x-mail::panel>

@if (!empty($city))
City: **{{ $city }}**
@endif

Enjoy your plans.
@endcomponent
