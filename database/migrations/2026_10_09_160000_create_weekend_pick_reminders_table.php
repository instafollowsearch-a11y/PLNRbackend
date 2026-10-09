<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekend_pick_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('weekend_recommendation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('item_index');
            $table->string('title');
            $table->dateTime('scheduled_at');
            $table->dateTime('remind_at');
            $table->string('recipient_email');
            $table->timestamp('email_sent_at')->nullable();
            $table->timestamp('push_sent_at')->nullable();
            $table->timestamps();

            $table->index(['email_sent_at', 'remind_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekend_pick_reminders');
    }
};
