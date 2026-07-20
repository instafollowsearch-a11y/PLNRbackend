<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 12px 0;border:1px solid {{ $theme['border'] }};border-radius:8px;background-color:{{ $theme['softBg'] }};">
    <tr>
        <td style="width:6px;background-color:{{ $theme['accent'] }};border-radius:8px 0 0 8px;font-size:0;line-height:0;">&nbsp;</td>
        <td style="padding:14px 16px;">
            <p style="margin:0 0 4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;color:{{ $theme['accent'] }};">
                {{ $stop['time'] ?? '' }}
            </p>
            <p style="margin:0 0 6px 0;font-family:Georgia,'Times New Roman',serif;font-size:17px;font-weight:700;color:{{ $theme['text'] }};">
                {{ $stop['name'] ?? 'Stop' }}
            </p>
            @if (!empty($stop['activity']))
                <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:14px;color:{{ $theme['text'] }};">
                    {{ $stop['activity'] }}
                </p>
            @endif
            @if (!empty($stop['notes']))
                <p style="margin:6px 0 0 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-style:italic;color:{{ $theme['muted'] }};">
                    {{ $stop['notes'] }}
                </p>
            @endif
        </td>
    </tr>
</table>
