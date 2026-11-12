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
        Schema::table('settings', function (Blueprint $table) {
            $table->string('mqtt_broker', 255)->default('mqtt://localhost')->after('semester');
            $table->string('mqtt_port', 10)->default('1883')->after('mqtt_broker');
            $table->string('mqtt_topic', 150)->default('presentech/attendance')->after('mqtt_port');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['mqtt_broker', 'mqtt_port', 'mqtt_topic']);
        });
    }
};
