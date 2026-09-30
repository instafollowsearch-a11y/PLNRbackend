<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itinerary_stop_reminders', function (Blueprint $table) {
            $table->timestamp('push_sent_at')->nullable()->after('email_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('itinerary_stop_reminders', function (Blueprint $table) {
            $table->dropColumn('push_sent_at');
        });
    }
};
