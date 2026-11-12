# Firmware Alat PresenTech (ESP32)

Kode alat absensi RFID: **ESP32 + MFRC522 + LCD I2C 20x4 + buzzer**.
Firmware ini berbicara ke API Laravel dengan header `X-Device-Key`, sehingga alat
tidak menyimpan data siswa — server tetap menjadi sumber kebenaran.

| Fitur | Status |
|---|---|
| Tap kartu → absensi masuk/pulang | ✅ |
| Heartbeat 15 detik + status Online | ✅ |
| Mode **Baca UID** dari dashboard | ✅ |
| **Restart** dari dashboard | ✅ |
| **Ganti WiFi** dari dashboard / portal | ✅ |
| Antrean offline (maks 30 tap) + batch sync | ✅ |
| OTA firmware dari dashboard | ✅ |
| Jam masuk/pulang & nama alat di LCD | ✅ |
| Portal setup WiFi tanpa flash ulang | ✅ |

Wiring dan daftar komponen: **[WIRING.md](WIRING.md)**

---

## 1. Isi folder

```
firmware/
├─ WIRING.md                  # skema pin + catatan daya
└─ PresentechAlat/
   ├─ PresentechAlat.ino      # firmware utama
   ├─ config.example.h        # template konfigurasi (disalin jadi config.h)
   └─ config.h                # dibuat sendiri, TIDAK ikut Git
```

---

## 2. Yang dibutuhkan

- **Arduino IDE 2.x** (atau Arduino CLI).
- Board package **esp32 by Espressif** — diuji pada **3.3.8** (core 3.x).
- Library dari Library Manager:

  | Library | Versi diuji | Pemasang |
  |---|---|---|
  | MFRC522 | 1.4.12 | GithubCommunity |
  | ArduinoJson | 7.4.3 (v6 juga didukung) | Benoit Blanchon |
  | LiquidCrystal I2C | 1.1.2 | Frank de Brabander |

  > Arduino IDE mungkin menampilkan peringatan “library claims to run on avr
  > architecture … may be incompatible”. Abaikan — library ini tetap berjalan di
  > ESP32 dan sudah diuji kompilasi.

---

## 3. Konfigurasi

1. Salin `config.example.h` menjadi `config.h` di folder yang sama.
2. Isi minimal empat nilai berikut:

   ```cpp
   #define WIFI_SSID      "WIFI_SEKOLAH"
   #define WIFI_PASSWORD  "password_wifi"
   #define API_BASE_URL   "http://192.168.1.6:8000/api"
   #define DEVICE_KEY     "ptd_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX"
   ```

   - `API_BASE_URL` **wajib** berakhiran `/api` dan memakai IP komputer server
     (bukan `127.0.0.1`). Mode hotspot Windows selalu `192.168.137.1`.
   - `DEVICE_KEY` didapat dari dashboard: **Perangkat IoT → Tambah Perangkat**.
     Key hanya ditampilkan sekali.
   - Untuk alat kedua/ketiga, ubah juga `DEVICE_ID` (mis. `ESP32-TKJ-01`) dan
     pakai device key yang berbeda. Satu key = satu alat.

3. `config.h` **tidak akan ikut ter-commit** (berisi password WiFi dan device key).
   Jangan memaksa menambahkannya ke Git.

---

## 4. Upload firmware

Di Arduino IDE:

1. Buka `firmware/PresentechAlat/PresentechAlat.ino`.
2. **Tools → Board → esp32 → ESP32 Dev Module**.
3. **Tools → Partition Scheme → Minimal SPIFFS (1.9MB APP with OTA)**.

   > Wajib untuk OTA. Firmware memakai ±1,17 MB (59% dari 1,9 MB). Dengan skema
   > bawaan “Default 4MB with spiffs”, `Update.begin()` akan gagal karena tidak ada
   > partisi OTA.
4. **Tools → Upload Speed → 921600** (turunkan ke 460800 bila gagal).
5. Pilih port, klik **Upload**.
6. Buka **Serial Monitor**, baud **115200**.

Firmware hasil kompilasi (core 3.3.8, partition min_spiffs):

```
Sketch uses 1.166.872 bytes (59%) of program storage space. Maximum is 1.960.080 bytes.
Global variables use 52.968 bytes (16%) of dynamic memory, leaving 274.712 bytes.
```

---

## 5. Pertama kali menyala

1. Alat menyambung ke WiFi dari `config.h`.
2. Serial Monitor menampilkan IP alat dan boot ID.
3. Alat mengirim heartbeat → status berubah **Online** di menu **Perangkat IoT**.
4. LCD siaga menampilkan:

   ```
   SMK TELESANDI
   Gerbang RPL 1
   MSK 05:40  PLG 15:00
   TAP KARTU     07:10
   ```

5. Tempel kartu yang sudah didaftarkan (menu **Kartu RFID**):

   ```
   ABSENSI BERHASIL
   Aulia Rahma
   XII RPL 1
   Hadir  06:58
   ```

Bila perangkat dibiarkan dalam mode **Maintenance** di dashboard, semua tap akan
ditolak (`403`) dan LCD menampilkan `ALAT MAINTENANCE`.

---

## 6. Perintah Serial Monitor (115200)

| Perintah | Fungsi |
|---|---|
| `status` | WiFi, IP, server, kode heartbeat, antrean, heap. |
| `portal` | Buka portal setup WiFi tanpa menahan tombol BOOT. |
| `srv http://192.168.1.6:8000/api` | Ganti URL server saja, tersimpan di NVS. |
| `key ptd_...` | Ganti device key tanpa flash ulang (mis. setelah Rotate Key). |
| `beep` | Tes buzzer. |
| `otareset` | Hapus penanda OTA gagal agar versi yang sama boleh dicoba lagi. |
| `factory` | Hapus semua konfigurasi NVS + antrean, lalu restart. |
| `help` | Daftar perintah. |

---

## 7. Ganti WiFi tanpa flash ulang

Ada dua cara:

**A. Dari dashboard (alat harus Online)**

1. Perangkat IoT → tombol **⋮** pada alat → **Ganti WiFi**.
2. Isi SSID & password baru → **Kirim**.
3. Alat menerima konfigurasi pada heartbeat berikutnya (±15 detik), menyimpan ke NVS,
   mengirim `wifi-ack`, lalu restart.
4. Bila WiFi baru salah, alat gagal tersambung dan otomatis membuka portal setup
   setelah ±30 detik.

**B. Portal setup (tanpa dashboard)**

1. Tahan tombol **BOOT (GPIO0)** 3 detik sampai LCD menampilkan `SETUP WIFI`.
2. Sambungkan laptop/HP ke WiFi `PresenTech-Setup` (sandi `presen123`).
3. Buka `http://192.168.4.1`, isi SSID, password, dan URL server.
4. **SIMPAN & RESTART**. Alat restart dan menyambung ke WiFi baru.

> Portal juga terbuka otomatis bila alat tidak berhasil menyambung WiFi dalam 30 detik
> pertama. Portal menutup sendiri (restart) setelah 10 menit tanpa konfigurasi.

---

## 8. Antrean offline

- WiFi putus atau server mati → tap disimpan di NVS (maks 30, yang tertua dibuang).
- Setiap event punya `client_event_id` unik, sehingga pengiriman ulang tidak
  menghasilkan absensi ganda.
- Saat online, alat mengirim maksimal 20 event tiap 30 detik ke
  `POST /api/device/attendance/batch` dan menghapus event yang sudah diproses server.
- LCD menampilkan jumlah antrean pada baris bawah, mis. `3Q 07:10`.
- `tapped_at` dikirim hanya bila jam alat sudah sinkron NTP; jika belum,
  `time_verified: false` dan server memakai jam server.

---

## 9. OTA firmware

1. Naikkan `FIRMWARE_VERSION` di `config.h`.
2. Arduino IDE: **Sketch → Export Compiled Binary** → ambil file `.bin`.
3. Dashboard Admin → **Firmware** → unggah `.bin` + isi versi (harus sama dengan
   `FIRMWARE_VERSION`).
4. **Kirim ke perangkat**. Pada heartbeat berikutnya alat mengunduh, memeriksa SHA-256,
   menulis partisi OTA, melapor status, lalu restart.
5. Verifikasi di menu Perangkat IoT: versi firmware harus berubah.

Catatan:
- Unduhan memakai URL bertanda tangan, tanpa login.
- Bila checksum/unduhan gagal, versi tersebut ditandai gagal dan tidak dicoba terus
  menerus. Setelah masalah diperbaiki, kirim `otareset` lewat Serial atau naikkan versi.
- Tanpa partition scheme ber-OTA, fitur ini tidak bisa dipakai — update manual via USB.

---

## 10. Troubleshooting

| Gejala | Penyebab | Solusi |
|---|---|---|
| `config.h belum ada` saat compile | Template belum disalin | `config.example.h` → `config.h`. |
| Kompilasi kehabisan flash | Partition “Default 4MB with spiffs” | Pilih **Minimal SPIFFS (1.9MB APP with OTA)**. |
| LCD kosong | Alamat I2C / kontras | Jalankan I2C scanner, ubah `LCD_I2C_ADDR`. |
| Kartu tidak terbaca | 5V ke MFRC522 / kabel SPI panjang | Cek 3.3V, pendekkan kabel (lihat [WIRING.md](WIRING.md)). |
| Status tetap Offline | `API_BASE_URL` salah / beda jaringan | Buka `{API}/health` dari HP; perbaiki URL. |
| 401 Device key salah | Key salah/ter-rotate | Salin ulang atau `key ptd_...`. |
| 409 saat tap | Sudah absen / pulang terlalu awal | Normal — LCD menampilkan pesan server. |
| 429 Too Many Requests | Heartbeat terlalu cepat | Jangan turunkan `HEARTBEAT_MS` di bawah 2000. |
| Alat restart berulang | `restart_requested` tidak ter-ack, kredensial WiFi salah | Kirim `status`, cek WiFi, pastikan `boot_id` terkirim (sudah otomatis). |
| OTA selalu gagal di 0% | Server tidak bisa diakses alat / URL memakai localhost | Pastikan alat bisa membuka URL server; pakai IP LAN. |
| WiFi dari dashboard tidak berubah | Alat offline | Alat harus Online agar menerima heartbeat berisi kredensial. |

---

## 11. Ringkasan endpoint yang dipakai

Semua request memakai `Content-Type: application/json` dan `X-Device-Key: ptd_...`.

| Method | Endpoint | Fungsi |
|---|---|---|
| `GET` | `/api/health` | Cek server (manual, via browser/HP). |
| `POST` | `/api/device/heartbeat` | Denyut + ambil jam, tampilan LCD, perintah. |
| `POST` | `/api/attendance` | Tap kartu (masuk/pulang). |
| `POST` | `/api/device/attendance/batch` | Kirim antrean offline. |
| `POST` | `/api/device/scan-result` | Hasil mode baca UID. |
| `POST` | `/api/device/restart-ack` | Konfirmasi restart. |
| `POST` | `/api/device/wifi-ack` | Konfirmasi kredensial WiFi tersimpan. |
| `POST` | `/api/device/ota-ack` | Laporan status OTA. |
| `GET` | `/api/firmware/{id}/download` | Unduh firmware (URL bertanda tangan). |

Panduan kontrak API selengkapnya: [`docs/PANDUAN-SAMBUNG-ALAT-ESP32.md`](../docs/PANDUAN-SAMBUNG-ALAT-ESP32.md).
