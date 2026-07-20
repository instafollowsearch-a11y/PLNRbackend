@component('mail.layouts.plnr', [
    'theme' => $theme,
    'title' => 'Upcoming: '.$reminder->stop_name,
    'heroEyebrow' => $theme['label'].' reminder',
    'heroTitle' => 'Coming up soon',
])
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 8px 0;border:1px solid {{ $theme['border'] }};border-radius:10px;background-color:{{ $theme['softBg'] }};">
        <tr>
            <td style="width:6px;background-color:{{ $theme['accent'] }};border-radius:10px 0 0 10px;font-size:0;line-height:0;">&nbsp;</td>
            <td style="padding:18px 18px;">
                <p style="margin:0 0 6px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;color:{{ $theme['accent'] }};">
                    {{ $reminder->scheduled_at?->format('D, M j · g:i A') }}
                </p>
                <p style="margin:0 0 10px 0;font-family:Georgia,'Times New Roman',serif;font-size:22px;font-weight:700;color:{{ $theme['text'] }};">
                    {{ $reminder->stop_name }}
                </p>
                @if (!empty($reminder->activity))
                    <p style="margin:0 0 8px 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;color:{{ $theme['text'] }};">
                        <strong>Activity:</strong> {{ $reminder->activity }}
                    </p>
                @endif
                @if (!empty($reminder->notes))
                    <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-style:italic;color:{{ $theme['muted'] }};">
                        {{ $reminder->notes }}
                    </p>
                @endif
            </td>
        </tr>
    </table>

    @if (!empty($city))
        <p style="margin:16px 0 0 0;font-family:Arial,Helvetica,sans-serif;font-size:14px;color:{{ $theme['muted'] }};">
            City: <strong style="color:{{ $theme['text'] }};">{{ $city }}</strong>
        </p>
    @endif
@endcomponent
