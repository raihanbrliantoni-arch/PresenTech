# PresenTech Backend API

Backend Laravel untuk PresenTech.

## Menjalankan

```bash
php artisan migrate:fresh --seed
```

API disajikan oleh **Apache Laragon** pada port 8000 melalui vhost
`C:\laragon\etc\apache2\sites-enabled\presentech.conf`. Jalankan Apache dari
Laragon (Start All).

> Jangan menjalankan `php artisan serve` di port 8000 saat Apache aktif.
> Keduanya memakai port yang sama sehingga Apache gagal start dengan
> `AH00072: make_sock: could not bind to address 0.0.0.0:8000`.

API akan tersedia di `http://127.0.0.1:8000/api`.

Tes:

```text
GET /api/health
GET /api/students
GET /api/teachers
GET /api/classes
GET /api/schedules
GET /api/rfid-cards
GET /api/attendance
GET /api/attendance-corrections
GET /api/notifications
GET /api/devices
```

## Catatan

- Endpoint ini adalah tahap API development dan belum diberi autentikasi produksi.
- Notifikasi mempunyai `expires_at` dan endpoint notifikasi membersihkan data yang sudah lewat 24 jam.
- Endpoint `POST /api/attendance` menerima UID RFID dan menjadi dasar integrasi ESP32.
- Endpoint `POST /api/devices/{device}/heartbeat` menjadi dasar monitoring perangkat.
- Endpoint restart baru mencatat perintah; eksekusi fisik akan disambungkan melalui MQTT.
