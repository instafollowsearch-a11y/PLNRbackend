@component('mail.layouts.plnr', [
    'theme' => $theme,
    'title' => 'Reset your password',
    'heroEyebrow' => 'Account',
    'heroTitle' => 'Choose a new password',
    'closing' => 'If you did not ask for this, you can ignore this email.',
])
    <p style="margin:0 0 16px 0;color:{{ $theme['muted'] }};font-size:15px;">
        We received a request to reset the password for
        <strong style="color:{{ $theme['text'] }};">{{ $email }}</strong>.
        This link works once and expires in 60 minutes.
    </p>

    <p style="margin:0 0 16px 0;">
        <a href="{{ $resetUrl }}" style="display:inline-block;background:{{ $theme['accent'] }};color:#FFFFFF;text-decoration:none;font-weight:700;font-size:14px;padding:12px 18px;border-radius:8px;">
            Choose a new password
        </a>
    </p>

    <p style="margin:0;font-size:13px;line-height:1.5;color:{{ $theme['muted'] }};">
        Or copy this link into your browser:<br>
        <a href="{{ $resetUrl }}" style="color:{{ $theme['accent'] }};word-break:break-all;">{{ $resetUrl }}</a>
    </p>
@endcomponent
