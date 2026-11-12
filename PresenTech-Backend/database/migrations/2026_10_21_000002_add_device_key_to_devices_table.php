<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Key perangkat (disimpan sebagai hash SHA-256) untuk autentikasi
        // heartbeat & pencatatan absensi dari ESP32 via header X-Device-Key.
        Schema::table('devices', function (Blueprint $table) {
            $table->string('device_key', 64)->nullable()->unique()->after('device_id');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('device_key');
        });
    }
};
