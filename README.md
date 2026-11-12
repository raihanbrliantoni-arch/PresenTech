# PresenTech — Sistem Absensi RFID & IoT Sekolah

[![CI](https://github.com/raihanbrliantoni-arch/PresenTech/actions/workflows/ci.yml/badge.svg)](https://github.com/raihanbrliantoni-arch/PresenTech/actions/workflows/ci.yml)

Aplikasi absensi sekolah berbasis **RFID + ESP32** dengan web dashboard untuk **Admin, Guru, dan Siswa**. Perangkat membaca kartu RFID, mengirim UID ke API Laravel, lalu web menampilkan kehadiran, laporan, dan status perangkat secara real-time.

> Repo ini berisi **web (backend Laravel + frontend React)** dan **firmware alat ESP32**
> di folder [`firmware/`](firmware/). Panduan merakit: [`firmware/WIRING.md`](firmware/WIRING.md).
>
> **Starter bersih:** database sengaja dikosongkan. Seeder hanya membuat **satu akun Admin**;
> data kelas, guru, siswa, kartu RFID, jadwal, dan perangkat dibuat dari dashboard Admin.

---

## Fitur

**Admin**
- Kelola siswa, guru, kelas, jadwal, kartu RFID, perangkat IoT, dan pengguna.
- Absensi manual, koreksi absensi, log sistem, notifikasi.
- Export absensi (PDF potret/lanskap, Excel, CSV) dengan **No Absen** dan opsi menampilkan semua siswa (Alpa otomatis).
- Backup & restore **database** (JSON) dari menu Pengaturan.
- Kadaluarsa akun, reset password, dan pengaturan jam masuk/batas terlambat.

**Guru**
- Dashboard kelas, jadwal mengajar, rekap kehadiran, pengajuan koreksi absensi.

**Siswa**
- Riwayat absensi pribadi, jadwal kelas, notifikasi, pengajuan koreksi.

**Perangkat (ESP32)**
- Tap kartu → absensi tercatat (Hadir/Terlambat ditentukan server).
- Heartbeat, mode baca UID dari web, restart dari web, antrean offline.
- Menemukan server otomatis lewat **UDP discovery** (tanpa mengisi IP), atau MQTT untuk mode server online.

**Otomatisasi**
- Siswa tanpa tap setelah **Batas Terlambat** otomatis ditandai **Alpa** (dicek tiap 5 menit di hari kerja).
- Bila siswa tap setelah ditandai Alpa, statusnya otomatis diperbarui (bukan duplikat).
- Perangkat offline ditandai otomatis oleh scheduler.

---

## Arsitektur

```
Kartu RFID → MFRC522 → ESP32 (WiFi)
                          │  HTTP JSON + X-Device-Key
                          │  (atau MQTT, opsional)
                          ▼
                   Laravel REST API  ──►  MySQL
                          │
                          ▼
              React Dashboard (SPA di /app)
```

- Backend: Laravel 13, Sanctum, PHPUnit.
- Frontend: React 19 + Vite, disajikan Laravel dari `public/app`.
- Perangkat: ESP32 + MFRC522 + LCD I2C 20x4 + buzzer (firmware Arduino, lihat [`firmware/`](firmware/)).

---

## Struktur folder

```
.
├─ PresenTech-Backend/     # Laravel API + host SPA (/app)
├─ PresenTech-frontend/    # React + Vite
├─ firmware/               # kode alat ESP32 + skema wiring
│  ├─ PresentechAlat/      # firmware Arduino (.ino + config.example.h)
│  ├─ README.md            # cara konfigurasi, upload, OTA
│  └─ WIRING.md            # tabel pin + catatan daya
├─ docs/                   # laporan proyek (md/html/pdf)
├─ scripts/
│  └─ build-frontend.ps1   # build React lalu salin ke public/app
├─ start-presentech.bat    # jalankan API + discovery + scheduler + tunnel
└─ README.md
```

---

## Alat & firmware (ESP32)

Firmware siap pakai ada di [`firmware/PresentechAlat`](firmware/PresentechAlat).
Skema pin lengkap dan catatan daya: **[`firmware/WIRING.md`](firmware/WIRING.md)**.

| Komponen | Pin ESP32 |
|---|---|
| MFRC522 SDA/SS / RST | GPIO5 / GPIO4 |
| MFRC522 SCK / MISO / MOSI | GPIO18 / GPIO19 / GPIO23 |
| MFRC522 VCC | **3.3V** (jangan 5V) |
| LCD I2C 20x4 SDA / SCL | GPIO21 / GPIO22 |
| LCD VCC | 5V (VIN) |
| Buzzer aktif | GPIO27 |
| Tombol setup | GPIO0 (BOOT) |

Ringkasnya: daftarkan alat di **Perangkat IoT**, salin device key `ptd_...` ke
`config.h`, upload firmware, lalu alat akan **Online** dan siap menerima tap kartu.
Langkah lengkap: [`firmware/README.md`](firmware/README.md).

---

## Prasyarat

- PHP **8.3+** dengan ekstensi standar Laravel
- Composer 2
- MySQL 5.7+/8 (Laragon) — atau SQLite
- Node.js **20+** dan npm
- (Opsional) Arduino CLI + core `esp32` untuk firmware

---

## Instalasi

### 1. Clone

```powershell
git clone <url-repo> presentech
cd presentech
```

### 2. Backend

```powershell
cd PresenTech-Backend
composer install
Copy-Item .env.example .env
php artisan key:generate
```

Buat database MySQL bernama `presentech` (lewat Laragon/phpMyAdmin), sesuaikan `.env` bila perlu, lalu:

```powershell
php artisan migrate --seed
```

Seeder hanya membuat **satu akun Admin** (username & password diatur lewat
`PRESENTECH_ADMIN_*` pada `.env`). Data guru, siswa, kelas, kartu RFID, jadwal, dan
perangkat dibuat dari dashboard Admin.
Untuk mengisi struktur **27 kelas** (X–XII: RPL 4, DKV 2, TKJ 2, TRANSMISI 1 per tingkat)
sekaligus:

```powershell
php artisan db:seed --class=SchoolClassSeeder
```

### 3. Frontend

```powershell
cd ..
powershell -ExecutionPolicy Bypass -File scripts\build-frontend.ps1 -Install
```

Script akan `npm install`, `npm run build`, lalu menyalin `dist/*` ke `PresenTech-Backend/public/app`.

### 4. Jalankan

Cara cepat (Windows):

```powershell
.\start-presentech.bat
```

Atau manual (3 terminal):

```powershell
cd PresenTech-Backend
php artisan serve --host=0.0.0.0 --port=8000
php artisan devices:discovery
php artisan schedule:work
```

Buka:

- Web: <http://127.0.0.1:8000/app>
- API health: <http://127.0.0.1:8000/api/health>
- Dari HP/LAN: `http://<IP-laptop>:8000/app`

> `start-presentech.bat` juga menjalankan `cloudflared tunnel` (akses publik sementara). Jangan dipakai saat tidak diperlukan.

---

## Akun Admin awal

| Peran | Username | Password |
|---|---|---|
| Admin | `admin` | `admin123` |

Nilai tersebut berasal dari `PRESENTECH_ADMIN_USERNAME` / `PRESENTECH_ADMIN_PASSWORD`
di `.env` dan **wajib diganti setelah login pertama** (menu **Profil → Ganti Password**).

Tidak ada akun Guru/Siswa bawaan dan tidak ada pendaftaran publik. Setelah login sebagai
Admin, buat akun lewat:

1. **Kelas** → Tambah (mis. X RPL 1).
2. **Guru** → Tambah (NIP, nama, mata pelajaran).
3. **Siswa** → Tambah (NIS, nama, kelas).
4. Buka menu baris data → **Buat Akun** / **Reset Password Akun** untuk mendapatkan
   password sementara. Password sementara wajib diganti saat login pertama.
5. **Kartu RFID** → daftarkan UID kartu ke siswa.
6. **Perangkat IoT** → Tambah untuk mendapatkan Device Key ESP32.

> Ingin data contoh? Project versi lengkap (dengan seeder demo) tetap tersimpan terpisah
> di komputer pengembang; repo ini sengaja dibuat kosong untuk setup akun sekolah nyata.

---

## Menghubungkan perangkat ESP32

1. Login Admin → **Perangkat IoT** → **Tambah**.
2. Salin **Device Key** (`ptd_...`) yang hanya ditampilkan sekali.
3. Isi `config.h` firmware:

```cpp
#define WIFI_SSID "NAMA_WIFI"
#define WIFI_PASSWORD "PASSWORD_WIFI"
#define DEVICE_KEY "ptd_..."
#define DIRECT_SERVER_URL ""   // kosong = cari server otomatis (UDP discovery)
```

4. Flash firmware (Arduino IDE atau `arduino-cli`), lalu nyalakan alat di WiFi yang sama dengan server.
5. Alat mengirim `PRESENTECH_DISCOVERY` ke UDP `8888`; server menjawab `http://<IP-server>:8000/api` lalu heartbeat berjalan tiap 3 detik.

Port yang perlu dibuka di firewall: **TCP 8000** dan **UDP 8888**.

Mode MQTT (opsional, untuk server online): aktifkan **Pengaturan → Koneksi MQTT**, jalankan `php artisan devices:mqtt-listen`, dan flash firmware dengan `MQTT_ENABLE 1` serta host/kredensial yang sama.

---

## Pengaturan penting

- **Jam Masuk** & **Batas Terlambat** (Pengaturan → Aturan Kehadiran): menentukan status `Terlambat` dan kapan siswa tanpa tap ditandai `Alpa`.
- **Jadwal**: mendukung slot jam cepat, tambah ke beberapa kelas sekaligus, duplikat jadwal, dan penolakan jadwal bentrok (kelas/guru/ruangan).
- **Export**: orientasi PDF diingat browser; opsi "tampilkan semua siswa" hanya untuk periode Harian.

---

## Pengujian

```powershell
cd PresenTech-Backend
php artisan test
```

Frontend:

```powershell
cd PresenTech-frontend
npm run lint
npm run build
```

---

## Keamanan

- **Jangan commit** `.env` (APP_KEY, kredensial DB) maupun `config.h` firmware (WiFi + device key). Keduanya sudah masuk `.gitignore`.
- Repo ini berisi data **dummy**; jangan menambahkan data siswa asli ke repo publik.
- Untuk produksi: `APP_DEBUG=false`, gunakan HTTPS, batasi akses tunnel, dan rotasi Device Key secara berkala.

---

## Lisensi

Dirilis di bawah [MIT License](LICENSE) © 2026 Raihan Briliantoni.
Proyek tugas sekolah (PKL); silakan gunakan dan kembangkan dengan menyebutkan sumber.

---

## Status CI

Workflow ada di [`.github/workflows/ci.yml`](.github/workflows/ci.yml) dan berjalan otomatis
di setiap push ke `main` serta pull request:

- **Backend** — `composer install`, `php artisan test`, dan `vendor/bin/pint --test`.
- **Frontend** — `npm ci`, `npm run lint`, dan `npm run build`.
- **Firmware** — `arduino-cli compile` untuk `firmware/PresentechAlat` dengan core
  ESP32 3.3.8 dan partition `min_spiffs` (skema ber-OTA), memakai
  `config.example.h` sebagai `config.h` sementara.

Test backend memakai SQLite in-memory (lihat `PresenTech-Backend/phpunit.xml`), jadi CI
tidak memerlukan MySQL.
