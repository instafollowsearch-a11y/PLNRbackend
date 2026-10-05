<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const EMAIL = 'dawiterefe@outlook.com';

    public function up(): void
    {
        DB::table('users')
            ->whereRaw('LOWER(email) = ?', [self::EMAIL])
            ->update([
                'pro_status' => 'active',
                'pro_current_period_end' => now()->addYear(),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('users')
            ->whereRaw('LOWER(email) = ?', [self::EMAIL])
            ->update([
                'pro_status' => 'inactive',
                'pro_current_period_end' => null,
                'updated_at' => now(),
            ]);
    }
};
