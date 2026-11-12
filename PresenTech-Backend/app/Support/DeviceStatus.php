<?php

namespace App\Support;

use App\Models\Device;

/**
 * Status perangkat yang dihitung saat dibaca (live) dari last_seen_at,
 * bukan dari kolom status yang disimpan scheduler. Dashboard jadi tidak
 * perlu menunggu perintah devices:mark-offline agar tampilan akurat.
 */
final class DeviceStatus
{
    public const OFFLINE_AFTER_SECONDS = 90;

    public static function live(Device $device): string
    {
        if ($device->status === 'Maintenance') {
            return 'Maintenance';
        }

        $threshold = now()->subSeconds(self::OFFLINE_AFTER_SECONDS);

        return $device->last_seen_at !== null && $device->last_seen_at->gte($threshold)
            ? 'Online'
            : 'Offline';
    }
}
