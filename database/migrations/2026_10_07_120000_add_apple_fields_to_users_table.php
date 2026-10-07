<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('apple_product_id')->nullable()->after('google_play_purchase_token');
            $table->string('apple_original_transaction_id')->nullable()->after('apple_product_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'apple_product_id',
                'apple_original_transaction_id',
            ]);
        });
    }
};
