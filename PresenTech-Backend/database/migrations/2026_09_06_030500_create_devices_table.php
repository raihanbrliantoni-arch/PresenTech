<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_id', 100)->unique();
            $table->string('name', 150);
            $table->string('location', 150)->nullable();
            $table->string('mac_address', 32)->nullable()->unique();
            $table->string('ip_address', 45)->nullable();
            $table->string('firmware_version', 50)->nullable();
            $table->enum('connection_type', ['WiFi', 'Ethernet', 'Unknown'])->default('WiFi');
            $table->enum('status', ['Online', 'Offline', 'Maintenance'])->default('Offline')->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('last_restart_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
