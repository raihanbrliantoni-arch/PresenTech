<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Holiday;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Membersihkan baris Alpa otomatis yang sudah terlanjur dibuat pada tanggal
 * yang kemudian ditetapkan sebagai hari libur. Hanya baris auto_alpa yang
 * dihapus; absensi manual/RFID tidak pernah disentuh.
 */
#[Signature('attendance:clear-holiday-alpa {--date= : Tanggal Y-m-d tertentu (harus terdaftar libur)} {--dry-run : Tampilkan jumlah tanpa menghapus}')]
#[Description('Hapus Alpa otomatis pada tanggal yang terdaftar sebagai hari libur')]
class ClearHolidayAlpa extends Command
{
    public function handle(): int
    {
        $date = $this->option('date');

        $holidays = Holiday::query()
            ->when($date !== null && $date !== '', fn ($query) => $query->onDate((string) $date))
            ->orderBy('date')
            ->get();

        if ($holidays->isEmpty()) {
            $this->info($date ? "Tanggal {$date} tidak terdaftar sebagai hari libur." : 'Belum ada hari libur yang terdaftar.');

            return self::SUCCESS;
        }

        $dates = $holidays->map(fn (Holiday $holiday) => $holiday->date->toDateString())->unique()->values()->all();

        $query = Attendance::query()
            ->where('auto_alpa', true)
            ->whereIn('attendance_date', $dates);

        if ($this->option('dry-run')) {
            $this->info('Akan dihapus: '.$query->count().' baris Alpa otomatis pada '.count($dates).' tanggal libur.');

            return self::SUCCESS;
        }

        $deleted = $query->delete();

        $this->info("{$deleted} baris Alpa otomatis dihapus pada tanggal libur: ".implode(', ', $dates).'.');

        return self::SUCCESS;
    }
}
