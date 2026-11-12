<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Token klaim: dikembalikan sekali saat pengajuan, disimpan sebagai
        // hash SHA-256, dan wajib disertakan saat pengajuan selesai (complete)
        // oleh pemilik — jalur ini tidak lagi memerlukan sesi login.
        Schema::table('password_reset_requests', function (Blueprint $table) {
            $table->string('claim_token', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('password_reset_requests', function (Blueprint $table) {
            $table->dropColumn('claim_token');
        });
    }
};
