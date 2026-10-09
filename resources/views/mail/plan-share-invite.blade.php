@component('mail::message')
{{ $theme['label'] }}

# {{ ($share->inviter?->name ?? 'Someone') }} shared a plan with you

Open the invite to view the plan together. If you already have a PLNR account, sign in with **{{ $share->invitee_email }}**. Otherwise create an account using that email.

@if (!empty($share->planSession?->planType?->label) || !empty($share->planSession?->city))
@if (!empty($share->planSession?->planType?->label))
**{{ $share->planSession->planType->label }}**
@endif
@if (!empty($share->planSession?->city))
in {{ $share->planSession->city }}
@endif
@endif

@if (!empty($urls['web']))
<x-mail::button :url="$urls['web']" :color="$theme['slug']">
View the plans shared with you
</x-mail::button>
@endif

View on the app for an enhanced experience: [{{ $urls['app'] }}]({{ $urls['app'] }})

@if ($appStoreUrl || $playStoreUrl)
Don’t have the app yet?
@if ($appStoreUrl)
[App Store]({{ $appStoreUrl }})
@endif
@if ($playStoreUrl)
[Google Play]({{ $playStoreUrl }})
@endif
@endif

Enjoy your plans.
@endcomponent
