<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weekend_recommendations', function (Blueprint $table): void {
            $table->json('saturday_plan')->nullable()->after('items');
        });
    }

    public function down(): void
    {
        Schema::table('weekend_recommendations', function (Blueprint $table): void {
            $table->dropColumn('saturday_plan');
        });
    }
};
