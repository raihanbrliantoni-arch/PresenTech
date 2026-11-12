<?php

namespace App\Support;

use App\Models\Device;
use App\Models\DeviceFirmware;
use App\Models\SystemLog;
use Illuminate\Support\Facades\DB;

/**
 * Alur OTA firmware per perangkat. Dipakai bersama oleh jalur HTTP
 * (heartbeat + ack) dan bridge MQTT supaya status di dashboard selalu
 * mencerminkan laporan terakhir dari alat.
 */
final class DeviceOta
{
    /**
     * Firmware yang menunggu dipasang, bila permintaan masih berlaku.
     */
    public static function target(Device $device): ?DeviceFirmware
    {
        if ($device->ota_requested_at === null || $device->ota_firmware_id === null) {
            return null;
        }

        $firmware = $device->otaFirmware;

        if ($firmware === null || ! $firmware->is_active || ! $firmware->hasFile()) {
            return null;
        }

        return $firmware;
    }

    /**
     * Payload untuk alat: versi, URL unduhan bertanda tangan, ukuran, dan
     * checksum SHA-256 agar alat bisa menolak file yang rusak.
     *
     * @return array{version: string, url: string, size: int, sha256: string, requested_at: string}|null
     */
    public static function pending(Device $device): ?array
    {
        $firmware = self::target($device);

        if ($firmware === null) {
            return null;
        }

        return $firmware->toDevicePayload() + [
            'requested_at' => $device->ota_requested_at?->toIso8601String(),
        ];
    }

    public static function request(Device $device, DeviceFirmware $firmware): Device
    {
        $device->update([
            'ota_firmware_id' => $firmware->id,
            'ota_requested_at' => now(),
            'ota_status' => 'pending',
            'ota_reported_at' => null,
            'ota_message' => null,
        ]);

        return $device->fresh();
    }

    public static function cancel(Device $device): Device
    {
        $device->update([
            'ota_firmware_id' => null,
            'ota_requested_at' => null,
            'ota_status' => 'canceled',
            'ota_reported_at' => now(),
            'ota_message' => 'Dibatalkan dari dashboard.',
        ]);

        return $device->fresh();
    }

    /**
     * Terapkan laporan dari alat. Status yang tidak dikenal dianggap masih
     * berjalan agar permintaan tidak hilang karena payload lama.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function applyReport(Device $device, array $payload): Device
    {
        $rawStatus = strtolower(trim((string) ($payload['status'] ?? '')));
        $status = match (true) {
            in_array($rawStatus, ['success', 'succeeded', 'done', 'ok', 'installed', 'updated'], true) => 'success',
            in_array($rawStatus, ['failed', 'error', 'aborted', 'checksum_mismatch'], true) => 'failed',
            in_array($rawStatus, ['downloading', 'progress', 'started', 'rebooting'], true) => 'downloading',
            default => 'pending',
        };

        $version = isset($payload['version']) && is_string($payload['version']) && $payload['version'] !== ''
            ? substr($payload['version'], 0, 50)
            : null;

        $message = isset($payload['message']) && is_string($payload['message']) && $payload['message'] !== ''
            ? substr($payload['message'], 0, 255)
            : null;

        return DB::transaction(function () use ($device, $status, $version, $message): Device {
            $target = $device->otaFirmware;
            $update = [
                'ota_status' => $status,
                'ota_reported_at' => now(),
                'ota_message' => $message,
            ];

            if ($status === 'success') {
                $update['firmware_version'] = $version ?? $target?->version ?? $device->firmware_version;
                $update['ota_requested_at'] = null;
                $update['ota_firmware_id'] = null;
            }

            if ($status === 'failed') {
                // Permintaan dihentikan supaya alat tidak mencoba terus-menerus;
                // Admin bisa mengirim ulang setelah masalahnya diperbaiki.
                $update['ota_requested_at'] = null;
                $update['ota_firmware_id'] = null;
            }

            $device->update($update);

            if ($status === 'success' || $status === 'failed') {
                SystemLog::create([
                    'user_id' => null,
                    'device_id' => $device->id,
                    'type' => 'device',
                    'action' => $status === 'success' ? 'device.ota_success' : 'device.ota_failed',
                    'message' => $status === 'success'
                        ? 'Firmware OTA terpasang: '.($update['firmware_version'] ?? '-')
                        : 'Firmware OTA gagal: '.($message ?: 'tanpa keterangan'),
                    'ip_address' => null,
                    'metadata' => [
                        'device_id' => $device->id,
                        'version' => $update['firmware_version'] ?? null,
                        'status' => $status,
                    ],
                ]);
            }

            return $device->fresh();
        });
    }
}
