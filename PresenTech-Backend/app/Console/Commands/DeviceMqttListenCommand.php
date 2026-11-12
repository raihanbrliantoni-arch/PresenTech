<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\SystemLog;
use App\Support\DeviceAttendanceRecorder;
use App\Support\DeviceDisplay;
use App\Support\DeviceOta;
use App\Support\DeviceRuntime;
use App\Support\MqttDeviceBus;
use App\Support\RfidUid;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use PhpMqtt\Client\MqttClient;
use Throwable;

/**
 * Bridge MQTT → Laravel (mode server online).
 *
 * Perangkat mengirim heartbeat/absensi via MQTT; command ini meneruskan ke
 * database memakai logika yang sama dengan jalur HTTP (DeviceAttendanceRecorder,
 * scan session, restart request) lalu mem-publish hasil ke topik /result.
 *
 * Jalankan bersama server: php artisan devices:mqtt-listen
 */
#[Signature('devices:mqtt-listen')]
#[Description('Bridge MQTT: heartbeat, absensi, dan perintah perangkat ESP32')]
class DeviceMqttListenCommand extends Command
{
    private const SCAN_TTL_SECONDS = 60;

    public function handle(): int
    {
        if (! MqttDeviceBus::enabled()) {
            $this->error('MQTT belum aktif. Isi dan aktifkan Koneksi MQTT di menu Pengaturan (atau MQTT_HOST di .env). Alat memakai HTTP + discovery.');

            return self::FAILURE;
        }

        $client = MqttDeviceBus::makeClient();
        $prefix = MqttDeviceBus::connection()['prefix'];

        $client->registerMessageReceivedEventHandler(function (string $topic, string $message): void {
            try {
                $this->handleMessage($topic, $message);
            } catch (Throwable $exception) {
                report($exception);
            }
        });

        try {
            $client->connect(MqttDeviceBus::connectionSettings(), true);
            $client->subscribe($prefix.'/d/#', MqttClient::QOS_AT_MOST_ONCE);
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Gagal terhubung ke broker MQTT: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Bridge MQTT aktif: '.$client->getHost().':'.$client->getPort().' topik '.$prefix.'/d/#');

        // loop(true) = berjalan terus; Ctrl+C untuk berhenti.
        $client->loop(true);

        return self::SUCCESS;
    }

    private function handleMessage(string $topic, string $message): void
    {
        $prefix = MqttDeviceBus::connection()['prefix'];
        $expectedHead = $prefix.'/d/';

        if (! str_starts_with($topic, $expectedHead)) {
            return;
        }

        $rest = substr($topic, strlen($expectedHead));
        $parts = explode('/', $rest, 2);

        if (count($parts) !== 2 || strlen($parts[0]) !== 8) {
            return;
        }

        [$hash8, $event] = $parts;
        $device = $this->resolveDeviceByHash($hash8);

        if ($device === null) {
            $this->output->writeln("<comment>Pesan dari perangkat tak dikenal (hash {$hash8})</comment>");

            return;
        }

        $payload = json_decode($message, true) ?? [];

        match (true) {
            $event === 'heartbeat' => $this->handleHeartbeat($device, $payload),
            $event === 'attendance' => $this->handleAttendance($device, $payload),
            $event === 'scan-result' => $this->handleScanResult($device, $payload),
            $event === 'result' => $this->handleDeviceResult($device, $payload),
            default => null,
        };
    }

    /**
     * Pesan /result arah alat → server (mis. ack restart).
     */
    private function handleDeviceResult(Device $device, array $payload): void
    {
        match ($payload['kind'] ?? '') {
            'restart_ack' => $device->update(['restart_requested_at' => null]),
            'ota_ack' => DeviceOta::applyReport($device, $payload),
            default => null,
        };
    }

    /**
     * Perangkat dicari lewat 8 karakter pertama sha256(device_key) —
     * identitas tanpa pernah menyebarkan key mentah di jaringan.
     *
     * Perbandingan dibuat case-insensitive karena firmware lama mengirim
     * hash dengan huruf besar, sedangkan device_key disimpan huruf kecil.
     */
    private function resolveDeviceByHash(string $hash8): ?Device
    {
        if (strlen($hash8) !== 8 || ! ctype_xdigit($hash8)) {
            return null;
        }

        $prefix = strtolower($hash8);

        return Device::query()
            ->whereNotNull('device_key')
            ->whereRaw('LOWER(device_key) LIKE ?', [$prefix.'%'])
            ->first();
    }

    private function handleHeartbeat(Device $device, array $payload): void
    {
        $isMaintenance = $device->status === 'Maintenance';
        $wasOffline = ! $isMaintenance && $device->status !== 'Online';

        $device->update(array_merge(array_filter([
            'status' => $isMaintenance ? 'Maintenance' : 'Online',
            'last_seen_at' => now(),
            'ip_address' => isset($payload['ip_address']) && is_string($payload['ip_address'])
                && filter_var($payload['ip_address'], FILTER_VALIDATE_IP)
                ? $payload['ip_address'] : $device->ip_address,
            'firmware_version' => isset($payload['firmware_version']) && is_string($payload['firmware_version'])
                && $payload['firmware_version'] !== '' ? $payload['firmware_version'] : $device->firmware_version,
        ], fn ($value) => $value !== null), [
            // Alat sudah mengirim heartbeat, jadi alert offline berikutnya
            // boleh dibuat lagi bila alat kembali putus.
            'offline_alerted_at' => null,
        ]));

        if ($wasOffline) {
            SystemLog::create([
                'user_id' => null,
                'device_id' => $device->id,
                'type' => 'device',
                'action' => 'device.online',
                'message' => 'Perangkat kembali online (MQTT): '.$device->name,
                'ip_address' => null,
                'metadata' => ['device_id' => $device->id, 'via' => 'mqtt'],
            ]);
        }

        $device = $device->fresh();

        // Perintah yang tertunda dibagikan lewat MQTT saat alat kembali nongol.
        if ($device->restart_requested_at !== null) {
            MqttDeviceBus::publishCommand($device, 'restart');
        }

        $scanRequested = $device->scan_requested_at !== null
            && $device->scan_requested_at->getTimestamp() >= now()->getTimestamp() - self::SCAN_TTL_SECONDS;

        if ($scanRequested) {
            MqttDeviceBus::publishCommand($device, 'scan', [
                'expires_in' => max(1, self::SCAN_TTL_SECONDS - (now()->getTimestamp() - $device->scan_requested_at->getTimestamp())),
            ]);
        }

        // Sama seperti heartbeat HTTP: alat menerima jam absensi hari ini,
        // status jam pulang, dan detail OTA lewat topik /result.
        MqttDeviceBus::publishResult($device, array_merge(DeviceRuntime::attendanceWindow(), [
            'kind' => 'heartbeat',
            'restart_requested' => $device->restart_requested_at !== null,
            'scan_requested' => $scanRequested,
            'firmware' => DeviceRuntime::firmwareStatus($device),
            'ota' => DeviceOta::pending($device),
        ]));
    }

    private function handleAttendance(Device $device, array $payload): void
    {
        $result = app(DeviceAttendanceRecorder::class)->record($device, (string) ($payload['uid'] ?? ''));
        $body = $result['body'];
        $outcome = $result['outcome'];

        $this->output->writeln(now()->format('H:i:s')." Absensi ({$device->name}) outcome={$outcome}");

        $createdName = isset($body['attendance']) ? (string) ($body['attendance']->student?->name ?? 'Siswa') : '';

        SystemLog::create([
            'user_id' => null,
            'device_id' => $device->id,
            'type' => 'attendance',
            'action' => 'attendance.mqtt',
            'message' => match ($outcome) {
                'created' => 'Absensi via MQTT dicatat: '.$createdName,
                'checked_out' => 'Tap pulang via MQTT dicatat: '.$createdName,
                'duplicate' => 'Tap ganda via MQTT (sudah absen hari ini)',
                'maintenance' => 'Tap ditolak: alat maintenance',
                default => 'Tap via MQTT: kartu tidak terdaftar',
            },
            'ip_address' => null,
            'metadata' => ['device_id' => $device->id, 'outcome' => $outcome, 'uid' => (string) ($payload['uid'] ?? '')],
        ]);

        $resultPayload = array_merge(DeviceRuntime::attendanceWindow(), [
            'kind' => 'attendance',
            'outcome' => $outcome,
            'check_out' => null,
        ]);

        if ($outcome === 'created' || $outcome === 'checked_out') {
            $attendance = $body['attendance'];
            $isCheckOut = $outcome === 'checked_out';
            $eventTime = $isCheckOut
                ? ($attendance->check_out_time ?: $attendance->attendance_time)
                : $attendance->attendance_time;

            $resultPayload = array_merge($resultPayload, [
                'name' => $attendance->student?->name,
                'class_name' => $attendance->student?->schoolClass?->name,
                // Nama versi layar alat (sudah dipendekkan server) supaya
                // firmware tidak perlu memotong kata sendiri.
                'name_short' => $attendance->student
                    ? DeviceDisplay::shortPersonName($attendance->student->name, 16)
                    : null,
                'name_initials' => $attendance->student
                    ? DeviceDisplay::initialsName($attendance->student->name, 16)
                    : null,
                'class_name_short' => $attendance->student?->schoolClass?->name
                    ? DeviceDisplay::shorten($attendance->student->schoolClass->name, 16, '')
                    : null,
                // Tap pulang dilabeli "Pulang" agar LCD tidak menampilkan
                // "Hadir" untuk kejadian kedua di hari yang sama.
                'status_label' => $isCheckOut ? 'Pulang' : $attendance->status,
                'time' => substr((string) $eventTime, 0, 5),
                'check_out' => $attendance->check_out_time !== null
                    ? substr((string) $attendance->check_out_time, 0, 5)
                    : null,
            ]);
        }

        if ($outcome === 'duplicate') {
            $existing = $body['existing_attendance'] ?? null;
            $duplicateStudent = $existing?->student ?? ($body['student'] ?? null);

            $resultPayload = array_merge($resultPayload, [
                'name' => $duplicateStudent?->name,
                'name_short' => $duplicateStudent
                    ? DeviceDisplay::shortPersonName($duplicateStudent->name, 16)
                    : null,
                'name_initials' => $duplicateStudent
                    ? DeviceDisplay::initialsName($duplicateStudent->name, 16)
                    : null,
                'class_name_short' => $duplicateStudent?->schoolClass?->name
                    ? DeviceDisplay::shorten($duplicateStudent->schoolClass->name, 16, '')
                    : null,
                'time' => $existing
                    ? substr((string) ($existing->check_out_time ?: $existing->attendance_time), 0, 5)
                    : '',
                'check_out' => $existing?->check_out_time !== null
                    ? substr((string) $existing->check_out_time, 0, 5)
                    : null,
                'checked_out' => $existing?->check_out_time !== null,
            ]);
        }

        MqttDeviceBus::publishResult($device, $resultPayload);
    }

    private function handleScanResult(Device $device, array $payload): void
    {
        $uid = RfidUid::normalize((string) ($payload['uid'] ?? ''));

        $isRequested = $device->scan_requested_at !== null;
        $isActive = $isRequested
            && $device->scan_requested_at->getTimestamp() >= now()->getTimestamp() - self::SCAN_TTL_SECONDS;

        if ($uid === '' || ! $isActive) {
            if ($isRequested && ! $isActive) {
                $device->update(['scan_requested_at' => null]);
            }

            MqttDeviceBus::publishResult($device, [
                'kind' => 'scan',
                'outcome' => $uid === '' ? 'invalid' : 'expired',
            ]);

            return;
        }

        $device->update([
            'scan_requested_at' => null,
            'scanned_uid' => $uid,
            'scanned_at' => now(),
        ]);

        SystemLog::create([
            'user_id' => null,
            'device_id' => $device->id,
            'type' => 'device',
            'action' => 'device.scan_result',
            'message' => 'UID kartu dibaca (MQTT): '.$uid,
            'ip_address' => null,
            'metadata' => ['device_id' => $device->id, 'uid' => $uid, 'via' => 'mqtt'],
        ]);

        MqttDeviceBus::publishResult($device, ['kind' => 'scan', 'outcome' => 'completed']);
    }
}
