<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\Notification;
use App\Models\SystemLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Kirim notifikasi in-app ke Admin ketika perangkat sudah offline lebih dari
 * batas waktu. Notifikasi yang sama tidak diulang sampai jendela jeda
 * (default 6 jam) terlewati, dan direset otomatis saat alat kembali online.
 */
#[Signature('devices:alert-offline {--minutes=10 : Lama offline sebelum diberi notifikasi} {--repeat-hours=6 : Jeda minimal sebelum notifikasi ulang}')]
#[Description('Beri notifikasi Admin untuk perangkat yang offline terlalu lama')]
class AlertOfflineDevices extends Command
{
    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $repeatHours = max(1, (int) $this->option('repeat-hours'));
        $cutoff = now()->subMinutes($minutes);

        $devices = Device::query()
            ->where('status', 'Offline')
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '<', $cutoff)
            ->where(function ($query) use ($repeatHours) {
                $query->whereNull('offline_alerted_at')
                    ->orWhere('offline_alerted_at', '<', now()->subHours($repeatHours));
            })
            ->orderBy('name')
            ->get();

        foreach ($devices as $device) {
            $message = $device->name.' ('.$device->device_id.') offline. Terakhir terlihat '
                .$device->last_seen_at->format('d/m/Y H:i').'.';

            Notification::create([
                'recipient_role' => 'admin',
                'type' => 'device',
                'title' => 'Perangkat Offline',
                'message' => $message,
                'icon' => 'wifi-off',
                'expires_at' => now()->addDays(2),
            ]);

            SystemLog::create([
                'device_id' => $device->id,
                'type' => 'device',
                'action' => 'device.offline_alert',
                'message' => $message,
            ]);

            $device->update(['offline_alerted_at' => now()]);
        }

        $this->info($devices->count().' notifikasi perangkat offline dibuat.');

        return self::SUCCESS;
    }
}
