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
@if ($appStoreUrl)
App Store: {{ $appStoreUrl }}
@endif
@if ($playStoreUrl)
Google Play: {{ $playStoreUrl }}
@endif
