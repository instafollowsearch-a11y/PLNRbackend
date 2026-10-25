<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interest_pings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('matched_interest');
            $table->timestamp('scheduled_at');
            $table->timestamp('remind_at');
            $table->timestamp('push_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'event_id', 'kind']);
            $table->index(['remind_at', 'push_sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interest_pings');
    }
};
