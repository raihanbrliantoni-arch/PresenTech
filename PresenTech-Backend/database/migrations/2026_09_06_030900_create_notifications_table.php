<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('recipient_role', ['admin', 'guru', 'siswa'])->nullable()->index();
            $table->string('recipient_class', 50)->nullable()->index();
            $table->string('recipient_nis', 30)->nullable()->index();
            $table->string('type', 50)->default('system')->index();
            $table->string('title', 200);
            $table->text('message');
            $table->string('icon', 50)->nullable();
            $table->boolean('is_read')->default(false)->index();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->index(['recipient_user_id', 'is_read', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
