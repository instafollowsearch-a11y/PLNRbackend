@component('mail.layouts.plnr', [
    'message' => $message ?? null,
    'theme' => $theme,
    'title' => $content['title'] ?? 'Shared PLNR plan',
    'heroEyebrow' => 'Shared with you',
    'heroTitle' => $content['title'] ?? 'Shared PLNR plan',
])
    <p style="margin:0 0 16px 0;color:{{ $theme['muted'] }};font-size:15px;">
        {{ $sharedBy?->name ?? 'A friend' }} shared a plan with you.
    </p>

    @if (!empty($planSession->city))
        <p style="margin:0 0 20px 0;font-size:14px;">
            <span style="color:{{ $theme['muted'] }};">City</span><br>
            <strong style="color:{{ $theme['text'] }};">{{ $planSession->city }}</strong>
        </p>
    @endif

    @if (!empty($viewUrl))
        <p style="margin:0;">
            <a href="{{ $viewUrl }}" style="display:inline-block;background:{{ $theme['accent'] }};color:#FFFFFF;text-decoration:none;font-weight:700;font-size:14px;padding:12px 18px;border-radius:8px;">
                View the plans shared with you
            </a>
        </p>
    @endif
@endcomponent
