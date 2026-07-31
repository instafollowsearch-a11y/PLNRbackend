<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_sessions', function (Blueprint $table): void {
            if (! Schema::hasColumn('plan_sessions', 'creator_ip')) {
                $table->string('creator_ip', 45)->nullable()->after('user_id');
                $table->index('creator_ip');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plan_sessions', function (Blueprint $table): void {
            if (Schema::hasColumn('plan_sessions', 'creator_ip')) {
                $table->dropIndex(['creator_ip']);
                $table->dropColumn('creator_ip');
            }
        });
    }
};
