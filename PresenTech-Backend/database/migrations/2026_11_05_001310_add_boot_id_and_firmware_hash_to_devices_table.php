<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * boot_id berubah di setiap boot sehingga server bisa membedakan
     * "alat restart sendiri" dari heartbeat biasa, dan restart_requested_at
     * tidak pernah nyangkut di alat yang sudah menyala ulang.
     *
     * firmware_hash diisi saat OTA dipasang agar alat bisa melaporkan
     * apakah firmware di flash sama dengan versi yang dikirim server.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('boot_id', 40)->nullable()->after('firmware_version');
            $table->timestamp('booted_at')->nullable()->after('boot_id');
            $table->char('firmware_hash', 32)->nullable()->after('booted_at');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['boot_id', 'booted_at', 'firmware_hash']);
        });
    }
};
