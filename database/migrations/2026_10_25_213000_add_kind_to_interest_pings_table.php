<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * For environments that already ran the original interest_pings migration
 * before `kind` existed. Fresh installs get `kind` from the create migration
 * and this no-ops when the column is already present.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('interest_pings')) {
            return;
        }

        if (Schema::hasColumn('interest_pings', 'kind')) {
            return;
        }

        Schema::table('interest_pings', function (Blueprint $table) {
            $table->string('kind', 32)->default('morning_of')->after('event_id');
        });

        Schema::table('interest_pings', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'event_id']);
            $table->unique(['user_id', 'event_id', 'kind']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('interest_pings') || ! Schema::hasColumn('interest_pings', 'kind')) {
            return;
        }

        Schema::table('interest_pings', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'event_id', 'kind']);
        });

        Schema::table('interest_pings', function (Blueprint $table) {
            $table->dropColumn('kind');
            $table->unique(['user_id', 'event_id']);
        });
    }
};
