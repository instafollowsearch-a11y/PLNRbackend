@component('mail.layouts.plnr', [
    'theme' => $theme,
    'title' => $content['title'] ?? 'Your PLNR Itinerary',
    'heroEyebrow' => 'Your itinerary',
    'heroTitle' => $content['title'] ?? 'Your PLNR Itinerary',
])
    @if (!empty($content['summary']))
        <p style="margin:0 0 16px 0;color:{{ $theme['muted'] }};font-size:15px;">
            {{ $content['summary'] }}
        </p>
    @endif

    @if (!empty($planSession->city))
        <p style="margin:0 0 20px 0;font-size:14px;">
            <span style="color:{{ $theme['muted'] }};">City</span><br>
            <strong style="color:{{ $theme['text'] }};">{{ $planSession->city }}</strong>
        </p>
    @endif

    <p style="margin:0 0 12px 0;font-size:12px;letter-spacing:0.06em;text-transform:uppercase;color:{{ $theme['accent'] }};font-weight:700;">
        Itinerary
    </p>

    @if (!empty($content['stops']) && is_array($content['stops']))
        @foreach ($content['stops'] as $stop)
            @include('mail.partials.stop-card', ['stop' => $stop, 'theme' => $theme])
        @endforeach
    @endif

    @if (!empty($content['days']) && is_array($content['days']))
        @foreach ($content['days'] as $day)
            <p style="margin:20px 0 10px 0;font-family:Georgia,'Times New Roman',serif;font-size:18px;font-weight:700;color:{{ $theme['text'] }};">
                {{ $day['date'] ?? 'Day' }}
                @if (!empty($day['theme']))
                    <span style="font-weight:400;color:{{ $theme['muted'] }};"> — {{ $day['theme'] }}</span>
                @endif
            </p>
            @foreach ($day['stops'] ?? [] as $stop)
                @include('mail.partials.stop-card', ['stop' => $stop, 'theme' => $theme])
            @endforeach
        @endforeach
    @endif
@endcomponent
