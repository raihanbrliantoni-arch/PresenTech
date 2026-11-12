<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Setting extends Model
{
    /**
     * Kunci hari kerja yang dipakai pada kolom day_times (huruf kecil,
     * mengikuti nama hari Inggris dari Carbon::format('l')). Sabtu dan
     * Minggu tidak disimpan dan otomatis memakai jam umum.
     */
    public const DAY_KEYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

    /**
     * Password MQTT tidak pernah dikirim ke client. Admin hanya menerima
     * penanda apakah password sudah tersimpan.
     */
    protected $hidden = [
        'mqtt_password',
    ];

    protected $fillable = [
        'entry_time',
        'late_time',
        'home_time',
        'day_times',
        'schedule_revision',
        'timezone',
        'academic_year',
        'semester',
        'mqtt_broker',
        'mqtt_port',
        'mqtt_tls',
        'mqtt_username',
        'mqtt_password',
        'mqtt_enabled',
        'mqtt_topic',
    ];

    protected $casts = [
        'day_times' => 'array',
        'schedule_revision' => 'integer',
    ];

    /**
     * Dipakai dashboard agar field password tidak perlu diisi ulang saat
     * menyimpan pengaturan lain.
     */
    public function getMqttPasswordConfiguredAttribute(): bool
    {
        return filled($this->mqtt_password);
    }

    /**
     * Pengaturan sekolah disimpan sebagai satu baris; dibuat otomatis
     * dengan nilai default bila belum ada.
     */
    public static function current(): self
    {
        return self::query()->firstOrCreate([], [
            'entry_time' => '07:00',
            'late_time' => '07:15',
            'home_time' => '14:00',
            'schedule_revision' => 1,
            'timezone' => 'Asia/Jakarta',
            'academic_year' => '2026/2027',
            'semester' => 'Ganjil',
            'mqtt_broker' => 'mqtt://localhost',
            'mqtt_port' => '1883',
            'mqtt_enabled' => false,
            'mqtt_tls' => false,
            'mqtt_topic' => 'presentech/attendance',
        ]);
    }

    /**
     * Jam absensi efektif untuk sebuah tanggal. Jam khusus hari tersebut
     * dipakai bila diisi; selain itu memakai jam umum pada pengaturan.
     *
     * @return array{entry_time: string, late_time: string, home_time: string}
     */
    public function timesForDate(CarbonInterface|string|null $date = null): array
    {
        $moment = match (true) {
            $date === null => Carbon::now(),
            $date instanceof CarbonInterface => $date,
            default => Carbon::parse($date),
        };

        $dayTimes = is_array($this->day_times) ? $this->day_times : [];
        $dayKey = strtolower($moment->format('l'));
        $override = in_array($dayKey, self::DAY_KEYS, true) ? ($dayTimes[$dayKey] ?? []) : [];
        $override = is_array($override) ? $override : [];

        $entry = ($override['entry_time'] ?? null) ?: ($this->entry_time ?: '');
        $late = ($override['late_time'] ?? null) ?: ($this->late_time ?: '');
        $home = ($override['home_time'] ?? null) ?: ($this->home_time ?: '14:00');

        return [
            'entry_time' => substr((string) $entry, 0, 5),
            'late_time' => substr((string) $late, 0, 5),
            'home_time' => substr((string) $home, 0, 5),
        ];
    }
}
