<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('plan_sessions', 'recipient_email')) {
            return;
        }

        Schema::table('plan_sessions', function (Blueprint $table) {
            $table->string('recipient_email')->nullable()->after('recipient_phone');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('plan_sessions', 'recipient_email')) {
            return;
        }

        Schema::table('plan_sessions', function (Blueprint $table) {
            $table->dropColumn('recipient_email');
        });
    }
};
