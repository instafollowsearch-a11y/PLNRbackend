<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suggestions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_session_id')->constrained()->cascadeOnDelete();
            $table->json('payload');
            $table->timestamp('selected_at')->nullable();
            $table->timestamps();

            $table->index('plan_session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suggestions');
    }
};
