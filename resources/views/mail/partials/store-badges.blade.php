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
    $appleFile = public_path('images/store-apple.png');
    $playFile = public_path('images/store-play.png');
    $appleIcon = ($canEmbed && is_file($appleFile)) ? $mailMessage->embed($appleFile) : asset('images/store-apple.png');
    $playIcon = ($canEmbed && is_file($playFile)) ? $mailMessage->embed($playFile) : asset('images/store-play.png');
    $iconStyle = 'display:inline-block;border:0;outline:none;width:18px;height:18px;max-width:18px;max-height:18px;vertical-align:middle;margin-right:8px;';
@endphp
<table class="action" align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 16px auto 0;">
<tr>
<td align="center">
<table border="0" cellpadding="0" cellspacing="0" role="presentation" align="center">
<tr>
<td align="center" valign="middle" style="padding: 0 6px;">
@if (is_string($appStoreUrl) && $appStoreUrl !== '')
<a href="{{ $appStoreUrl }}" class="button button-store" target="_blank" rel="noopener"><img src="{{ $appleIcon }}" alt="" width="18" height="18" style="{{ $iconStyle }}">App Store</a>
@else
<span class="button button-store"><img src="{{ $appleIcon }}" alt="" width="18" height="18" style="{{ $iconStyle }}">App Store</span>
@endif
</td>
<td align="center" valign="middle" style="padding: 0 6px;">
@if (is_string($playStoreUrl) && $playStoreUrl !== '')
<a href="{{ $playStoreUrl }}" class="button button-store" target="_blank" rel="noopener"><img src="{{ $playIcon }}" alt="" width="18" height="18" style="{{ $iconStyle }}">Google Play</a>
@else
<span class="button button-store"><img src="{{ $playIcon }}" alt="" width="18" height="18" style="{{ $iconStyle }}">Google Play</span>
@endif
</td>
</tr>
</table>
</td>
</tr>
</table>
