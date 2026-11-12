<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('devices:mark-offline')->everyMinute();

// Cek berkala; perintah hanya menandai Alpa setelah "Batas Terlambat" di
// Pengaturan terlewati, sehingga perubahan jam langsung diikuti.
Schedule::command('attendance:mark-alpa')->everyFiveMinutes();

// Retensi log sistem: log lebih dari 30 hari dihapus otomatis setiap hari.
Schedule::command('logs:prune')->dailyAt('02:00');

// Token Sanctum yang sudah kedaluwarsa dibersihkan agar tidak menumpuk.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Penanda scheduler hidup. Panel Kesehatan Sistem membaca nilai ini dan
// memberi peringatan bila lebih dari 3 menit tidak diperbarui.
Schedule::command('system:heartbeat')->everyMinute();

// Notifikasi in-app ke Admin bila perangkat offline lebih dari 10 menit.
// Notifikasi tidak diulang untuk alat yang sama dalam 6 jam.
Schedule::command('devices:alert-offline')->everyFiveMinutes();

// Backup database harian + prune file lebih tua dari retensi (default 30 hari).
Schedule::command('system:backup')->dailyAt('01:30');
