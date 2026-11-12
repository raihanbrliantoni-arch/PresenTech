<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registry firmware ESP32 yang bisa dikirim ke perangkat lewat OTA.
     * File biner disimpan di disk "local" (storage/app/private/firmware).
     */
    public function up(): void
    {
        Schema::create('device_firmwares', function (Blueprint $table) {
            $table->id();
            $table->string('version', 50);
            $table->string('original_name');
            $table->string('path');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('checksum', 64);
            $table->string('notes', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('version');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_firmwares');
    }
};
