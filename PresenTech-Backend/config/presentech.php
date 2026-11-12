<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pengaturan Absensi PresenTech
    |--------------------------------------------------------------------------
    */

    'attendance' => [

        // Batas jam masuk default. Jika siswa tap setelah jam ini (dan tidak
        // ditemukan jadwal kelas yang relevan), status menjadi "Terlambat".
        'late_threshold' => env('PRESENTECH_LATE_THRESHOLD', '07:15'),

        // Jendela deteksi double tap (detik) pada tanggal yang sama.
        'duplicate_window' => env('PRESENTECH_DUPLICATE_WINDOW', 60),

        // Tap pulang baru diterima setelah jam pulang di Pengaturan, dan
        // minimal sekian menit setelah jam masuk (anti tap ganda).
        'check_out_min_gap_minutes' => (int) env('PRESENTECH_CHECK_OUT_MIN_GAP', 10),

        // Beberapa kartu berbeda yang ditap pada perangkat sama dalam rentang
        // ini ditandai "tap beruntun" untuk diverifikasi Guru/Admin.
        'buddy_tap_window_seconds' => (int) env('PRESENTECH_BUDDY_TAP_WINDOW', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | MQTT (mode server online)
    |--------------------------------------------------------------------------
    |
    | Bridge devices:mqtt-listen membaca pesan perangkat ESP32 lewat broker
    | MQTT. Kosongkan MQTT_HOST untuk mematikan mode MQTT — alat otomatis
    | memakai HTTP + UDP discovery (server laptop lokal).
    |
    */

    'mqtt' => [
        'host' => env('MQTT_HOST', ''),
        'port' => (int) env('MQTT_PORT', 8883),
        'tls' => (bool) env('MQTT_TLS', true),
        'username' => env('MQTT_USERNAME', ''),
        'password' => env('MQTT_PASSWORD', ''),
        'allow_self_signed' => (bool) env('MQTT_ALLOW_SELF_SIGNED', false),
        'topic_prefix' => env('MQTT_TOPIC_PREFIX', 'presentech'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retensi Log Sistem
    |--------------------------------------------------------------------------
    |
    | Log sistem yang lebih lama dari jumlah hari ini dihapus otomatis oleh
    | scheduler `logs:prune` supaya tabel tidak terus membesar.
    |
    */

    'logs' => [
        'retention_days' => (int) env('PRESENTECH_LOG_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup Database Otomatis
    |--------------------------------------------------------------------------
    |
    | Perintah `system:backup` menyimpan backup JSON ke disk "local"
    | (storage/app/private/backups) setiap hari dan menghapus file yang lebih
    | lama dari jumlah hari retensi ini.
    |
    */

    'backup' => [
        'retention_days' => (int) env('PRESENTECH_BACKUP_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | OTA Firmware Perangkat
    |--------------------------------------------------------------------------
    |
    | Perangkat mengunduh firmware dari URL bertanda tangan yang berlaku
    | selama signed_ttl_hours. Isi PRESENTECH_FIRMWARE_URL dengan alamat
    | server yang bisa dijangkau alat (mis. http://192.168.1.10:8000) bila
    | APP_URL memakai "localhost" — kalau kosong, URL mengikuti host request
    | atau APP_URL.
    |
    */

    'firmware' => [
        'url' => env('PRESENTECH_FIRMWARE_URL', ''),
        'signed_ttl_hours' => (int) env('PRESENTECH_FIRMWARE_TTL_HOURS', 12),
    ],
];
