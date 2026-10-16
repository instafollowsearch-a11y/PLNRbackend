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
@endphp
@if ($appStoreUrl || $playStoreUrl)
<table role="presentation" align="center" cellpadding="0" cellspacing="0" style="margin: 8px auto 12px;">
<tr>
@if ($appStoreUrl)
<td style="padding: 0 6px;">
<a href="{{ $appStoreUrl }}" style="display: inline-block; background: #1A1A1A; color: #ffffff; text-decoration: none; border-radius: 8px; padding: 10px 14px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; font-size: 13px; font-weight: 700; line-height: 1;">App Store</a>
</td>
@endif
@if ($playStoreUrl)
<td style="padding: 0 6px;">
<a href="{{ $playStoreUrl }}" style="display: inline-block; background: #1A1A1A; color: #ffffff; text-decoration: none; border-radius: 8px; padding: 10px 14px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; font-size: 13px; font-weight: 700; line-height: 1;">Google Play</a>
</td>
@endif
</tr>
</table>
@endif
