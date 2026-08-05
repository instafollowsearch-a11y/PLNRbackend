<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('pro_status', 32)->default('inactive')->after('role');
            $table->string('stripe_subscription_id')->nullable()->after('stripe_customer_id');
            $table->timestamp('pro_current_period_end')->nullable()->after('stripe_subscription_id');
            $table->json('interests')->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'pro_status',
                'stripe_subscription_id',
                'pro_current_period_end',
                'interests',
            ]);
        });
    }
};
