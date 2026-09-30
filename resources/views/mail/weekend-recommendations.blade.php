@component('mail.layouts.plnr', [
    'theme' => $theme,
    'title' => 'Weekend picks in '.$recommendation->city,
    'heroEyebrow' => 'Weekend picks',
    'heroTitle' => 'Things to do in '.$recommendation->city,
])
    <p style="margin:0 0 16px 0;color:{{ $theme['muted'] }};font-size:15px;">
        Based on your interests
        @if (!empty($recommendation->interests) && is_array($recommendation->interests))
            ({{ implode(', ', $recommendation->interests) }})
        @endif
        — here are {{ count($items) }} recommendations for the coming weekend.
    </p>

    @if (is_array($days))
        @foreach ($days as $dayName => $dayItems)
            <p style="margin:18px 0 10px 0;font-family:Georgia,'Times New Roman',serif;font-size:20px;font-weight:700;color:{{ $theme['text'] }};">
                {{ $dayName }}
            </p>
            @if (count($dayItems) === 0)
                <p style="margin:0 0 14px 0;font-size:14px;color:{{ $theme['muted'] }};">Nothing listed.</p>
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
        <p style="margin:22px 0 8px 0;font-family:Georgia,'Times New Roman',serif;font-size:20px;font-weight:700;color:{{ $theme['text'] }};">
            Saturday plan
        </p>
        <p style="margin:0 0 6px 0;font-size:16px;font-weight:700;color:{{ $theme['text'] }};">
            {{ $saturdayPlan['title'] }}
        </p>
        @if (!empty($saturdayPlan['summary']))
            <p style="margin:0 0 12px 0;font-size:14px;color:{{ $theme['muted'] }};">
                {{ $saturdayPlan['summary'] }}
            </p>
        @endif
        @foreach (($saturdayPlan['stops'] ?? []) as $stop)
            <p style="margin:0 0 8px 0;font-size:14px;color:{{ $theme['text'] }};">
                <strong>{{ $stop['time'] ?? 'Time TBA' }}</strong>
                {{ $stop['name'] ?? 'Stop' }}
                @if (!empty($stop['detail']))
                    — {{ $stop['detail'] }}
                @endif
            </p>
        @endforeach
    @endif
@endcomponent
