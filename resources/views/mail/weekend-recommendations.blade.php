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
        — here are {{ count($items) }} recommendations for the upcoming week.
    </p>

    @foreach ($items as $index => $item)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 14px 0;border:1px solid {{ $theme['border'] }};border-radius:10px;overflow:hidden;">
            <tr>
                <td style="padding:14px 16px;background-color:{{ $theme['surface'] }};">
                    <p style="margin:0 0 4px 0;font-size:12px;letter-spacing:0.06em;text-transform:uppercase;color:{{ $theme['accent'] }};font-weight:700;">
                        Pick {{ $index + 1 }}
                    </p>
                    <p style="margin:0 0 6px 0;font-family:Georgia,'Times New Roman',serif;font-size:18px;font-weight:700;color:{{ $theme['text'] }};">
                        {{ $item['title'] ?? 'Event' }}
                    </p>
                    @if (!empty($item['venue']) || !empty($item['starts_at']))
                        <p style="margin:0 0 8px 0;font-size:14px;color:{{ $theme['muted'] }};">
                            @if (!empty($item['venue'])){{ $item['venue'] }}@endif
                            @if (!empty($item['venue']) && !empty($item['starts_at'])) · @endif
                            @if (!empty($item['starts_at']))
                                {{ \Illuminate\Support\Carbon::parse($item['starts_at'])->timezone(config('app.timezone'))->format('D, M j · g:i A') }}
                            @endif
                        </p>
                    @endif
                    @if (!empty($item['reason']))
                        <p style="margin:0 0 10px 0;font-size:14px;color:{{ $theme['text'] }};">
                            {{ $item['reason'] }}
                        </p>
                    @endif
                    @if (!empty($item['url']))
                        <a href="{{ $item['url'] }}" style="color:{{ $theme['accent'] }};font-size:14px;font-weight:600;text-decoration:none;">
                            View event →
                        </a>
                    @endif
                </td>
            </tr>
        </table>
    @endforeach
@endcomponent
