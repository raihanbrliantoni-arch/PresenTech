<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->timestamp('scan_requested_at')->nullable()->after('restart_requested_at');
            $table->string('scanned_uid', 64)->nullable()->after('scan_requested_at');
            $table->timestamp('scanned_at')->nullable()->after('scanned_uid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['scan_requested_at', 'scanned_uid', 'scanned_at']);
        });
    }
};
