<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jam masuk/terlambat/pulang khusus per hari (Senin–Minggu).
     * Nilai null berarti hari tersebut memakai jam umum pada kolom
     * entry_time, late_time, dan home_time.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->json('day_times')->nullable()->after('home_time');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('day_times');
        });
    }
};
