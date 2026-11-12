<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jam pulang: tap kedua pada hari yang sama baru dicatat sebagai
     * pulang setelah jam ini, supaya tap ganda pagi tidak dianggap pulang.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('home_time', 5)->default('14:00')->after('late_time');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('home_time');
        });
    }
};
