@component('mail::message')
# {{ $content['title'] ?? 'Your PLNR Itinerary' }}

@if (!empty($content['summary']))
{{ $content['summary'] }}
@endif

Times are ranges, not exact times. Places and plans can be off. Double-check before you go.

@if (!empty($planSession->city))
**City**

{{ $planSession->city }}
@endif

Itinerary

@if (!empty($content['stops']) && is_array($content['stops']))
@foreach ($content['stops'] as $stop)
@include('mail.partials.stop-card', ['stop' => $stop, 'theme' => $theme])
@endforeach
@endif

@if (!empty($content['days']) && is_array($content['days']))
@foreach ($content['days'] as $day)
## {{ $day['date'] ?? 'Day' }}@if (!empty($day['theme'])) — {{ $day['theme'] }}@endif

@foreach ($day['stops'] ?? [] as $stop)
@include('mail.partials.stop-card', ['stop' => $stop, 'theme' => $theme])
@endforeach
@endforeach
@endif

Enjoy your plans.
@endcomponent
