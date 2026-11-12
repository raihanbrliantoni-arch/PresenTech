<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('entry_time', 5)->default('07:00');
            $table->string('late_time', 5)->default('07:15');
            $table->string('timezone', 64)->default('Asia/Jakarta');
            $table->string('academic_year', 20)->default('2026/2027');
            $table->string('semester', 20)->default('Ganjil');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
