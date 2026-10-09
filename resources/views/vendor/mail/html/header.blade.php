@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
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
<img src="{{ $plnrLogo }}" class="logo" alt="PLNR" width="120" height="44">
@else
PLNR
@endif
</a>
</td>
</tr>
