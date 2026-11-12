<?php

namespace App\Support;

use App\Models\Device;
use App\Models\DeviceFirmware;
use App\Models\Setting;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Potongan data yang selalu dibutuhkan perangkat ESP32 pada setiap
 * komunikasi: jam absensi hari ini, status jam pulang, dan waktu server.
 *
 * Nilai yang sama dipakai jalur HTTP (heartbeat/absensi) maupun bridge MQTT
 * supaya LCD alat menampilkan perilaku identik pada kedua transport.
 */
final class DeviceRuntime
{
    /**
     * @return array{
     *     server_time: string,
     *     server_date: string,
     *     entry_time: string|null,
     *     late_time: string|null,
     *     home_time: string,
     *     check_out_active: bool,
     *     schedule_revision: int,
     *     timezone: string
     * }
     */
    public static function attendanceWindow(CarbonInterface|string|null $at = null): array
    {
        $moment = $at === null ? now() : Carbon::parse($at);

        $settings = Setting::current();
        $window = $settings->timesForDate($moment->toDateString());
        $homeTime = $window['home_time'];

        return [
            'server_time' => $moment->toIso8601String(),
            'server_date' => $moment->toDateString(),
            'entry_time' => $window['entry_time'],
            'late_time' => $window['late_time'],
            'home_time' => $homeTime,
            'check_out_active' => $moment->format('H:i') >= $homeTime,
            'schedule_revision' => (int) ($settings->schedule_revision ?? 1),
            'timezone' => $settings->timezone ?: config('app.timezone'),
        ];
    }

    /**
     * Ringkasan versi firmware untuk dashboard dan petunjuk pembaruan alat.
     *
     * @return array{current: string|null, latest: string|null, update_available: bool}
     */
    public static function firmwareStatus(Device $device): array
    {
        $latest = DeviceFirmware::latestActive();
        $current = $device->firmware_version;

        return [
            'current' => $current,
            'latest' => $latest?->version,
            'update_available' => $latest !== null
                && ($current === null || $current === '' || version_compare((string) $current, $latest->version, '<')),
        ];
    }

    /**
     * Data tampilan untuk layar alat: nama yang sudah dipendekkan, label
     * sekolah, warna tema, dan jam. Alat tinggal menggambar — aturan
     * pemendekan nama panjang ada di DeviceDisplay supaya konsisten.
     *
     * `name_short` dipakai bila layar sempit (≤ 16 kolom),
     * `name_initials` dipakai bila nama lengkap pun tidak muat.
     *
     * @return array{
     *     device_name: string,
     *     device_name_short: string,
     *     device_name_initials: string,
     *     school_label: string,
     *     theme_color: string,
     *     date_label: string,
     *     greeting: string,
     *     clock: string,
     *     day_label: string,
     *     state: string
     * }
     */
    public static function display(Device $device, CarbonInterface|string|null $at = null): array
    {
        $moment = $at === null ? now() : Carbon::parse($at);
        $window = self::attendanceWindow($moment);
        $hour = (int) $moment->format('G');

        $state = match (true) {
            $window['check_out_active'] => 'pulang',
            $moment->format('H:i') >= substr((string) ($window['late_time'] ?? '07:15'), 0, 5) => 'terlambat',
            default => 'masuk',
        };

        return [
            'device_name' => $device->displayLabel(),
            'device_name_short' => DeviceDisplay::shorten($device->displayLabel(), 16, 'Alat'),
            'device_name_initials' => DeviceDisplay::initialsName($device->displayLabel(), 16),
            'school_label' => $device->schoolLabel(),
            'theme_color' => strtolower((string) ($device->theme_color ?: '#1f6feb')),
            'date_label' => $moment->locale('id')->translatedFormat('D, j M Y'),
            'greeting' => match (true) {
                $hour < 11 => 'Selamat pagi',
                $hour < 15 => 'Selamat siang',
                $hour < 18 => 'Selamat sore',
                default => 'Selamat malam',
            },
            'clock' => $moment->format('H:i:s'),
            'day_label' => $moment->locale('id')->translatedFormat('l'),
            'state' => $state,
        ];
    }
}
