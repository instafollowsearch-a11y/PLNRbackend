<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="color-scheme" content="light">
    <title>{{ $title ?? 'PLNR' }}</title>
</head>
<body style="margin:0;padding:0;background-color:{{ $theme['softBg'] }};">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:{{ $theme['softBg'] }};padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background-color:{{ $theme['surface'] }};border:1px solid {{ $theme['border'] }};border-radius:12px;overflow:hidden;">
                <tr>
                    <td style="background-color:{{ $theme['accent'] }};padding:28px 32px 24px 32px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="font-family:Georgia,'Times New Roman',serif;font-size:28px;font-weight:700;letter-spacing:0.1em;color:#FFFFFF;">
                                    PLNR
                                </td>
                                <td align="right" style="font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:#FFFFFF;">
                                    {{ $theme['label'] }}
                                </td>
                            </tr>
                        </table>
                        @isset($heroEyebrow)
                            <p style="margin:16px 0 0 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;letter-spacing:0.06em;text-transform:uppercase;color:#FFFFFF;opacity:0.88;">
                                {{ $heroEyebrow }}
                            </p>
                        @endisset
                        @isset($heroTitle)
                            <h1 style="margin:8px 0 0 0;font-family:Georgia,'Times New Roman',serif;font-size:26px;line-height:1.25;font-weight:700;color:#FFFFFF;">
                                {{ $heroTitle }}
                            </h1>
                        @endisset
                    </td>
                </tr>

                <tr>
                    <td style="background-color:{{ $theme['softBg'] }};padding:20px 24px 8px 24px;" align="right">
                        @include('mail.motifs.'.$theme['motif'], ['theme' => $theme])
                    </td>
                </tr>

                <tr>
                    <td style="background-color:{{ $theme['softBg'] }};padding:0 24px 24px 24px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:{{ $theme['surface'] }};border:1px solid {{ $theme['border'] }};border-radius:10px;">
                            <tr>
                                <td style="padding:24px;font-family:Arial,Helvetica,sans-serif;color:{{ $theme['text'] }};font-size:15px;line-height:1.55;">
                                    {{ $slot }}
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:8px 32px 28px 32px;background-color:{{ $theme['softBg'] }};">
                        <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.5;color:{{ $theme['muted'] }};text-align:center;">
                            Enjoy your plan.<br>
                            <span style="font-family:Georgia,'Times New Roman',serif;font-weight:700;letter-spacing:0.08em;color:{{ $theme['accent'] }};">PLNR</span>
                            <span> · {{ date('Y') }}</span>
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
