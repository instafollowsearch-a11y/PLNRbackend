@component('mail::message')
{{ $theme['label'] }}

# {{ $acceptedBy->name }} accepted your plan invite

{{ $acceptedBy->name }} ({{ $acceptedBy->email }}) can now view this plan with you. They’ll also get a reminder in the app when stops are coming up.

@if (!empty($share->planSession?->planType?->label) || !empty($share->planSession?->city))
@if (!empty($share->planSession?->planType?->label))
**{{ $share->planSession->planType->label }}**
@endif
@if (!empty($share->planSession?->city))
in {{ $share->planSession->city }}
@endif
@endif

Enjoy your plans.

@include('mail.partials.store-badges')
@endcomponent
