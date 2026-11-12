<?php

namespace Database\Seeders;

use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Database\Seeder;

/**
 * Struktur kelas baku PresenTech:
 * - RPL 4 kelas per tingkat
 * - DKV 2 kelas per tingkat
 * - TKJ 2 kelas per tingkat
 * - TRANSMISI 1 kelas per tingkat
 * Tingkat: X, XI, XII (total 27 kelas).
 *
 * Seeder bersifat idempoten: kelas yang sudah ada hanya diperbarui
 * nama/jurusannya, sehingga siswa dan wali kelas tidak hilang.
 */
class SchoolClassSeeder extends Seeder
{
    /** @var array<string, int> */
    private const MAJOR_COUNTS = [
        'RPL' => 4,
        'DKV' => 2,
        'TKJ' => 2,
        'TRANSMISI' => 1,
    ];

    /** @var array<string, string> */
    private const LEGACY_NAMES = [
        'X  RPL 2' => 'X RPL 2',
        'XI TKJ' => 'XI TKJ 1',
    ];

    public function run(): void
    {
        foreach (self::LEGACY_NAMES as $legacy => $canonical) {
            $legacyClass = SchoolClass::query()->where('name', $legacy)->first();

            if ($legacyClass === null) {
                continue;
            }

            $target = SchoolClass::query()->where('name', $canonical)->first();

            if ($target === null) {
                $legacyClass->update(['name' => $canonical]);

                continue;
            }

            // Nama baku sudah ada: pindahkan siswa dulu agar tidak ikut
            // terhapus oleh cascade, baru hapus kelas lama yang kosong.
            Student::query()
                ->where('class_id', $legacyClass->id)
                ->update(['class_id' => $target->id]);
            $legacyClass->delete();
        }

        foreach (['X', 'XI', 'XII'] as $grade) {
            foreach (self::MAJOR_COUNTS as $major => $count) {
                for ($number = 1; $number <= $count; $number++) {
                    SchoolClass::query()->updateOrCreate(
                        ['name' => "{$grade} {$major} {$number}"],
                        ['major' => $major],
                    );
                }
            }
        }

        SchoolClass::query()
            ->withCount('students')
            ->get()
            ->each(fn (SchoolClass $class) => $class->update(['student_count' => $class->students_count]));
    }
}
