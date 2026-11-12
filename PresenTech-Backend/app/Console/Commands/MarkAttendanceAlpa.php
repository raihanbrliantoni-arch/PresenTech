<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Holiday;
use App\Models\Setting;
use App\Models\Student;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Tandai siswa aktif yang belum punya catatan absensi sebagai Alpa setelah
 * batas terlambat (Pengaturan -> Batas Terlambat) terlewati.
 *
 * Sabtu/Minggu dan tanggal yang terdaftar di kalender hari libur dilewati,
 * sehingga siswa tidak dianggap Alpa saat sekolah libur.
 *
 * Dijalankan berkala oleh scheduler; perintah ini idempoten dan hanya
 * menandai sekali per siswa per tanggal. Bila siswa akhirnya tap RFID,
 * DeviceAttendanceRecorder akan memperbarui baris Alpa otomatis tersebut
 * menjadi Hadir/Terlambat (lihat kolom attendances.auto_alpa).
 */
#[Signature('attendance:mark-alpa {--date= : Tanggal format Y-m-d (default hari ini)} {--include-weekend : Ikut tandai Sabtu/Minggu} {--include-holiday : Tetap tandai walaupun tanggal terdaftar libur} {--force : Abaikan pengecekan batas terlambat}')]
#[Description('Tandai siswa tanpa absensi sebagai Alpa setelah batas terlambat')]
class MarkAttendanceAlpa extends Command
{
    public function handle(): int
    {
        $date = $this->option('date')
            ? Carbon::createFromFormat('Y-m-d', (string) $this->option('date'))->startOfDay()
            : now()->startOfDay();

        // Batas terlambat mengikuti jam hari tersebut (bila diatur per hari).
        $settings = Setting::current();
        $late = $this->normalizeTime($settings->timesForDate($date)['late_time']
            ?: config('presentech.attendance.late_threshold', '07:15'));

        $isToday = ! $this->option('date');

        if ($isToday && ! $this->option('force') && now()->lt(Carbon::parse($date->toDateString().' '.$late))) {
            $this->info("Belum melewati batas terlambat ({$late}). Alpa belum ditandai.");

            return self::SUCCESS;
        }

        if (! $this->option('include-weekend') && $date->isWeekend()) {
            $this->info($date->toDateString().' akhir pekan, tidak ada penandaan Alpa.');

            return self::SUCCESS;
        }

        if (! $this->option('include-holiday') && ($holiday = Holiday::forDate($date)) !== null) {
            $this->info($date->toDateString().' hari libur ('.$holiday->name.'), tidak ada penandaan Alpa.');

            return self::SUCCESS;
        }

        $marked = 0;
        $skipped = 0;

        Student::query()
            ->where('status', 'Aktif')
            ->orderBy('id')
            ->chunkById(500, function ($students) use ($date, $late, &$marked, &$skipped) {
                $existing = Attendance::query()
                    ->whereDate('attendance_date', $date->toDateString())
                    ->whereIn('student_id', $students->pluck('id'))
                    ->pluck('student_id')
                    ->all();

                $existing = array_flip($existing);

                foreach ($students as $student) {
                    if (isset($existing[$student->id])) {
                        $skipped++;

                        continue;
                    }

                    Attendance::create([
                        'student_id' => $student->id,
                        'attendance_date' => $date->toDateString(),
                        'attendance_time' => $late,
                        'status' => 'Alpa',
                        'source' => 'Manual',
                        'auto_alpa' => true,
                        'notes' => "Alpa otomatis: belum ada tap sampai batas terlambat {$late}",
                    ]);

                    $marked++;
                }
            });

        $this->info("Alpa {$date->toDateString()} (batas {$late}): {$marked} siswa ditandai, {$skipped} sudah punya catatan.");

        return self::SUCCESS;
    }

    private function normalizeTime(string $time): string
    {
        return strlen($time) === 5 ? $time.':00' : $time;
    }
}
