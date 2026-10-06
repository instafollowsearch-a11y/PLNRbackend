<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('google_play_product_id')->nullable()->after('pro_current_period_end');
            $table->text('google_play_purchase_token')->nullable()->after('google_play_product_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'google_play_product_id',
                'google_play_purchase_token',
            ]);
        });
    }
};
