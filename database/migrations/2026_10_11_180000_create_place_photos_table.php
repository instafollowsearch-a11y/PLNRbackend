<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_photos', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->text('photo_name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_photos');
    }
};
