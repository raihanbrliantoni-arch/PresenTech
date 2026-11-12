<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penanda baris Alpa yang dibuat otomatis oleh sistem. Saat siswa
     * akhirnya tap RFID, baris ini diperbarui (bukan ditolak sebagai duplikat).
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->boolean('auto_alpa')->default(false)->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex(['auto_alpa']);
            $table->dropColumn('auto_alpa');
        });
    }
};
