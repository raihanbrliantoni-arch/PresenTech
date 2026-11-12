<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->string('client_event_id', 64)->nullable()->after('uid_snapshot');
            $table->unique(['device_id', 'client_event_id']);
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropUnique(['device_id', 'client_event_id']);
            $table->dropColumn('client_event_id');
        });
    }
};
