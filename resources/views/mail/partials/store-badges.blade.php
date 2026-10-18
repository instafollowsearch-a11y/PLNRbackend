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

    $appleMark = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="20" viewBox="0 0 384 512" aria-hidden="true" style="display: inline-block; vertical-align: middle; margin-right: 8px;"><path fill="#ffffff" d="M318.7 268.7c-.2-36.7 16.4-64.4 50-84.8-18.8-26.9-47.2-41.7-84.7-44.6-35.5-2.8-74.3 20.7-88.5 20.7-15 0-49.4-19.7-76.4-19.7C63.3 141.2 4 184.8 4 273.5q0 77.4 43.8 150.3c19.4 31.8 43.4 67.6 75.4 66.2 29.6-1.4 40.8-19.1 76.9-19.1s46.1 19.1 76.4 19.1c32.4.4 56.8-32.6 75.4-64.8 21.4-33.2 30.2-65.7 30.4-67.4-.7-.3-58.6-22.5-58.8-89.1zM267.3 80.4c27.3-32.4 24.8-61.9 24-72.5-24.1 1.4-52 16.4-67.9 34.9-17.5 19.8-27.8 44.3-25.6 71.9 26.1 2 49.9-11.4 69.5-34.3z"/></svg>App Store';
    $playMark = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="18" viewBox="0 0 24 24" aria-hidden="true" style="display: inline-block; vertical-align: middle; margin-right: 8px;"><path fill="#34A853" d="M3.11 2.54 13.09 12 3.11 21.46c-.45-.27-.73-.76-.73-1.33V3.87c0-.57.28-1.06.73-1.33z"/><path fill="#FBBC04" d="m16.86 8.37-3.77 3.63 3.77 3.63 3.51-2.03c.94-.55.94-2.05 0-2.6z"/><path fill="#4285F4" d="M3.11 2.54c.23-.14.5-.22.78-.22.4 0 .8.13 1.13.37L16.86 8.37 13.09 12 3.11 2.54z"/><path fill="#EA4335" d="m13.09 12 4.77 4.63-11.84 6.88c-.33.24-.73.37-1.13.37-.28 0-.55-.08-.78-.22L13.09 12z"/></svg>Google Play';
@endphp
<table class="action" align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 16px auto 0;">
<tr>
<td align="center">
<table border="0" cellpadding="0" cellspacing="0" role="presentation" align="center">
<tr>
<td align="center" style="padding: 0 6px;" valign="middle">
@if (is_string($appStoreUrl) && $appStoreUrl !== '')
<a href="{{ $appStoreUrl }}" class="button button-store" target="_blank" rel="noopener">{!! $appleMark !!}</a>
@else
<span class="button button-store">{!! $appleMark !!}</span>
@endif
</td>
<td align="center" style="padding: 0 6px;" valign="middle">
@if (is_string($playStoreUrl) && $playStoreUrl !== '')
<a href="{{ $playStoreUrl }}" class="button button-store" target="_blank" rel="noopener">{!! $playMark !!}</a>
@else
<span class="button button-store">{!! $playMark !!}</span>
@endif
</td>
</tr>
</table>
</td>
</tr>
</table>
