<?php

use App\Models\Device;

$device = Device::find(1);

if ($device === null) {
    echo 'Perangkat ESP32-RPL-01 tidak ditemukan di database.'.PHP_EOL;

    return;
}

$lastSeen = $device->last_seen_at?->toDateTimeString();

echo "last_seen tersimpan: {$lastSeen}".PHP_EOL;
echo 'Menunggu heartbeat (titik = '.now()->format('H:i:s').', tiap 3 detik)'.PHP_EOL;

$dots = 0;

while (true) {
    $device->refresh();
    $current = $device->last_seen_at?->toDateTimeString();

    if ($current !== $lastSeen) {
        echo PHP_EOL.'>>> ALAT TERHUBUNG <<< '.now()->format('H:i:s')
            ." | last_seen={$current} | status={$device->status} | ip={$device->ip_address}".PHP_EOL;
        $lastSeen = $current;
    } else {
        echo '.';
        $dots++;

        if ($dots % 20 === 0) {
            echo ' '.now()->format('H:i:s').' | '.$device->status.' | terakhir '.$current.PHP_EOL;
        }
    }

    sleep(3);
}
