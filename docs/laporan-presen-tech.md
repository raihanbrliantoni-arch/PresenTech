# LAPORAN PROYEK PRESENTECH

## Sistem Absensi RFID Berbasis Web dan ESP32

**SMK Telesandi**

Disusun oleh: [Nama Penyusun]  
Kelas/Jurusan: [Kelas/Jurusan]  
Guru Pembimbing: [Nama Guru Pembimbing]  
Tahun Ajaran: 2026/2027

---

## Abstrak

PresenTech adalah sistem absensi sekolah yang menggabungkan aplikasi web Laravel, antarmuka React, dan perangkat absensi RFID berbasis ESP32. Perangkat membaca UID kartu, mengirimkannya ke API menggunakan device key, lalu menampilkan nama siswa, status kehadiran, dan waktu yang ditentukan server. Aplikasi web menyediakan pengelolaan siswa, guru, kelas, jadwal, kartu RFID, perangkat, absensi, koreksi, notifikasi, dan log sistem untuk tiga peran: Admin, Guru, dan Siswa.

Pada tahap ini, integrasi perangkat telah diarahkan dari Google Apps Script dan daftar siswa lokal menuju API PresenTech. Backend memiliki **224 pengujian otomatis dengan 1002 assertion** yang lulus. Firmware berada di `firmware/PresentechAlat`, berhasil dikompilasi dengan `arduino-cli` (1.166.872 byte, 59% ruang program; RAM global 16%), dan kompilasi yang sama dijalankan otomatis di CI. Firmware mendukung antrean offline (maks 30 tap + batch sync), heartbeat 15 detik, baca UID dari dashboard, ganti WiFi, restart, portal setup, OTA, dan UDP discovery. Form pendaftaran RFID memakai pilihan siswa dengan pencarian; NIS dan kelas tampil otomatis; perangkat kembali ke mode absensi otomatis setelah pembacaan, timeout, atau pembatalan. Web menyediakan ekspor PDF/Excel/CSV, backup/restore basis data, dan Alpa otomatis. Estimasi capaian proyek adalah **96%**, dengan UAT perangkat keras sebagai pekerjaan berikutnya.

**Kata kunci:** absensi, RFID, ESP32, Laravel, React, PresenTech.

---

## BAB 1 PENDAHULUAN

### 1.1 Latar Belakang

Pencatatan kehadiran manual membutuhkan waktu, mudah salah, dan sulit direkap. Kartu RFID dapat mempercepat identifikasi siswa, tetapi data baru bermanfaat bila langsung masuk ke basis data dan dapat dipantau oleh sekolah. PresenTech menggabungkan kartu RFID, LCD, buzzer, dan jaringan WiFi dengan aplikasi web terpusat.

### 1.2 Tujuan

1. Membangun sistem absensi RFID yang terhubung ke basis data sekolah.
2. Menyediakan dashboard Admin, Guru, dan Siswa.
3. Menentukan status Hadir/Terlambat secara konsisten di server.
4. Menyediakan pengelolaan kartu, perangkat, jadwal, dan koreksi absensi.
5. Menyediakan perangkat ESP32 yang mudah dikonfigurasi dan dipantau, termasuk antrean
   offline, ganti WiFi, dan pembaruan OTA.

### 1.3 Batasan

- Firmware menggunakan WiFi 2.4 GHz, bukan Ethernet.
- Firmware hasil build CI memakai `config.example.h` (kredensial placeholder); alat baru
  perlu dikonfigurasi lewat portal `PresenTech-Setup` atau Serial Monitor.
- OTA memerlukan partition scheme **Minimal SPIFFS (1.9 MB APP with OTA)** dan firmware
  harus didaftarkan lebih dulu di dashboard.
- Mode MQTT tersedia tetapi nonaktif secara bawaan; memerlukan broker Mosquitto.
- Server berjalan di jaringan lokal dengan HTTP; HTTPS dan 2FA belum disediakan.
- Validasi perangkat keras menyeluruh (tap absensi, LCD, buzzer, restart, OTA) masih
  menunggu UAT.
- Firewall Windows perlu diizinkan manual agar alat di jaringan lain bisa mengakses API.

---

## BAB 2 LANDASAN TEORI

### 2.1 RFID dan MFRC522

MFRC522 membaca UID kartu Mifare pada frekuensi 13,56 MHz melalui SPI. UID adalah identitas kartu yang kemudian dicocokkan dengan tabel `rfid_cards`. Sistem menormalkan UID menjadi huruf besar tanpa pemisah agar format konsisten.

### 2.2 ESP32

ESP32 menjalankan firmware Arduino dan terhubung ke WiFi. Perangkat ini mengendalikan LCD I2C 20x4, membaca MFRC522, mengaktifkan buzzer, dan mengirim HTTP JSON ke API.

### 2.3 Laravel dan React

Laravel menyediakan API, validasi, autentikasi Sanctum, otorisasi peran, dan basis data MySQL. React menyediakan dashboard web yang memakai API tersebut. Vite digunakan untuk build frontend.

### 2.4 Keamanan Dasar

- Device key dikirim melalui header `X-Device-Key` dan disimpan sebagai hash SHA-256.
- Device key hanya ditampilkan sekali saat dibuat atau dirotasi.
- Waktu absensi perangkat selalu memakai jam server.
- Perangkat tidak boleh menentukan status kehadiran sendiri.
- Perangkat berstatus Maintenance tidak boleh mencatat absensi.
- Endpoint perangkat dan login dibatasi rate limit.
- Unduhan firmware OTA memakai URL bertanda tangan (tanpa login) dan diverifikasi SHA-256.
- UID kartu dinormalkan (huruf besar, tanpa pemisah) agar tidak terjadi duplikasi kartu.

---

## BAB 3 ANALISIS DAN PERANCANGAN

### 3.1 Kebutuhan Fungsional

| Kode | Kebutuhan |
|---|---|
| F-01 | Admin, Guru, dan Siswa dapat login dan logout. |
| F-02 | Admin mengelola siswa, guru, kelas, jadwal, kartu RFID, dan perangkat. |
| F-03 | Perangkat mengirim UID dan menerima nama, status, serta waktu dari server. |
| F-04 | Sistem mencegah satu siswa tercatat dua kali pada tanggal yang sama dari perangkat. |
| F-05 | Guru dan Siswa dapat mengajukan koreksi absensi. |
| F-06 | Admin dapat menyetujui atau menolak koreksi. |
| F-07 | Admin dapat melihat status perangkat, mengganti WiFi, dan mengirim perintah restart. |
| F-08 | Sistem mencatat log aktivitas penting. |
| F-09 | Perangkat menyimpan tap saat server mati (antrean offline) dan mengirim ulang tanpa duplikat. |
| F-10 | Admin dapat mengunggah firmware dan mengirim update OTA ke perangkat. |
| F-11 | Admin dapat mengekspor absensi ke PDF, Excel, dan CSV serta mencadangkan basis data. |
| F-12 | Sistem menandai Alpa otomatis setelah batas terlambat dan menandai perangkat offline. |

### 3.2 Peran Pengguna

| Peran | Hak Akses Utama |
|---|---|
| Admin | Seluruh master data, perangkat, absensi, koreksi, pengaturan, log, reset password. |
| Guru | Dashboard, absensi, koreksi, data siswa, jadwal, laporan. |
| Siswa | Dashboard, absensi sendiri, koreksi sendiri, jadwal. |

### 3.3 Arsitektur Sistem

```text
Kartu RFID -> MFRC522 -> ESP32 -> WiFi -> REST API Laravel -> MySQL
                                      |
                                      +-> Dashboard React Admin/Guru/Siswa
                                      +-> Heartbeat 15 detik (perintah + jam)
                                      +-> Antrean offline -> batch sync
                                      +-> OTA firmware (URL bertanda tangan)
                                      +-> UDP discovery 8888 (cari server)
                                      +-> MQTT opsional (monitoring)
```

Perangkat tidak menyimpan daftar siswa. Server menjadi sumber kebenaran untuk identitas, status, dan waktu.
Konfigurasi WiFi, URL server, dan device key disimpan di NVS sehingga bisa diubah tanpa
flash ulang (portal setup, Serial Monitor, atau dashboard).

### 3.4 Kebutuhan Perangkat Keras

| Komponen | Pin ESP32 | Keterangan |
|---|---|---|
| LCD I2C 20x4 (0x27) | SDA 21, SCL 22 | Menampilkan status dan hasil tap. |
| MFRC522 SDA/SS | GPIO 5 | Chip select RFID. |
| MFRC522 RST | GPIO 4 | Reset modul RFID. |
| MFRC522 SCK | GPIO 18 | SPI clock. |
| MFRC522 MISO | GPIO 19 | SPI data dari modul. |
| MFRC522 MOSI | GPIO 23 | SPI data ke modul. |
| Buzzer | GPIO 27 | Umpan balik suara. |
| Tombol BOOT | GPIO 0 | Menahan 3 detik membuka portal setup WiFi. |
| Daya | 3,3 V / 5 V sesuai modul | RFID 3,3 V; LCD umumnya 5 V. |

### 3.5 Rancangan Basis Data

| Tabel | Fungsi |
|---|---|
| `users` | Akun login dan peran. |
| `students` | Data siswa dan relasi ke kelas. |
| `teachers` | Data guru. |
| `school_classes` | Data kelas. |
| `rfid_cards` | UID kartu dan pemiliknya. |
| `devices` | Perangkat, device key, status, heartbeat, restart, WiFi, OTA, boot ID. |
| `device_firmwares` | Berkas firmware untuk OTA (versi, hash SHA-256, status kirim). |
| `schedules` | Jadwal pelajaran dan jam masuk. |
| `attendances` | Catatan kehadiran, termasuk jam pulang dan `client_event_id` antrean offline. |
| `attendance_corrections` | Pengajuan koreksi absensi. |
| `holidays` | Hari libur (menunda penandaan Alpa otomatis). |
| `notifications` | Notifikasi pengguna dan status baca. |
| `notification_reads` | Penanda notifikasi yang sudah dibaca per pengguna. |
| `password_reset_requests` | Permintaan reset password beserta token klaim. |
| `system_logs` | Log aktivitas. |
| `settings` | Jam masuk, keterlambatan, jam pulang, MQTT, tahun ajaran. |

Relasi utama:

- `students.class_id` → `school_classes.id`
- `rfid_cards.student_id` → `students.id`
- `attendances.student_id` → `students.id`
- `attendances.rfid_card_id` → `rfid_cards.id`
- `attendances.device_id` → `devices.id`
- `attendance_corrections.attendance_id` → `attendances.id`

### 3.6 Alur Absensi

```text
Tap kartu
  -> baca UID (uppercase hex)
  -> POST /api/attendance + X-Device-Key
  -> validasi device key
  -> cari kartu aktif dan siswa aktif
  -> tentukan tanggal/jam server
  -> cek duplikat harian
  -> hitung status dari jadwal atau setting late_time
  -> simpan attendance (masuk / pulang)
  -> respons 201 / 200 / 409 / 404 / 401 / 403
  -> LCD + buzzer

Bila server tidak terjangkau:
  -> simpan tap ke antrean NVS (maks 30, client_event_id unik)
  -> POST /api/device/attendance/batch saat online
  -> server mengabaikan event yang sudah pernah diproses
```

---

## BAB 4 IMPLEMENTASI

### 4.1 Backend

Backend menggunakan Laravel 13, PHP 8.3, MySQL, dan Sanctum. Endpoint utama:

| Metode | Endpoint | Akses | Fungsi |
|---|---|---|---|
| POST | `/api/login` | Publik | Login dengan `identifier` dan password. |
| GET | `/api/health` | Publik | Cek layanan. |
| POST | `/api/attendance` | Device key / Admin / Guru | Catat absensi. |
| POST | `/api/device/heartbeat` | Device key | Heartbeat perangkat. |
| POST | `/api/device/restart-ack` | Device key | Konfirmasi restart. |
| POST | `/api/device/wifi-ack` | Device key | Konfirmasi kredensial WiFi tersimpan. |
| POST | `/api/device/ota-ack` | Device key | Laporan status OTA. |
| POST | `/api/device/attendance/batch` | Device key | Kirim antrean offline (maks 20 per batch). |
| POST | `/api/device/scan-result` | Device key | Kirim UID hasil mode baca. |
| POST | `/api/devices/{device}/scan` | Admin | Mulai mode baca UID. |
| GET | `/api/devices/{device}/scan` | Admin | Polling status/hasil baca UID. |
| DELETE | `/api/devices/{device}/scan` | Admin | Batalkan mode baca UID. |
| POST | `/api/devices/{device}/restart` | Admin | Minta restart perangkat. |
| POST | `/api/devices/{device}/wifi` | Admin | Kirim kredensial WiFi baru. |
| POST | `/api/devices/{device}/rotate-key` | Admin | Rotasi device key. |
| GET/POST | `/api/firmwares` | Admin | Daftar/unggah firmware OTA. |
| POST/DELETE | `/api/devices/{device}/ota` | Admin | Kirim/batalkan OTA. |
| GET | `/api/firmware/{firmware}/download` | URL bertanda tangan | Unduh firmware untuk OTA. |
| GET | `/api/attendance` | Login | Daftar absensi. |
| POST | `/api/attendance-corrections` | Login | Ajukan koreksi. |
| PUT | `/api/attendance-corrections/{id}` | Admin | Setujui/tolak koreksi. |
| GET | `/api/rfid-cards` | Admin | Daftar kartu RFID. |
| GET | `/api/devices` | Admin | Daftar perangkat. |
| GET | `/api/system/backup` | Admin | Unduh backup basis data (JSON). |
| POST | `/api/system/restore` | Admin | Pulihkan basis data dari backup. |

Perubahan integrasi perangkat pada tahap ini:

- Migrasi `restart_requested_at`, field scan, OTA, WiFi, dan `boot_id` pada tabel `devices`.
- Heartbeat khusus device key tanpa memerlukan ID numerik.
- Perintah restart dibaca perangkat pada heartbeat, lalu dikonfirmasi dengan ack.
- Sesi baca UID: dashboard meminta scan, perangkat masuk mode baca, hasil UID dikirim ke web.
- Sesi scan kedaluwarsa 60 detik dan dapat dibatalkan dari dashboard.
- Normalisasi UID di absensi dan pengelolaan kartu.
- Command `devices:mark-offline` untuk menandai perangkat yang tidak heartbeat.
- Status Maintenance dipertahankan meskipun perangkat mengirim heartbeat.
- Antrean offline: tap disimpan di NVS (maks 30) lalu dikirim lewat batch dengan
  `client_event_id` unik sehingga tidak terjadi absensi ganda.
- OTA: firmware diunggah ke dashboard, diunduh alat lewat URL bertanda tangan, lalu
  diverifikasi SHA-256 sebelum ditulis ke partisi OTA.
- Ganti WiFi dari dashboard lewat heartbeat (`wifi_setup_*`) dan dikonfirmasi `wifi-ack`.
- UDP discovery `PRESENTECH_DISCOVERY` pada port 8888 agar alat menemukan server tanpa
  mengisi IP. MQTT tersedia sebagai jalur opsional untuk perintah yang lebih cepat.

Contoh heartbeat:

```http
POST /api/device/heartbeat
X-Device-Key: ptd_...
Content-Type: application/json

{"firmware_version":"1.0.0","ip_address":"192.168.1.50","boot_id":"a1b2c3","firmware_hash":"9f2c..."}
```

```json
{
  "message": "Heartbeat diterima.",
  "device": {"status": "Online", "last_seen_at": "..."},
  "server_time": "2026-11-18T07:10:00+07:00",
  "server_date": "2026-11-18",
  "entry_time": "06:30",
  "late_time": "07:15",
  "home_time": "15:00",
  "check_out_active": false,
  "timezone": "Asia/Jakarta",
  "restart_requested": false,
  "scan_requested": false,
  "scan_expires_in": 0,
  "firmware": {"current": "1.0.0", "latest": "1.0.0", "update_available": false},
  "ota": null,
  "display": {
    "school_label": "SMK TELESANDI",
    "device_name": "Gerbang RPL 1",
    "greeting": "Selamat pagi",
    "clock": "07:10:00",
    "state": "masuk"
  }
}
```

Alur baca UID dari web:

1. Admin menekan **Baca UID dari Alat** pada menu Kartu RFID.
2. Web mengirim `POST /api/devices/{id}/scan`; server menandai `scan_requested_at`.
3. Perangkat melihat `scan_requested: true` pada heartbeat berikutnya (interval 15 detik,
   atau segera bila MQTT aktif) dan menampilkan **MODE BACA UID**.
4. Kartu ditempelkan; perangkat mengirim `POST /api/device/scan-result` berisi UID.
5. Web yang melakukan polling `GET /api/devices/{id}/scan` menerima status `completed` dan UID, lalu menawarkan **Oke/Batal**.
6. Sesi berakhir otomatis setelah 60 detik atau saat Admin menekan Batal.

### 4.2 Frontend

Frontend React 19 dan Vite menyediakan:

- Landing page, login, dan alur lupa password.
- Dashboard Admin dengan 13 menu: dashboard, absensi, siswa, guru, kelas, jadwal,
  perangkat, kartu RFID, laporan, log, koreksi, reset password, dan pengaturan.
- Dashboard Guru: dashboard, absensi, koreksi, siswa, jadwal, laporan.
- Dashboard Siswa: dashboard, absensi, koreksi, jadwal, notifikasi.
- Koreksi absensi dengan pemilihan data absensi dan pembatalan pengajuan oleh siswa.
- Ekspor absensi **PDF (potret/lanskap), Excel, dan CSV** dengan rentang tanggal,
  No Absen, serta opsi menampilkan semua siswa (Alpa otomatis). Jadwal juga bisa
  diekspor ke PDF/Excel/CSV.
- Impor siswa dan kartu RFID dari CSV, serta backup/restore basis data (JSON) dari menu
  Pengaturan.
- Halaman perangkat: status Online/Offline/Maintenance, ganti WiFi, restart, rotasi
  device key, mode baca UID, dan kirim firmware OTA.
- Pengaturan jam masuk, batas terlambat, jam pulang, hari libur, tahun ajaran, dan
  koneksi MQTT.
- Mode **Dari Alat RFID** yang nyata: pilih perangkat Online, tekan **Baca UID dari Alat**, konfirmasi Oke/Batal sebelum dan sesudah pembacaan, lalu UID otomatis mengisi form pendaftaran.
- Nama siswa dipilih dari dropdown dengan pencarian (nama/NIS/kelas), bukan diketik bebas; NIS dan kelas tampil otomatis dan tidak dapat diubah.
- UID terkunci di mode alat, tombol **Selesai** membersihkan sesi, dan perangkat kembali ke mode absensi otomatis.

### 4.3 Firmware

Firmware berada di `firmware/PresentechAlat/` (lihat `firmware/README.md` dan
`firmware/WIRING.md`):

```text
firmware/
  PresentechAlat/
    PresentechAlat.ino   # firmware utama
    config.example.h     # template konfigurasi
    config.h             # dibuat sendiri, tidak ikut Git
  wiring/                # sketsa Fritzing + diagram skematik
```

Alur firmware:

1. Inisialisasi LCD, SPI, MFRC522, buzzer, dan konfigurasi NVS.
2. Menghubungkan WiFi dengan timeout dan reconnect otomatis; portal setup terbuka bila
   gagal tersambung.
3. Menemukan server lewat UDP discovery (`PRESENTECH_DISCOVERY` port 8888) bila
   `API_BASE_URL` kosong, lalu mengirim heartbeat setiap 15 detik.
4. Membaca UID kartu dan menormalkannya menjadi uppercase tanpa pemisah.
5. Mengirim `{"uid":"..."}` ke `/api/attendance` dengan device key.
6. Menampilkan hasil dari server pada LCD dan membunyikan buzzer.
7. Bila server/WiFi tidak terjangkau, tap disimpan di antrean NVS (maks 30 event) dan
   dikirim ke `/api/device/attendance/batch` setiap 30 detik setelah online.
8. Jika heartbeat meminta restart, firmware mengirim ack lalu restart.
9. Jika heartbeat meminta scan, firmware mereset medan RF, membangunkan kartu dengan WUPA
   (termasuk kartu yang masih HALT setelah absensi), mengirim hasil ke
   `/api/device/scan-result`, lalu kembali ke mode absensi.
10. Jika dashboard mengirim firmware baru, alat mengunduh lewat URL bertanda tangan,
    memeriksa SHA-256, menulis partisi OTA, melapor `ota-ack`, lalu restart.
11. Konfigurasi WiFi dan device key dapat diubah tanpa flash ulang: portal
    `PresenTech-Setup` (BOOT 3 detik), Serial Monitor (`portal`, `srv`, `key`), atau
    tombol **Ganti WiFi** di dashboard.

Pesan LCD utama:

| Kondisi | Tampilan |
|---|---|
| Siaga | SMK TELESANDI / nama alat / MSK + PLG / jumlah antrean + jam |
| Berhasil | ABSENSI BERHASIL / nama / kelas / status + jam |
| Absen pulang | ABSEN PULANG / nama / kelas / jam |
| Duplikat | SUDAH ABSEN / pesan server / TIDAK DICATAT ULANG |
| Kartu tidak terdaftar | KARTU TIDAK TERDAFTAR / UID / DAFTARKAN DI MENU RFID |
| Device key salah | DEVICE KEY SALAH / PERIKSA CONFIG.H / ATAU ROTATE KEY |
| Maintenance | ALAT MAINTENANCE / ABSEN DITOLAK / HUBUNGI ADMIN |
| Mode baca UID | MODE BACA UID / TEMPELKAN KARTU / DARI DASHBOARD / SISA n DETIK |
| WiFi mati | WIFI TERPUTUS / nama SSID / BOOT 3 DETIK: SETUP / MENUNGGU KONEKSI |
| Server mati | nama alat / SERVER OFFLINE / TAP MASUK ANTREAN / jumlah antrean |
| Portal setup | SETUP WIFI / PresenTech-Setup / SANDI: presen123 / BUKA 192.168.4.1 |
| Update firmware | UPDATE FIRMWARE / versi / MOHON TUNGGU... / JANGAN MATIKAN |

Konfigurasi rahasia tidak ditulis di laporan. Nilai sebenarnya berada di `config.h`:

```cpp
#define WIFI_SSID "..."
#define WIFI_PASSWORD "..."
#define API_BASE_URL "http://192.168.1.5:8000/api"
#define DEVICE_KEY "ptd_..."
#define FIRMWARE_VERSION "1.0.0"
#define HEARTBEAT_MS 15000UL
#define QUEUE_MAX 30
```

### 4.4 Keamanan dan Konsistensi Data

- UID dinormalkan sebelum pencarian, sehingga `a1:b2:c3:d4` cocok dengan `A1B2C3D4`.
- Perangkat tidak dapat menentukan tanggal, jam, atau status palsu.
- Satu siswa hanya dicatat sekali per tanggal dari perangkat; event antrean memakai
  `client_event_id` unik agar pengiriman ulang tidak menghasilkan duplikat.
- Device key disimpan sebagai hash dan hanya ditampilkan sekali saat dibuat/dirotasi.
- Perangkat Maintenance tidak dapat mencatat absensi.
- Rate limit melindungi login dan endpoint perangkat.
- Waktu server memakai zona Asia/Jakarta sesuai pengaturan aplikasi.
- Unduhan firmware memakai URL bertanda tangan sementara dan diverifikasi SHA-256.
- Password akun disimpan sebagai hash; akun baru wajib mengganti password sementara saat
  login pertama.
- Backup basis data berisi data sensitif dan hanya dapat diunduh Admin yang login.

---

## BAB 5 PENGUJIAN

### 5.1 Pengujian Otomatis Backend

Perintah:

```bash
php artisan test --compact
```

Hasil:

```text
224 tests, 1002 assertions, passed (33 file tes)
```

Cakupan:

- Heartbeat dengan device key valid, tanpa key, dan key salah.
- Perangkat Maintenance tetap Maintenance setelah heartbeat.
- Perintah restart dilaporkan pada heartbeat dan dihapus setelah ack.
- Command penandaan perangkat offline dan penandaan Alpa otomatis.
- Sesi baca UID: permintaan Admin, flag pada heartbeat, hasil UID ternormalisasi, batal, kedaluwarsa, dan penolakan perangkat Offline/Maintenance.
- Normalisasi UID pada unit test, absensi, pendaftaran kartu, dan filter kartu.
- Antrean offline: batch absensi, `client_event_id` idempoten, dan penolakan duplikat.
- OTA: unggah firmware, URL bertanda tangan, ack status, dan pembatalan.
- Ganti WiFi dari dashboard, WiFi ack, dan rotasi device key.
- Export laporan, backup/restore basis data, dan otorisasi per peran.
- Regresi absensi perangkat dan koreksi.

### 5.2 Pengujian Frontend

```text
npm run build  -> berhasil, dist terbentuk
npm run lint   -> berhasil (0 error)
```

Selain lokal, CI GitHub Actions menjalankan `npm ci`, `npm run lint`, dan `npm run build`
pada setiap push ke `main`.

### 5.3 Pengujian Integrasi API

- `GET /api/health` dari alamat LAN `http://192.168.1.5:8000/api/health` berhasil.
- Heartbeat dengan device key berhasil dan status perangkat menjadi Online.
- Absensi dengan UID tidak terdaftar mengembalikan 404 tanpa membuat data.
- Server dijalankan dengan `--host=0.0.0.0` agar dapat diakses perangkat.
- Uji alur scan end-to-end via API: permintaan `waiting` → hasil `039D7641` → polling `completed` → batal `idle`.
- Firmware dikompilasi dengan `arduino-cli` untuk FQBN `esp32:esp32:esp32`, partition
  `min_spiffs`: 1.166.872 byte (59% ruang program), RAM global 52.968 byte (16%).
- Kompilasi yang sama dijalankan otomatis di CI dan hasil `.bin`-nya diunggah sebagai
  artifact `presenTech-firmware`.

### 5.4 Pengujian yang Belum Dilakukan

- UAT tap kartu absensi dan verifikasi LCD/buzzer pada board fisik.
- UAT pendaftaran kartu lewat dropdown siswa di dashboard.
- Uji restart, ganti WiFi, portal setup, dan OTA dari dashboard ke perangkat fisik.
- Uji antrean offline saat WiFi/server dimatikan lalu dinyalakan kembali.
- Uji dari perangkat lain di jaringan sekolah (firewall Windows perlu diizinkan).
- Jalankan checklist UAT penuh di Lampiran A.
- UAT dengan pengguna sebenarnya.

---

## BAB 6 HASIL DAN PEMBAHASAN

### 6.1 Capaian

| Komponen | Bobot | Sebelum | Setelah pekerjaan ini | Setelah UAT |
|---|---:|---:|---:|---:|
| Backend/API | 25% | 90% | 99% | 100% |
| Frontend web | 25% | 85% | 96% | 99% |
| Firmware | 20% | 70% | 95% | 100% |
| Integrasi alat–web | 20% | 10% | 90% | 100% |
| Dokumentasi | 10% | 30% | 100% | 100% |
| **Total estimasi** |  | **±63%** | **±96%** | **±100%** |

Angka ini adalah estimasi berbobot untuk keperluan laporan, bukan hasil UAT formal.
Firmware sudah dikompilasi di lokal dan CI, protokol API sudah diuji end-to-end, tetapi
pengujian pada board fisik dan pengguna sebenarnya masih menjadi penentu terakhir.

### 6.2 Kelebihan

- Server menjadi sumber kebenaran untuk identitas, waktu, dan status.
- Firmware lebih sederhana karena tidak lagi menyimpan daftar siswa.
- Tap tetap tercatat saat server/WiFi mati berkat antrean offline yang idempoten.
- Perangkat dapat dipantau, di-restart, diganti WiFi-nya, dan di-OTA dari dashboard.
- UID dapat dibaca langsung dari alat melalui dashboard, tanpa mengetik manual.
- UID tidak lagi sensitif terhadap huruf besar/kecil dan pemisah.
- Laporan absensi bisa diekspor ke PDF, Excel, dan CSV sesuai kebutuhan sekolah.
- Alpa otomatis, penandaan perangkat offline, dan backup harian berjalan lewat scheduler.
- CI menjaga kualitas: 224 tes, Pint, lint/build frontend, dan kompilasi firmware.
- Dokumentasi wiring menyertakan sketsa Fritzing asli yang bisa dibuka dan diedit.

### 6.3 Keterbatasan

- UAT perangkat keras menyeluruh (tap, LCD, buzzer, restart, WiFi, OTA) belum selesai.
- Mode baca UID dan perintah lain memakai polling heartbeat 15 detik bila MQTT tidak aktif.
- MQTT tersedia tetapi nonaktif secara bawaan dan memerlukan broker.
- Server lokal masih HTTP; HTTPS, 2FA Admin, dan token di `localStorage` belum diperkuat.
- Build firmware di CI memakai kredensial placeholder, sehingga alat baru tetap perlu
  provisioning lewat portal/Serial.
- Firewall Windows perlu diizinkan secara manual untuk akses dari perangkat lain.
- Belum ada staging, monitoring uptime, atau uji restore terjadwal.
- Import CSV siswa belum atomik: satu baris gagal dapat meninggalkan data setengah masuk.

### 6.4 Rencana Pengembangan

1. Flash firmware ke board dan jalankan checklist UAT di Lampiran A.
2. Uji kartu asli, duplikat, kartu tidak terdaftar, maintenance, dan antrean offline.
3. Uji OTA, ganti WiFi, portal setup, dan restart dari dashboard ke perangkat fisik.
4. Tambahkan HTTPS, 2FA Admin, dan penyimpanan token yang lebih aman.
5. Aktifkan MQTT di jaringan sekolah untuk perintah yang lebih real-time.
6. Siapkan deploy hosting (VPS/cPanel), staging, dan monitoring uptime.
7. Lakukan UAT dengan Admin, Guru, dan Siswa, lalu kumpulkan umpan balik.

---

## BAB 7 PENUTUP

### 7.1 Kesimpulan

PresenTech telah memiliki aplikasi web, API, dan firmware yang saling terhubung. Backend
teruji dengan 224 tes otomatis (1002 assertion), frontend berhasil dibangun dan lolos
lint, serta firmware dikompilasi otomatis di CI. Fitur perangkat sudah mencakup antrean
offline, heartbeat, baca UID, ganti WiFi, restart, portal setup, dan OTA. Web menyediakan
ekspor PDF/Excel/CSV, backup/restore, Alpa otomatis, dan pengelolaan perangkat. Repository
GitHub dilengkapi CI tiga job untuk backend, frontend, dan firmware. Pekerjaan yang
tersisa berpusat pada UAT perangkat keras dan penguatan keamanan produksi.

### 7.2 Saran

Lakukan pengujian di board sebelum presentasi, gunakan kartu yang UID-nya sudah didaftarkan, dan pastikan komputer server serta ESP32 berada pada jaringan WiFi yang sama. Untuk produksi, gunakan HTTPS dan pisahkan kredensial dari kode yang dibagikan.

---

## LAMPIRAN A — Checklist Uji Manual

- [ ] Backend dapat dibuka dari HP: `http://192.168.1.5:8000/api/health`.
- [ ] Perangkat muncul Online di dashboard setelah heartbeat.
- [ ] Kartu terdaftar menampilkan nama, kelas, status, dan jam.
- [ ] Tap kartu yang sama menampilkan SUDAH ABSEN.
- [ ] Kartu tidak terdaftar menampilkan KARTU TIDAK TERDAFTAR.
- [ ] Perangkat Maintenance menampilkan ALAT MAINTENANCE.
- [ ] Menu Kartu RFID → Baca UID dari Alat → konfirmasi Oke menampilkan MODE BACA UID di alat.
- [ ] Tap kartu pada mode scan mengisi UID di form pendaftaran setelah konfirmasi Oke.
- [ ] Batal pada konfirmasi mengembalikan alat ke mode absensi dan mengosongkan UID.
- [ ] Nama siswa dipilih lewat dropdown pencarian; NIS dan kelas terisi otomatis serta tidak dapat diubah.
- [ ] Tombol Selesai mengosongkan form dan alat tetap kembali ke mode absensi.
- [ ] Mode scan berakhir otomatis setelah 60 detik.
- [ ] Tombol restart membuat perangkat restart setelah heartbeat.
- [ ] WiFi dimatikan menampilkan WIFI OFFLINE / SERVER OFFLINE.
- [ ] Data absensi muncul di dashboard Admin/Guru/Siswa.
- [ ] Tap saat server mati tersimpan di antrean; setelah server hidup, batch terkirim tanpa duplikat.
- [ ] Tombol **Ganti WiFi** dari dashboard membuat alat restart dan kembali Online.
- [ ] Portal `PresenTech-Setup` terbuka setelah BOOT ditahan 3 detik dan menyimpan WiFi baru.
- [ ] Unggah firmware di menu Firmware, kirim OTA, dan versi alat berubah.
- [ ] Export absensi PDF/Excel/CSV menghasilkan file yang benar.
- [ ] Backup basis data dapat diunduh dan dipulihkan lewat menu Pengaturan.
- [ ] Siswa tanpa tap setelah batas terlambat otomatis berstatus Alpa.

## LAMPIRAN B — Perintah Operasional

```bash
cd PresenTech-Backend
php artisan serve --host=0.0.0.0 --port=8000
php artisan devices:discovery
php artisan schedule:work
php artisan test --compact
php artisan devices:mark-offline
php artisan devices:mqtt-listen   # hanya bila MQTT diaktifkan
```

Firewall Windows (jalankan PowerShell sebagai Administrator):

```powershell
New-NetFirewallRule -DisplayName "PresenTech API 8000" -Direction Inbound -Action Allow -Protocol TCP -LocalPort 8000
New-NetFirewallRule -DisplayName "PresenTech Discovery 8888" -Direction Inbound -Action Allow -Protocol UDP -LocalPort 8888
```

## LAMPIRAN C — Catatan Rilis

- Tanggal: 18 November 2026.
- Versi firmware: 1.0.0 (antrean offline, OTA, portal setup, heartbeat 15 detik).
- Perangkat contoh: `ESP32-RPL-01`.
- API lokal: `http://192.168.1.5:8000/api`.
- Firmware: `firmware/PresentechAlat` (repo GitHub `raihanbrliantoni-arch/PresenTech`).
- Laporan: `docs/laporan-presen-tech.md`.
- CI: 3 job (backend, frontend, firmware) + artifact `.bin` dan rilis otomatis saat tag `v*`.
