<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_sessions', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->after('id');
            $table->json('refinement_messages')->nullable()->after('answers');
        });

        foreach (\DB::table('plan_sessions')->whereNull('uuid')->cursor() as $session) {
            \DB::table('plan_sessions')
                ->where('id', $session->id)
                ->update(['uuid' => (string) Str::uuid()]);
        }

        Schema::table('plan_sessions', function (Blueprint $table): void {
            $table->unique('uuid');
        });
    }

    public function down(): void
    {
        Schema::table('plan_sessions', function (Blueprint $table): void {
            $table->dropIndex(['uuid']);
            $table->dropColumn(['uuid', 'refinement_messages']);
        });
    }
};
