<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Hari libur sekolah (nasional, sekolah, atau libur semester).
 *
 * Tanggal yang terdaftar di sini dipakai oleh penandaan Alpa otomatis dan
 * laporan harian agar siswa tidak dianggap tidak hadir saat sekolah libur.
 */
class Holiday extends Model
{
    public const TYPES = ['nasional', 'sekolah', 'semester'];

    public const TYPE_LABELS = [
        'nasional' => 'Libur Nasional',
        'sekolah' => 'Libur Sekolah',
        'semester' => 'Libur Semester',
    ];

    protected $fillable = ['date', 'name', 'type', 'description'];

    protected $casts = [
        'date' => 'date:Y-m-d',
    ];

    public function scopeOnDate(Builder $query, Carbon|string $date): Builder
    {
        return $query->whereDate('date', $date instanceof Carbon ? $date->toDateString() : $date);
    }

    public static function forDate(Carbon|string $date): ?self
    {
        return self::query()->onDate($date)->first();
    }

    public static function isHoliday(Carbon|string $date): bool
    {
        return self::query()->onDate($date)->exists();
    }
}
