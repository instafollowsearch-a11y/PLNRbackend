@component('mail.layouts.plnr', [
    'theme' => $theme,
    'title' => 'Reminder: '.$booking->title,
    'heroEyebrow' => $theme['label'].' reminder',
    'heroTitle' => $booking->title,
])
    <p style="margin:0 0 16px 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;color:{{ $theme['text'] }};">
        Hi {{ $booking->user->name }},
    </p>
    <p style="margin:0 0 16px 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;color:{{ $theme['muted'] }};">
        This is a reminder for your upcoming plan.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid {{ $theme['border'] }};border-radius:10px;background-color:{{ $theme['softBg'] }};">
        <tr>
            <td style="width:6px;background-color:{{ $theme['accent'] }};border-radius:10px 0 0 10px;font-size:0;line-height:0;">&nbsp;</td>
            <td style="padding:16px 18px;">
                <p style="margin:0 0 4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;color:{{ $theme['accent'] }};">
                    Scheduled for
                </p>
                <p style="margin:0;font-family:Georgia,'Times New Roman',serif;font-size:18px;font-weight:700;color:{{ $theme['text'] }};">
                    {{ $booking->scheduled_for?->format('D, M j, Y · g:i A') }}
                </p>
            </td>
        </tr>
    </table>
@endcomponent
