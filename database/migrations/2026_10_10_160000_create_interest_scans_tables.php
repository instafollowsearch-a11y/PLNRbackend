<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interest_scans', function (Blueprint $table) {
            $table->id();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('users_checked')->default(0);
            $table->unsignedInteger('matches_kept')->default(0);
            $table->unsignedInteger('users_skipped')->default(0);
            $table->timestamps();
        });

        Schema::create('interest_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interest_scan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('matched_interest');
            $table->unsignedTinyInteger('score');
            $table->timestamps();

            $table->index(['user_id', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interest_matches');
        Schema::dropIfExists('interest_scans');
    }
};
