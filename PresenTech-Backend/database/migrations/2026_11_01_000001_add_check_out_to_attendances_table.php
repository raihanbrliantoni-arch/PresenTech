<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tap pulang: satu baris absensi per hari menyimpan jam masuk dan jam
     * pulang. early_leave menandai siswa pulang lebih awal (mis. izin).
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->time('check_out_time')->nullable()->after('attendance_time');
            $table->boolean('early_leave')->default(false)->after('check_out_time');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['check_out_time', 'early_leave']);
        });
    }
};
