<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pengaturan koneksi MQTT yang bisa diubah dari web. Tanpa
     * mqtt_enabled = true, alat tetap memakai HTTP + UDP discovery.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('mqtt_enabled')->default(false)->after('semester');
            $table->boolean('mqtt_tls')->default(false)->after('mqtt_port');
            $table->string('mqtt_username', 150)->nullable()->after('mqtt_tls');
            $table->string('mqtt_password', 255)->nullable()->after('mqtt_username');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['mqtt_enabled', 'mqtt_tls', 'mqtt_username', 'mqtt_password']);
        });
    }
};
