<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@plnr.test'],
            [
                'name' => 'PLNR Admin',
                'password' => Hash::make('password'),
                'role' => User::ROLE_ADMIN,
                'city' => 'Austin',
            ],
        );

        User::query()->updateOrCreate(
            ['email' => 'user@plnr.test'],
            [
                'name' => 'PLNR User',
                'password' => Hash::make('password'),
                'role' => User::ROLE_USER,
                'city' => 'Austin',
            ],
        );
    }
}
