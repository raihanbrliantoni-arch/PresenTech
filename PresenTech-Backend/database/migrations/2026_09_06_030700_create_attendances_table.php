<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('rfid_card_id')->nullable()->constrained('rfid_cards')->nullOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained('schedules')->nullOnDelete();
            $table->date('attendance_date')->index();
            $table->time('attendance_time');
            $table->enum('status', ['Hadir', 'Terlambat', 'Izin', 'Sakit', 'Alpa', 'Dikoreksi'])->default('Hadir')->index();
            $table->enum('source', ['RFID', 'Device', 'Manual', 'Import', 'Correction'])->default('RFID');
            $table->string('uid_snapshot', 64)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'attendance_date']);
            $table->index(['attendance_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
