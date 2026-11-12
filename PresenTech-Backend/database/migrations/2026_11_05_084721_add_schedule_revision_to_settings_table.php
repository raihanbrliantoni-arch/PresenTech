<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nomor revisi jadwal absensi. Naik setiap jam masuk/terlambat/pulang
     * berubah, lalu dikirim ke alat lewat heartbeat. Firmware memakai angka
     * ini untuk berbunyi sekali saja saat menerima jadwal baru.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedInteger('schedule_revision')->default(1)->after('day_times');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('schedule_revision');
        });
    }
};
