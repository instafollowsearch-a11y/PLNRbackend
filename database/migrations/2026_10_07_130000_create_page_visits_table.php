<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('occurred_at');
            $table->string('path', 2048);
            $table->string('referrer', 2048)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 1024)->nullable();
            $table->string('browser', 64)->nullable();
            $table->string('browser_version', 32)->nullable();
            $table->string('platform', 64)->nullable();
            $table->string('platform_version', 32)->nullable();
            $table->string('device', 128)->nullable();
            $table->string('device_type', 32)->nullable();
            $table->boolean('is_robot')->default(false);
            $table->string('language', 64)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('screen', 32)->nullable();
            $table->string('plan', 16);
            $table->timestamps();

            $table->index('occurred_at');
            $table->index(['user_id', 'occurred_at']);
            $table->index('plan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_visits');
    }
};
