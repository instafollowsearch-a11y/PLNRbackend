@php
    $appStoreUrl = null;
    $playStoreUrl = null;

    if (\Illuminate\Support\Facades\Schema::hasTable('app_settings')) {
        $storeSettings = app(\App\Services\Settings\AppSettings::class);
        $appStoreUrl = $storeSettings->appStoreUrl();
        $playStoreUrl = $storeSettings->playStoreUrl();
    } else {
        $configuredAppStore = config('services.pro.app_store_url');
        $configuredPlayStore = config('services.pro.play_store_url');
        $appStoreUrl = is_string($configuredAppStore) ? $configuredAppStore : null;
        $playStoreUrl = is_string($configuredPlayStore) ? $configuredPlayStore : null;
    }

    $mailMessage = $message ?? ($plnrMailMessage ?? null);
    $canEmbed = isset($mailMessage) && is_object($mailMessage) && method_exists($mailMessage, 'embed');
    $appStoreFile = public_path('images/app-store-badge.png');
    $playStoreFile = public_path('images/google-play-badge.png');
    $appStoreBadge = ($canEmbed && is_file($appStoreFile))
        ? $mailMessage->embed($appStoreFile)
        : asset('images/app-store-badge.png');
    $playStoreBadge = ($canEmbed && is_file($playStoreFile))
        ? $mailMessage->embed($playStoreFile)
        : asset('images/google-play-badge.png');
    // Official badge art is 168x56 / 188x56 — keep height ~40 so both sit side-by-side on phones.
    $appImgStyle = 'display:block;border:0;outline:none;text-decoration:none;width:120px;height:40px;max-width:120px;max-height:40px;';
    $playImgStyle = 'display:block;border:0;outline:none;text-decoration:none;width:134px;height:40px;max-width:134px;max-height:40px;';
    $cellStyle = 'padding:0 6px;white-space:nowrap;vertical-align:middle;';
@endphp
<table class="store-badges" align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:16px auto 0;border-collapse:collapse;">
<tr>
<td align="center" style="padding:0;">
<table border="0" cellpadding="0" cellspacing="0" role="presentation" align="center" style="border-collapse:collapse;margin:0 auto;">
<tr>
<td align="center" valign="middle" class="store-badge" style="{{ $cellStyle }}">
@if (is_string($appStoreUrl) && $appStoreUrl !== '')
<a href="{{ $appStoreUrl }}" class="store-badge-link" target="_blank" rel="noopener" style="display:inline-block;line-height:0;text-decoration:none;border:0;outline:none;">
<img src="{{ $appStoreBadge }}" alt="Download on the App Store" width="120" height="40" style="{{ $appImgStyle }}">
</a>
@else
<span class="store-badge-link" style="display:inline-block;line-height:0;">
<img src="{{ $appStoreBadge }}" alt="Download on the App Store" width="120" height="40" style="{{ $appImgStyle }}">
</span>
@endif
</td>
<td align="center" valign="middle" class="store-badge" style="{{ $cellStyle }}">
@if (is_string($playStoreUrl) && $playStoreUrl !== '')
<a href="{{ $playStoreUrl }}" class="store-badge-link" target="_blank" rel="noopener" style="display:inline-block;line-height:0;text-decoration:none;border:0;outline:none;">
<img src="{{ $playStoreBadge }}" alt="Get it on Google Play" width="134" height="40" style="{{ $playImgStyle }}">
</a>
@else
<span class="store-badge-link" style="display:inline-block;line-height:0;">
<img src="{{ $playStoreBadge }}" alt="Get it on Google Play" width="134" height="40" style="{{ $playImgStyle }}">
</span>
@endif
</td>
</tr>
</table>
</td>
</tr>
</table>
