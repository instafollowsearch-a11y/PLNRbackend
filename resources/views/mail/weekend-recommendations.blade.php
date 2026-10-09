@component('mail::message')
# Things to do in {{ $recommendation->city }}

Based on your interests
@if (!empty($recommendation->interests) && is_array($recommendation->interests))
({{ implode(', ', $recommendation->interests) }})
@endif
— here are {{ count($items) }} recommendations for the coming weekend.

@if (is_array($days))
@foreach ($days as $dayName => $dayItems)
## {{ $dayName }}

@if (count($dayItems) === 0)
Nothing listed.
@endif
@foreach ($dayItems as $index => $item)
@include('mail.partials.weekend-event', ['theme' => $theme, 'item' => $item, 'index' => $index])
@endforeach
@endforeach
@else
@foreach ($items as $index => $item)
@include('mail.partials.weekend-event', ['theme' => $theme, 'item' => $item, 'index' => $index])
@endforeach
@endif

@if (is_array($saturdayPlan) && !empty($saturdayPlan['title']))
## Saturday plan

**{{ $saturdayPlan['title'] }}**

@if (!empty($saturdayPlan['summary']))
{{ $saturdayPlan['summary'] }}
@endif

@foreach (($saturdayPlan['stops'] ?? []) as $stop)
**{{ $stop['time'] ?? 'Time TBA' }}** {{ $stop['name'] ?? 'Stop' }}@if (!empty($stop['detail'])) — {{ $stop['detail'] }}@endif

@endforeach
@endif

Enjoy your plans.
@endcomponent
