<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Setting;
use App\Support\Audit;
use App\Support\DeviceStatus;
use App\Support\MqttDeviceBus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $settings = Setting::current()->append('mqtt_password_configured')->toArray();

        // Konfigurasi broker hanya diperlukan panel Admin. Siswa/Guru cukup
        // menerima jam absensi dan penanda password, bukan topik/broker MQTT.
        if ($request->user()?->role !== 'admin') {
            $settings = array_diff_key($settings, array_flip([
                'mqtt_broker',
                'mqtt_port',
                'mqtt_topic',
                'mqtt_username',
                'mqtt_enabled',
                'mqtt_tls',
            ]));
        }

        return response()->json([
            'success' => true,
            'settings' => $settings,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entry_time' => ['sometimes', 'date_format:H:i'],
            'late_time' => ['sometimes', 'date_format:H:i'],
            'home_time' => ['sometimes', 'date_format:H:i'],
            'day_times' => ['sometimes', 'array:'.implode(',', Setting::DAY_KEYS)],
            'day_times.*' => ['array'],
            'day_times.*.entry_time' => ['nullable', 'date_format:H:i'],
            'day_times.*.late_time' => ['nullable', 'date_format:H:i'],
            'day_times.*.home_time' => ['nullable', 'date_format:H:i'],
            'timezone' => ['sometimes', 'string', 'max:64', 'timezone'],
            'academic_year' => ['sometimes', 'string', 'max:20'],
            'semester' => ['sometimes', Rule::in(['Ganjil', 'Genap'])],
            'mqtt_broker' => ['sometimes', 'string', 'max:255'],
            'mqtt_port' => ['sometimes', 'string', 'max:10'],
            'mqtt_topic' => ['sometimes', 'string', 'max:150'],
            'mqtt_enabled' => ['sometimes', 'boolean'],
            'mqtt_tls' => ['sometimes', 'boolean'],
            'mqtt_username' => ['nullable', 'string', 'max:150'],
            'mqtt_password' => ['nullable', 'string', 'max:255'],
        ]);

        $settings = Setting::current();

        if (array_key_exists('day_times', $data)) {
            $data['day_times'] = $this->normalizeDayTimes($data['day_times']);
        }

        // Jam absensi hanya boleh berubah setelah Admin menekan "Simpan
        // Perubahan". Setelah tersimpan, perangkat diminta restart agar
        // membaca ulang jam masuk/pulang/terlambat dari heartbeat.
        $before = $settings->only(['entry_time', 'late_time', 'home_time', 'timezone']);
        $beforeDayTimes = $settings->day_times ?? [];
        $settings->update($data);
        $timesChanged = collect($before)->contains(
            fn ($value, string $key) => (string) $value !== (string) $settings->{$key}
        ) || $beforeDayTimes !== ($settings->day_times ?? []);

        /** @var array<int, array<string, mixed>> $syncedDevices */
        $syncedDevices = [];

        if ($timesChanged) {
            // Naikkan revisi jadwal supaya alat tahu jam benar-benar berubah
            // dan berbunyi sekali saat menerima revisi baru lewat heartbeat.
            $settings->forceFill([
                'schedule_revision' => (int) $settings->schedule_revision + 1,
            ])->save();

            // Tandai restart agar alat membaca ulang jam lewat heartbeat,
            // lalu dorong perintah restart instan bila MQTT aktif.
            Device::query()
                ->whereNotNull('device_key')
                ->get()
                ->each(function (Device $device) use (&$syncedDevices): void {
                    $device->forceFill(['restart_requested_at' => now()])->save();
                    MqttDeviceBus::publishCommand($device, 'restart');

                    $device = $device->fresh();

                    // Status untuk dialog Admin: apakah alat masih menunggu
                    // restart atau sudah mengonfirmasi jam terbaru.
                    $syncedDevices[] = [
                        'id' => $device->id,
                        'name' => $device->name,
                        'status' => DeviceStatus::live($device),
                        'restart_pending' => $device->restart_requested_at !== null,
                        'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                        'last_restart_at' => $device->last_restart_at?->toIso8601String(),
                    ];
                });
        }

        // Jangan simpan password MQTT ke log audit.
        $auditData = array_diff_key($data, ['mqtt_password' => true]);
        Audit::log($request, 'settings', 'settings.updated', 'Admin mengubah pengaturan sekolah', $auditData);

        $deviceSync = $timesChanged
            ? (empty($syncedDevices) ? 'no_devices' : 'restart_requested')
            : 'heartbeat';

        return response()->json([
            'success' => true,
            'message' => match ($deviceSync) {
                'restart_requested' => 'Pengaturan berhasil disimpan. Perangkat diminta restart agar memakai jam terbaru.',
                'no_devices' => 'Pengaturan berhasil disimpan. Belum ada perangkat terdaftar untuk disinkronkan.',
                default => 'Pengaturan berhasil disimpan.',
            },
            'device_sync' => $deviceSync,
            'devices' => $syncedDevices,
            'settings' => $settings->fresh()->append('mqtt_password_configured'),
        ]);
    }

    /**
     * Simpan hanya hari yang punya jam khusus, dengan bentuk yang sama
     * agar perbandingan sebelum/sesudah simpan stabil. Hari tanpa isian
     * tidak disimpan sehingga otomatis memakai jam umum.
     *
     * @param  array<string, array<string, string|null>>  $dayTimes
     * @return array<string, array{entry_time: string|null, late_time: string|null, home_time: string|null}>
     */
    private function normalizeDayTimes(array $dayTimes): array
    {
        $normalized = [];

        foreach (Setting::DAY_KEYS as $day) {
            $times = $dayTimes[$day] ?? [];
            $entry = $times['entry_time'] ?? null;
            $late = $times['late_time'] ?? null;
            $home = $times['home_time'] ?? null;

            if ($entry === null && $late === null && $home === null) {
                continue;
            }

            $normalized[$day] = [
                'entry_time' => $entry,
                'late_time' => $late,
                'home_time' => $home,
            ];
        }

        return $normalized;
    }
}
