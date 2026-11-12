<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Status OTA per perangkat: firmware tujuan, waktu permintaan, dan
     * laporan terakhir dari alat (pending/downloading/success/failed).
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->foreignId('ota_firmware_id')->nullable()->after('firmware_version')
                ->constrained('device_firmwares')->nullOnDelete();
            $table->timestamp('ota_requested_at')->nullable()->after('ota_firmware_id');
            $table->string('ota_status', 20)->nullable()->after('ota_requested_at');
            $table->timestamp('ota_reported_at')->nullable()->after('ota_status');
            $table->string('ota_message', 255)->nullable()->after('ota_reported_at');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ota_firmware_id');
            $table->dropColumn(['ota_requested_at', 'ota_status', 'ota_reported_at', 'ota_message']);
        });
    }
};
