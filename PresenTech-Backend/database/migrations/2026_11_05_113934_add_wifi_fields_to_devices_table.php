<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ganti WiFi dari dashboard: Admin menyimpan SSID + password, lalu alat
     * mengambilnya pada heartbeat berikutnya. Password disimpan terenkripsi
     * (cast "encrypted" di model) dan dihapus setelah alat mengonfirmasi.
     *
     * wifi_token dipakai alat agar konfirmasi (ack) bisa diulang setelah
     * restart tanpa memicu restart terus-menerus bila ack pertama gagal.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('wifi_ssid', 64)->nullable()->after('ip_address');
            $table->text('wifi_password')->nullable()->after('wifi_ssid');
            $table->string('wifi_token', 32)->nullable()->after('wifi_password');
            $table->timestamp('wifi_requested_at')->nullable()->after('wifi_token');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['wifi_ssid', 'wifi_password', 'wifi_token', 'wifi_requested_at']);
        });
    }
};
