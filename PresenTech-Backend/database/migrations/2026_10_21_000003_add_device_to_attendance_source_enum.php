<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tambah nilai enum 'Device' pada kolom source tabel attendances.
// AttendanceController::store() menentukan sumber: kartu = RFID,
// device (X-Device-Key) = Device, input manual = Manual.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->enum('source', ['RFID', 'Device', 'Manual', 'Import', 'Correction'])->default('RFID')->change();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->enum('source', ['RFID', 'Manual', 'Import', 'Correction'])->default('RFID')->change();
        });
    }
};
