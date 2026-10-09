<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_shares', function (Blueprint $table): void {
            $table->string('invitee_phone', 20)->nullable()->after('invitee_email');
        });
    }

    public function down(): void
    {
        Schema::table('plan_shares', function (Blueprint $table): void {
            $table->dropColumn('invitee_phone');
        });
    }
};
