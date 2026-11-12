<?php

namespace App\Support;

use App\Models\SchoolClass;
use Illuminate\Support\Collection;

/**
 * Urutan baku kelas sekolah: tingkat X, XI, XII lebih dulu; di dalam tiap
 * tingkat baru jurusan RPL, DKV, TKJ, TRANSMISI; lalu nomor kelas. Dipakai
 * daftar kelas dan laporan absensi agar urutan tampilan selalu sama di web
 * maupun hasil export.
 */
final class ClassOrder
{
    /** @var array<string, int> */
    private const MAJOR_RANK = [
        'RPL' => 1,
        'DKV' => 2,
        'TKJ' => 3,
        'TRANSMISI' => 4,
    ];

    /** @var array<string, string> */
    private const MAJOR_ALIAS = [
        'REKAYASA PERANGKAT LUNAK' => 'RPL',
        'DESAIN KOMUNIKASI VISUAL' => 'DKV',
        'TEKNIK KOMPUTER DAN JARINGAN' => 'TKJ',
        'TEKNIK TRANSMISI' => 'TRANSMISI',
    ];

    public static function gradeRank(string $name): int
    {
        $name = strtoupper(trim($name));

        return match (true) {
            str_starts_with($name, 'XII') => 3,
            str_starts_with($name, 'XI') => 2,
            str_starts_with($name, 'X') => 1,
            default => 9,
        };
    }

    public static function majorRank(?string $major, string $name): int
    {
        $code = self::majorCode($major, $name);

        return self::MAJOR_RANK[$code] ?? 9;
    }

    public static function number(string $name): int
    {
        preg_match_all('/\d+/', $name, $matches);

        return $matches[0] === [] ? 1 : (int) end($matches[0]);
    }

    public static function majorCode(?string $major, string $name): string
    {
        $candidate = strtoupper(trim((string) $major));

        if ($candidate !== '') {
            $candidate = self::MAJOR_ALIAS[$candidate] ?? $candidate;

            if (isset(self::MAJOR_RANK[$candidate])) {
                return $candidate;
            }
        }

        $parts = preg_split('/\s+/', strtoupper(trim($name))) ?: [];
        $code = $parts[1] ?? '';

        return self::MAJOR_ALIAS[$code] ?? $code;
    }

    public static function key(?string $major, string $name): string
    {
        return sprintf(
            '%d-%d-%03d-%s',
            self::gradeRank($name),
            self::majorRank($major, $name),
            self::number($name),
            $name,
        );
    }

    /**
     * @param  Collection<int, SchoolClass>  $classes
     * @return Collection<int, SchoolClass>
     */
    public static function sort(Collection $classes): Collection
    {
        return $classes->sortBy(fn (SchoolClass $class) => self::key($class->major, $class->name))->values();
    }
}
