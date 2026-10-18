@props(['url'])
<tr>
<td class="header" align="center">
<table class="header-card" role="presentation" width="570" cellpadding="0" cellspacing="0" align="center" style="width:570px;max-width:100%;background-color:#ffffff;border:1px solid #E4DDD4;border-bottom:0;border-radius:12px 12px 0 0;">
<tr>
<td align="center" bgcolor="#ffffff" style="background-color:#ffffff;padding:24px 0 12px;border-radius:12px 12px 0 0;">
<a href="{{ $url }}" style="display: inline-block; background-color: #ffffff; text-decoration: none;">
@php
    $plnrLogoFile = public_path('images/plnr-logo-email.png');
    if (! is_file($plnrLogoFile)) {
        $plnrLogoFile = public_path('images/plnr-logo-black-lettering.png');
    }
    $plnrLogoMessage = $plnrMailMessage ?? ($message ?? null);
    $plnrLogo = (isset($plnrLogoMessage) && is_object($plnrLogoMessage) && method_exists($plnrLogoMessage, 'embed') && is_file($plnrLogoFile))
        ? $plnrLogoMessage->embed($plnrLogoFile)
        : null;
@endphp
@if ($plnrLogo)
<img src="{{ $plnrLogo }}" class="logo" alt="PLNR" width="120" height="44" style="display:block;background-color:#ffffff;border:0;">
@else
PLNR
@endif
</a>
</td>
</tr>
</table>
</td>
</tr>
