<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itineraries', function (Blueprint $table): void {
            $table->foreignId('suggestion_id')
                ->nullable()
                ->after('plan_session_id')
                ->constrained('suggestions')
                ->nullOnDelete();
        });

        $selected = DB::table('suggestions')
            ->whereNotNull('selected_at')
            ->get(['id', 'plan_session_id']);

        foreach ($selected as $row) {
            DB::table('itineraries')
                ->where('plan_session_id', $row->plan_session_id)
                ->whereNull('suggestion_id')
                ->update(['suggestion_id' => $row->id]);
        }
    }

    public function down(): void
    {
        Schema::table('itineraries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('suggestion_id');
        });
    }
};
