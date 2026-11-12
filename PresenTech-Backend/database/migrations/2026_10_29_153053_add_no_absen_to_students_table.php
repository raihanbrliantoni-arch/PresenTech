<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nomor absen permanen per siswa. Boleh kosong: laporan akan memakai
     * urutan nama (A-Z) sebagai cadangan agar data lama tetap terbaca.
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->unsignedSmallInteger('no_absen')->nullable()->after('name')->index();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex(['no_absen']);
            $table->dropColumn('no_absen');
        });
    }
};
