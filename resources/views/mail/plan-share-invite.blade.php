@component('mail.layouts.plnr', [
    'theme' => $theme,
    'title' => 'Plan shared with you',
    'heroEyebrow' => 'Shared plan',
    'heroTitle' => ($share->inviter?->name ?? 'Someone').' shared a plan with you',
])
    <p style="margin:0 0 16px 0;color:{{ $theme['muted'] }};font-size:15px;">
        Open the invite to view the plan together. If you already have a PLNR account, sign in with
        <strong style="color:{{ $theme['text'] }};">{{ $share->invitee_email }}</strong>.
        Otherwise create an account using that email.
    </p>

    @if (!empty($share->planSession?->city) || !empty($share->planSession?->planType?->label))
        <p style="margin:0 0 20px 0;font-size:14px;color:{{ $theme['text'] }};">
            @if (!empty($share->planSession?->planType?->label))
                <strong>{{ $share->planSession->planType->label }}</strong>
            @endif
            @if (!empty($share->planSession?->city))
                in {{ $share->planSession->city }}
            @endif
        </p>
    @endif

    @if (!empty($urls['web']))
        <p style="margin:0 0 12px 0;">
            <a href="{{ $urls['web'] }}" style="display:inline-block;background:{{ $theme['accent'] }};color:#FFFFFF;text-decoration:none;font-weight:700;font-size:14px;padding:12px 18px;border-radius:8px;">
                Open invite on web
            </a>
        </p>
    @endif

    <p style="margin:0 0 8px 0;font-size:13px;color:{{ $theme['muted'] }};">
        Prefer the app? Use this link on your phone:
        <a href="{{ $urls['app'] }}" style="color:{{ $theme['accent'] }};">{{ $urls['app'] }}</a>
    </p>

    @if ($appStoreUrl || $playStoreUrl)
        <p style="margin:16px 0 0 0;font-size:13px;color:{{ $theme['muted'] }};">
            Don’t have the app yet?
            @if ($appStoreUrl)
                <a href="{{ $appStoreUrl }}" style="color:{{ $theme['accent'] }};">App Store</a>
            @endif
            @if ($appStoreUrl && $playStoreUrl) · @endif
            @if ($playStoreUrl)
                <a href="{{ $playStoreUrl }}" style="color:{{ $theme['accent'] }};">Google Play</a>
            @endif
        </p>
    @endif
@endcomponent
