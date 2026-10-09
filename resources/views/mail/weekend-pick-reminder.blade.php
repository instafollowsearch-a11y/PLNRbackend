@component('mail::message')
{{ $theme['label'] }}

# Coming up soon

<x-mail::panel>
{{ $reminder->scheduled_at?->timezone(config('app.timezone'))->format('D, M j · g:i A') }}

**{{ $reminder->title }}**
</x-mail::panel>

@if (!empty($city))
City: **{{ $city }}**
@endif

This is the nudge before this stop on your weekend picks.

Enjoy your plans.
@endcomponent
