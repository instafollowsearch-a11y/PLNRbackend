<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_sessions', function (Blueprint $table) {
            $table->string('generation_status')->nullable()->after('status');
            $table->text('generation_error')->nullable()->after('generation_status');
        });
    }

    public function down(): void
    {
        Schema::table('plan_sessions', function (Blueprint $table) {
            $table->dropColumn(['generation_status', 'generation_error']);
        });
    }
};
