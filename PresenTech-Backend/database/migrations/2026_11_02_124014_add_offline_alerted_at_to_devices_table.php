<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Waktu notifikasi "perangkat offline" terakhir dikirim. Dipakai agar
     * Admin tidak dibanjiri notifikasi berulang untuk alat yang sama.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->timestamp('offline_alerted_at')->nullable()->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('offline_alerted_at');
        });
    }
};
