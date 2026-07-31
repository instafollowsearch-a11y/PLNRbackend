<?php

namespace Database\Seeders;

use App\Models\AppSetting;
use App\Models\User;
use App\Services\Settings\AppSettings;
use Illuminate\Database\Seeder;

class AppSettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (AppSettings::DEFAULTS as $key => $value) {
            AppSetting::query()->firstOrCreate(
                ['key' => $key],
                ['value' => json_encode($value)],
            );
        }

        $adminEmail = env('ADMIN_EMAIL');

        if (is_string($adminEmail) && $adminEmail !== '') {
            User::query()->where('email', $adminEmail)->update([
                'role' => User::ROLE_ADMIN,
            ]);
        }
    }
}
