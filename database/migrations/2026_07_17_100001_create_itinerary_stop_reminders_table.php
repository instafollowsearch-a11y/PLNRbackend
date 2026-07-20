<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('itinerary_stop_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('itinerary_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('stop_index');
            $table->unsignedSmallInteger('day_index')->nullable();
            $table->string('stop_name');
            $table->string('activity')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('scheduled_at');
            $table->dateTime('remind_at');
            $table->string('recipient_email');
            $table->string('plan_type_slug', 64)->nullable();
            $table->timestamp('email_sent_at')->nullable();
            $table->timestamps();

            $table->index(['email_sent_at', 'remind_at']);
            $table->unique(
                ['itinerary_id', 'day_index', 'stop_index'],
                'itinerary_stop_reminders_unique_stop',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('itinerary_stop_reminders');
    }
};
