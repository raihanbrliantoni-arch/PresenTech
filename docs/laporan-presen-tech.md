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

Pada tahap ini, integrasi perangkat telah diarahkan dari Google Apps Script dan daftar siswa lokal menuju API PresenTech. Backend memiliki 83 pengujian otomatis dengan 305 assertion yang lulus. Firmware baru tersedia di `D:\Coding\Arduino\PresenTech_alat` dan telah berhasil dikompilasi melalui `arduino-cli` (75% ruang program), telah di-upload ke board ESP32 dan berjalan dengan heartbeat 3 detik. Form pendaftaran RFID memakai pilihan siswa dengan pencarian; NIS dan kelas tampil otomatis; perangkat kembali ke mode absensi otomatis setelah pembacaan, timeout, atau pembatalan. Fitur baca UID dari dashboard juga telah ditambahkan: Admin memulai mode baca dari web, perangkat masuk mode scan pada heartbeat berikutnya, lalu UID dikirim kembali ke web. Estimasi capaian proyek adalah 90%, dengan UAT penuh sebagai pekerjaan berikutnya.

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
5. Menyediakan perangkat ESP32 yang mudah dikonfigurasi dan dipantau.

### 1.3 Batasan

- Firmware menggunakan WiFi 2.4 GHz, bukan Ethernet.
- Mode offline belum diimplementasikan; tap ditolak saat server tidak terjangkau.
- Laporan web saat ini berupa ekspor CSV, belum PDF/Excel.
- Backup basis data dari dashboard belum tersedia.
- Perintah restart dikirim melalui heartbeat HTTP, belum memakai MQTT.
- Validasi perangkat keras menyeluruh (tap absensi, LCD, buzzer, restart) masih menunggu UAT.

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
- Waktu absensi perangkat selalu memakai jam server.
- Perangkat tidak boleh menentukan status kehadiran sendiri.
- Perangkat berstatus Maintenance tidak boleh mencatat absensi.
- Endpoint perangkat dibatasi rate limit.

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
| F-07 | Admin dapat melihat status perangkat dan mengirim perintah restart. |
| F-08 | Sistem mencatat log aktivitas penting. |

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
                                      +-> Heartbeat perangkat
                                      +-> Perintah restart
```

Perangkat tidak menyimpan daftar siswa. Server menjadi sumber kebenaran untuk identitas, status, dan waktu.

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
| Daya | 3,3 V / 5 V sesuai modul | RFID 3,3 V; LCD umumnya 5 V. |

### 3.5 Rancangan Basis Data

| Tabel | Fungsi |
|---|---|
| `users` | Akun login dan peran. |
| `students` | Data siswa dan relasi ke kelas. |
| `teachers` | Data guru. |
| `school_classes` | Data kelas. |
| `rfid_cards` | UID kartu dan pemiliknya. |
| `devices` | Perangkat, device key, status, heartbeat, restart. |
| `schedules` | Jadwal pelajaran dan jam masuk. |
| `attendances` | Catatan kehadiran. |
| `attendance_corrections` | Pengajuan koreksi absensi. |
| `notifications` | Notifikasi pengguna. |
| `system_logs` | Log aktivitas. |
| `settings` | Jam masuk, keterlambatan, MQTT, tahun ajaran. |

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
  -> simpan attendance
  -> respons 201 / 409 / 404 / 401 / 403
  -> LCD + buzzer
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
| POST | `/api/device/scan-result` | Device key | Kirim UID hasil mode baca. |
| POST | `/api/devices/{device}/scan` | Admin | Mulai mode baca UID. |
| GET | `/api/devices/{device}/scan` | Admin | Polling status/hasil baca UID. |
| DELETE | `/api/devices/{device}/scan` | Admin | Batalkan mode baca UID. |
| POST | `/api/devices/{device}/restart` | Admin | Minta restart perangkat. |
| GET | `/api/attendance` | Login | Daftar absensi. |
| POST | `/api/attendance-corrections` | Login | Ajukan koreksi. |
| PUT | `/api/attendance-corrections/{id}` | Admin | Setujui/tolak koreksi. |
| GET | `/api/rfid-cards` | Admin | Daftar kartu RFID. |
| GET | `/api/devices` | Admin | Daftar perangkat. |

Perubahan integrasi perangkat pada tahap ini:

- Migrasi `restart_requested_at` pada tabel `devices`.
- Heartbeat khusus device key tanpa memerlukan ID numerik.
- Perintah restart dibaca perangkat pada heartbeat, lalu dikonfirmasi dengan ack.
- Sesi baca UID: dashboard meminta scan, perangkat masuk mode baca, hasil UID dikirim ke web.
- Sesi scan kedaluwarsa 60 detik dan dapat dibatalkan dari dashboard.
- Normalisasi UID di absensi dan pengelolaan kartu.
- Command `devices:mark-offline` untuk menandai perangkat yang tidak heartbeat.
- Status Maintenance dipertahankan meskipun perangkat mengirim heartbeat.

Contoh heartbeat:

```http
POST /api/device/heartbeat
X-Device-Key: ptd_...
Content-Type: application/json

{"firmware_version":"1.1.0","ip_address":"192.168.1.50"}
```

```json
{
  "message": "Heartbeat diterima.",
  "device": {"status": "Online", "last_seen_at": "..."},
  "server_time": "2026-10-25T23:23:09+07:00",
  "late_time": "07:15",
  "restart_requested": false,
  "scan_requested": false,
  "scan_expires_in": 0
}
```

Alur baca UID dari web:

1. Admin menekan **Baca UID dari Alat** pada menu Kartu RFID.
2. Web mengirim `POST /api/devices/{id}/scan`; server menandai `scan_requested_at`.
3. Perangkat melihat `scan_requested: true` pada heartbeat berikutnya (interval 3 detik) dan menampilkan **MODE BACA UID**.
4. Kartu ditempelkan; perangkat mengirim `POST /api/device/scan-result` berisi UID.
5. Web yang melakukan polling `GET /api/devices/{id}/scan` menerima status `completed` dan UID, lalu menawarkan **Oke/Batal**.
6. Sesi berakhir otomatis setelah 60 detik atau saat Admin menekan Batal.

### 4.2 Frontend

Frontend React 19 dan Vite menyediakan:

- Landing page, login, dan alur lupa password.
- Dashboard Admin dengan 14 menu: dashboard, absensi, siswa, guru, kelas, jadwal, perangkat, monitoring, RFID, log, laporan, pengaturan, koreksi, reset password.
- Dashboard Guru: dashboard, absensi, koreksi, siswa, jadwal, laporan.
- Dashboard Siswa: dashboard, absensi, koreksi, jadwal.
- Koreksi absensi dengan pemilihan data absensi dan pembatalan pengajuan oleh siswa.
- Ekspor laporan CSV berdasarkan rentang tanggal.
- Mode **Dari Alat RFID** yang nyata: pilih perangkat Online, tekan **Baca UID dari Alat**, konfirmasi Oke/Batal sebelum dan sesudah pembacaan, lalu UID otomatis mengisi form pendaftaran.
- Nama siswa dipilih dari dropdown dengan pencarian (nama/NIS/kelas), bukan diketik bebas; NIS dan kelas tampil otomatis dan tidak dapat diubah.
- UID terkunci di mode alat, tombol **Selesai** membersihkan sesi, dan perangkat kembali ke mode absensi otomatis.

### 4.3 Firmware

Firmware baru berada di (versi yang dipakai repo ini: `firmware/PresentechAlat/`,
lihat `firmware/README.md` dan `firmware/WIRING.md`):

```text
D:\Coding\Arduino\PresenTech_alat\
  PresenTech_alat.ino
  config.h
  config.example.h
```

Alur firmware:

1. Inisialisasi LCD, SPI, MFRC522, dan buzzer.
2. Menghubungkan WiFi dengan timeout dan reconnect otomatis.
3. Mengirim heartbeat setiap 3 detik agar perintah dari web cepat diterima.
4. Membaca UID kartu dan menormalkannya menjadi uppercase 2 digit hex.
5. Mengirim `{"uid":"..."}` ke `/api/attendance` dengan device key.
6. Menampilkan hasil dari server pada LCD dan membunyikan buzzer.
7. Jika heartbeat meminta restart, firmware mengirim ack lalu restart.
8. Jika heartbeat meminta scan, firmware mereset medan RF, membangunkan kartu dengan WUPA (termasuk kartu yang masih HALT setelah absensi), mengirim hasil ke `/api/device/scan-result`, lalu kembali ke mode absensi.

Pesan LCD utama:

| Kondisi | Tampilan |
|---|---|
| Siaga | SMK TELESANDI / PRESENTECH / IP / TAP KARTU |
| Berhasil | ABSENSI BERHASIL / nama / kelas / status + jam |
| Duplikat | SUDAH ABSEN / nama / JAM / TIDAK DICATAT ULANG |
| Kartu tidak terdaftar | KARTU / TIDAK TERDAFTAR / DAFTARKAN DI WEB |
| Device key salah | DEVICE KEY SALAH / PERIKSA CONFIG.H |
| Maintenance | ALAT MAINTENANCE / ABSEN DITOLAK |
| Mode baca UID | MODE BACA UID / TEMPELKAN KARTU / PERINTAH DARI WEB |
| UID terkirim | UID TERBACA / UID / TERKIRIM KE WEB / ABSENSI AKTIF |
| Scan dibatalkan | SCAN DIBATALKAN / KEMBALI KE MODE ABSENSI |
| Scan kedaluwarsa | SCAN TIMEOUT / TIDAK ADA KARTU / KEMBALI KE ABSENSI / ULANGI DARI WEB |
| Server/WiFi mati | SERVER OFFLINE / TIDAK ADA KONEKSI |

Konfigurasi rahasia tidak ditulis di laporan. Nilai sebenarnya berada di `config.h`:

```cpp
#define WIFI_SSID "..."
#define WIFI_PASSWORD "..."
#define API_BASE_URL "http://192.168.1.5:8000/api"
#define DEVICE_KEY "ptd_..."
#define FIRMWARE_VERSION "1.1.0"
```

### 4.4 Keamanan dan Konsistensi Data

- UID dinormalkan sebelum pencarian, sehingga `a1:b2:c3:d4` cocok dengan `A1B2C3D4`.
- Perangkat tidak dapat menentukan tanggal, jam, atau status palsu.
- Satu siswa hanya dicatat sekali per tanggal dari perangkat.
- Device key disimpan sebagai hash dan hanya ditampilkan sekali saat dibuat/dirotasi.
- Perangkat Maintenance tidak dapat mencatat absensi.
- Rate limit melindungi login dan endpoint perangkat.
- Waktu server memakai zona Asia/Jakarta sesuai pengaturan aplikasi.

---

## BAB 5 PENGUJIAN

### 5.1 Pengujian Otomatis Backend

Perintah:

```bash
php artisan test --compact
```

Hasil:

```text
83 tests, 305 assertions, passed
```

Cakupan baru:

- Heartbeat dengan device key valid, tanpa key, dan key salah.
- Perangkat Maintenance tetap Maintenance setelah heartbeat.
- Perintah restart dilaporkan pada heartbeat dan dihapus setelah ack.
- Command penandaan perangkat offline.
- Sesi baca UID: permintaan Admin, flag pada heartbeat, hasil UID ternormalisasi, batal, kedaluwarsa, dan penolakan perangkat Offline/Maintenance.
- Normalisasi UID pada unit test, absensi, pendaftaran kartu, dan filter kartu.
- Regresi absensi perangkat dan koreksi.

### 5.2 Pengujian Frontend

```text
npm run build  -> berhasil, dist terbentuk
npm run lint   -> 0 error, 9 warning set-state-in-effect pada AdminDashboard
```

### 5.3 Pengujian Integrasi API

- `GET /api/health` dari alamat LAN `http://192.168.1.5:8000/api/health` berhasil.
- Heartbeat dengan device key berhasil dan status perangkat menjadi Online.
- Absensi dengan UID tidak terdaftar mengembalikan 404 tanpa membuat data.
- Server dijalankan dengan `--host=0.0.0.0` agar dapat diakses perangkat.
- Uji alur scan end-to-end via API: permintaan `waiting` → hasil `039D7641` → polling `completed` → batal `idle`.
- Firmware berhasil dikompilasi dengan `arduino-cli` untuk FQBN `esp32:esp32:esp32` (988.172 byte, 75% flash; RAM global 15%) dan di-upload ke board COM19 dengan hash terverifikasi.

### 5.4 Pengujian yang Belum Dilakukan

- UAT tap kartu absensi dan verifikasi LCD/buzzer.
- UAT pendaftaran kartu lewat dropdown siswa di dashboard.
- Uji restart dari dashboard ke perangkat fisik.
- Uji dari perangkat lain di jaringan sekolah (firewall Windows perlu diizinkan).
- Jalankan checklist UAT penuh di Lampiran C.
- UAT dengan pengguna sebenarnya.

---

## BAB 6 HASIL DAN PEMBAHASAN

### 6.1 Capaian

| Komponen | Bobot | Sebelum | Setelah pekerjaan ini | Setelah UAT |
|---|---:|---:|---:|---:|
| Backend/API | 25% | 90% | 98% | 98% |
| Frontend web | 25% | 85% | 90% | 93% |
| Firmware | 20% | 70% | 88% | 96% |
| Integrasi alat–web | 20% | 10% | 75% | 96% |
| Dokumentasi | 10% | 30% | 100% | 100% |
| **Total estimasi** |  | **±63%** | **±90%** | **±96%** |

Angka ini adalah estimasi berbobot untuk keperluan laporan, bukan hasil UAT formal. Firmware sudah dikompilasi dan API sudah diuji (termasuk alur scan), tetapi board belum di-flash.

### 6.2 Kelebihan

- Server menjadi sumber kebenaran untuk identitas, waktu, dan status.
- Firmware lebih sederhana karena tidak lagi menyimpan daftar siswa.
- Perangkat dapat dipantau dan di-restart dari dashboard.
- UID dapat dibaca langsung dari alat melalui dashboard, tanpa mengetik manual.
- UID tidak lagi sensitif terhadap huruf besar/kecil dan pemisah.
- Pengujian backend mencakup alur perangkat, scan UID, dan koreksi.

### 6.3 Keterbatasan

- Tidak ada antrean offline; jika server mati, tap tidak tercatat.
- Belum ada backup/restore basis data dari dashboard.
- Laporan hanya CSV.
- MQTT belum diimplementasikan.
- UAT perangkat keras menyeluruh belum selesai.
- Mode baca UID memakai polling heartbeat 3 detik, bukan push real-time.
- Konfirmasi Oke/Batal hanya ada di web; alat tidak memiliki tombol fisik.
- Firewall Windows perlu diizinkan secara manual untuk akses dari perangkat lain.

### 6.4 Rencana Pengembangan

1. Kompilasi dan flash firmware ke board.
2. Uji kartu asli, duplikat, kartu tidak terdaftar, dan maintenance.
3. Tambahkan antrean offline menggunakan Preferences/LittleFS.
4. Tambahkan laporan PDF/Excel dan backup basis data.
5. Implementasikan MQTT untuk monitoring dan restart yang lebih real-time.
6. Lakukan UAT dengan Admin, Guru, dan Siswa.

---

## BAB 7 PENUTUP

### 7.1 Kesimpulan

PresenTech telah memiliki aplikasi web, API, dan firmware yang saling terhubung secara desain. Backend teruji dengan 83 tes otomatis, frontend berhasil dibangun, dan firmware baru sudah menggunakan API PresenTech, bukan Google Apps Script. Fitur baca UID dari dashboard juga sudah tersedia, diuji melalui API, dan firmware telah di-upload ke board. Pekerjaan yang tersisa berpusat pada UAT perangkat keras menyeluruh.

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

## LAMPIRAN B — Perintah Operasional

```bash
cd C:\laragon\www\pkk\PresenTech-Backend
php artisan serve --host=0.0.0.0 --port=8000
php artisan test --compact
php artisan devices:mark-offline
```

Firewall Windows (jalankan PowerShell sebagai Administrator):

```powershell
New-NetFirewallRule -DisplayName "PresenTech API 8000" -Direction Inbound -Action Allow -Protocol TCP -LocalPort 8000
```

## LAMPIRAN C — Catatan Rilis

- Tanggal: 26 Oktober 2026.
- Versi firmware: 1.1.0 (mode baca UID + heartbeat 3 detik).
- Perangkat contoh: `ESP32-RPL-01`.
- API lokal: `http://192.168.1.5:8000/api`.
- Firmware: `D:\Coding\Arduino\PresenTech_alat`.
- Laporan: `C:\laragon\www\pkk\docs`.
