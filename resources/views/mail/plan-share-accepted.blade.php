@component('mail.layouts.plnr', [
    'theme' => $theme,
    'title' => 'Plan invite accepted',
    'heroEyebrow' => 'Shared plan',
    'heroTitle' => $acceptedBy->name.' accepted your plan invite',
])
    <p style="margin:0 0 16px 0;color:{{ $theme['muted'] }};font-size:15px;">
        {{ $acceptedBy->name }} ({{ $acceptedBy->email }}) can now view this plan with you.
        They’ll also get plan reminder emails when stops are coming up.
    </p>

    @if (!empty($share->planSession?->city) || !empty($share->planSession?->planType?->label))
        <p style="margin:0;font-size:14px;color:{{ $theme['text'] }};">
            @if (!empty($share->planSession?->planType?->label))
                <strong>{{ $share->planSession->planType->label }}</strong>
            @endif
            @if (!empty($share->planSession?->city))
                in {{ $share->planSession->city }}
            @endif
        </p>
    @endif
@endcomponent
