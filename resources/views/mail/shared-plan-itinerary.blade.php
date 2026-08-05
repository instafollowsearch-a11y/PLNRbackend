@component('mail.layouts.plnr', [
    'theme' => $theme,
    'title' => $content['title'] ?? 'Shared PLNR plan',
    'heroEyebrow' => 'Shared with you',
    'heroTitle' => $content['title'] ?? 'Shared PLNR plan',
])
    <p style="margin:0 0 16px 0;color:{{ $theme['muted'] }};font-size:15px;">
        {{ $sharedBy?->name ?? 'A friend' }} added you to this plan. Here’s the itinerary so you can follow along.
    </p>

    @include('mail.itinerary', [
        'theme' => $theme,
        'content' => $content,
        'planSession' => $planSession,
    ])
@endcomponent
