<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nama yang tampil di layar alat (LCD): nama panjang otomatis dipendekkan
     * di server supaya alat cukup menerima teks jadi. Warna tema disimpan
     * per perangkat agar tiap titik absensi bisa dibedakan.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('display_name', 32)->nullable()->after('name');
            $table->string('school_label', 32)->nullable()->after('display_name');
            $table->string('theme_color', 7)->default('#1f6feb')->after('school_label');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['display_name', 'school_label', 'theme_color']);
        });
    }
};
