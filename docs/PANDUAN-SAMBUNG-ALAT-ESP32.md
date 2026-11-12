# Panduan Menyambungkan Alat ESP32 ke Web PresenTech

Dokumen ini menjelaskan cara menyambungkan perangkat pembaca kartu RFID berbasis ESP32
ke aplikasi web PresenTech: mulai dari mendaftarkan alat, mengambil device key, mencari
alamat server, mengirim heartbeat, sampai mencatat absensi saat kartu ditempel.

> **Firmware siap pakai ada di repo ini:** [`firmware/PresentechAlat`](../firmware/PresentechAlat)
> (konfigurasi, upload, OTA: [`firmware/README.md`](../firmware/README.md); skema pin:
> [`firmware/WIRING.md`](../firmware/WIRING.md)). Contoh sketch di bagian 13 dokumen ini
> adalah versi ringkas untuk memahami alur, bukan pengganti firmware tersebut.

Panduan ini mengikuti kontrak API yang benar-benar dipakai backend
(`routes/api.php`, `EnsureDeviceKey`, `DeviceController`, `AttendanceController`),
bukan asumsi. Contoh nilai di bawah memakai kondisi lab saat ini:

| Item | Nilai contoh |
| --- | --- |
| Server PresenTech | `192.168.1.6` (port `8000`) |
| API base | `http://192.168.1.6:8000/api` |
| Alat #1 | `ESP32-RPL-01` — "Gerbang RPL 1", IP `192.168.1.8`, firmware `1.6.0`, **Online** |
| Jam absensi | masuk `05:40`, batas terlambat `06:30`, pulang `15:00`, zona `Asia/Jakarta` |

> Catatan: komputer server bisa punya dua IP (mis. `192.168.1.5` Wi-Fi dan `192.168.1.6`
> Ethernet). Pakai IP yang **satu jaringan dengan alat**. Discovery UDP otomatis
> menjawab IP yang benar, jadi cara ini paling aman.
>
> **Mode hotspot laptop (Windows):** IP server di jaringan hotspot selalu
> `192.168.137.1`. API base menjadi `http://192.168.137.1:8000/api`. ESP32 hanya
> mendukung **2.4 GHz**, jadi atur band hotspot ke 2.4 GHz (Settings → Network &
> internet → Mobile hotspot → Network band).

---

## 1. Gambaran arsitektur

```text
┌─────────────┐   WiFi sekolah    ┌──────────────────────┐   ┌────────────┐
│  ESP32 +    │  HTTP (port 8000) │  Laravel API         │   │  MySQL     │
│  MFRC522    │ ────────────────► │  /api/device/...     │ ─►│  devices,  │
│  + LCD      │ ◄──────────────── │  /api/attendance     │   │  attendance│
└─────────────┘   JSON + header   └──────────┬───────────┘   └────────────┘
       │            X-Device-Key             │
       │                                     │
       │  UDP 8888 (opsional)                │ HTTPS/HTTP
       │  "PRESENTECH_DISCOVERY"             ▼
       └──────────────────────────►  ┌──────────────────┐
                                     │ Dashboard React  │
                                     │ /app (Admin)     │
                                     └──────────────────┘
```

Alur singkatnya:

1. **Daftar alat** di dashboard Admin → server membuat **device key** (`ptd_...`).
2. Alat **menemukan server** lewat discovery UDP atau URL yang ditulis di firmware.
3. Alat mengirim **heartbeat** tiap 10–30 detik → status berubah **Online** di dashboard.
4. Kartu ditempel → alat `POST /api/attendance` → LCD menampilkan hasil dari server.
5. WiFi putus → tap disimpan di memori alat → dikirim menyusul lewat **batch**.
6. Perintah dari dashboard (baca UID, restart, OTA) ikut dikirim di **balasan heartbeat**.

---

## 2. Prasyarat

- [ ] MySQL/Laragon menyala dan database PresenTech bisa diakses.
- [ ] Backend berjalan dan bisa diakses dari perangkat lain:
      `php artisan serve --host=0.0.0.0 --port=8000`
      (tanpa `--host=0.0.0.0`, server hanya bisa diakses dari komputer sendiri).
- [ ] ESP32 dan komputer server berada di **jaringan/WiFi yang sama**.
- [ ] Firewall Windows mengizinkan **TCP 8000** (HTTP API) dan **UDP 8888** (discovery).
- [ ] Alat sudah terpasang firmware yang mengirim HTTP (contoh sketch di bagian 13).
- [ ] Kartu RFID siswa sudah terdaftar di menu **Kartu RFID** (kalau belum, lihat mode
      baca UID di bagian 10).

Cek cepat dari laptop lain / HP di WiFi yang sama:

```text
http://192.168.1.6:8000/api/health
```

Harus membalas:

```json
{ "success": true, "service": "PresenTech API", "timestamp": "..." }
```

Kalau alamat ini tidak terbuka dari perangkat lain, alat juga tidak akan bisa connect.

---

## 3. Langkah 1 — Daftarkan alat dan salin device key

1. Login dashboard sebagai **Admin**.
2. Buka menu **Perangkat IoT**.
3. Klik **Tambah Perangkat**, isi:
   - **Nama**: mis. `ESP32-RPL-01`
   - **Device ID**: boleh dikosongkan (server membuat otomatis `DEV-YYYYMMDDHHMMSS-XXXX`)
   - **Nama Tampilan**: teks pendek untuk LCD, mis. `Gerbang RPL 1`
   - **Lokasi**: mis. `Lab RPL 1`
   - **Warna Tema**: mis. `#1f6feb`
   - **Tipe Koneksi**: `WiFi`
4. Simpan. **Device key muncul satu kali** di respons/notifikasi, formatnya:

   ```text
   ptd_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX
   ```

   Salin ke firmware (`DEVICE_KEY`). Server hanya menyimpan **hash SHA-256**-nya,
   jadi key asli **tidak bisa dilihat lagi** dari dashboard.

5. Kalau key hilang atau alat lama diganti:
   buka detail perangkat → **Rotate Key** → salin key baru → tulis ulang di firmware.
   Key lama langsung tidak berlaku.

Perangkat baru akan berstatus **Offline** sampai heartbeat pertama masuk.

> Alat #1 (`ESP32-RPL-01`) sudah punya key dan sudah Online.
> Alat #2 (`ESP32-RPL-02`) dan #3 (`ESP32-TKJ-01`) belum punya key — lakukan
> **Rotate Key** untuk masing-masing sebelum dipakai.

---

## 4. Langkah 2 — Alat menemukan alamat server

### Cara A — Discovery otomatis (disarankan)

Di komputer server, jalankan listener:

```bash
php artisan devices:discovery
```

Alat mengirim paket **UDP broadcast ke port 8888** berisi teks:

```text
PRESENTECH_DISCOVERY
```

Server membalas satu atau beberapa JSON (satu per IP LAN yang aktif):

```json
{
  "api_base": "http://192.168.1.6:8000/api",
  "ip": "192.168.1.6",
  "service": "PresenTech"
}
```

Alat menyimpan `api_base` lalu memakai alamat itu untuk semua request berikutnya.
Kalau alat menerima beberapa balasan (server punya Wi-Fi + Ethernet), coba satu per
satu sampai `GET {api_base}/health` berhasil.

Opsi command:

```bash
php artisan devices:discovery --port=8888 --http-port=8000
php artisan devices:discovery --ip=192.168.1.6   # paksa satu IP
```

Syarat discovery: satu subnet, UDP 8888 tidak diblokir firewall, dan listener
dijalankan **sebelum** alat mencari server.

### Cara B — Tulis alamat manual di firmware

Kalau jaringan memblokir broadcast UDP, tulis langsung:

```cpp
const char* API_BASE = "http://192.168.1.6:8000/api";
```

Pastikan IP server tidak berubah. Gunakan **DHCP reservation** di router atau IP statis,
supaya alat tidak perlu di-flash ulang setiap server ganti IP.

> Perhatikan akhiran `/api`. Base URL yang benar: `http://IP:8000/api`.
> Endpoint lengkap menjadi `http://IP:8000/api/device/heartbeat`, dst.

### Cara C — Ganti WiFi alat (tanpa flash ulang)

Firmware `PresenTech_alat.ino` menyimpan SSID/password di **NVS**, sehingga
mengubah `config.h` saja **tidak** mengubah alat yang sudah pernah dikonfigurasi.
Ada tiga cara, pilih yang paling mudah:

**C1 — Dari dashboard (alat harus online)**

1. Buka menu **Perangkat IoT** → tombol **⋮** pada alat → **Ganti WiFi**.
2. Isi **Nama WiFi (SSID)** dan **Password WiFi** baru, lalu klik **Kirim**.
3. Alat menerima konfigurasi pada heartbeat berikutnya (±3 detik), menyimpannya
   ke NVS, lalu **restart otomatis**. Kejadian ini tercatat di **Log Sistem**
   sebagai `device.wifi_requested` dan `device.wifi_applied`.
4. Jika WiFi baru salah, alat gagal tersambung dan otomatis membuka portal
   setup setelah ±20 detik (lanjut ke C2 nomor 3–5).

> Password WiFi disimpan **terenkripsi** di database dan langsung dihapus
> setelah alat mengonfirmasi. SSID terakhir tetap ditampilkan di dashboard.

**C2 — Tahan tombol BOOT 3 detik (alat sedang menyala)**

1. Nyalakan hotspot laptop dan pastikan band-nya **2.4 GHz**.
2. Tahan tombol **BOOT (GPIO0)** selama **3 detik** sampai LCD menampilkan
   `SETUP WIFI`. Tidak perlu mencabut listrik.
3. Di laptop/HP, sambungkan WiFi ke **`PresenTech-Setup`** dengan sandi
   **`presen123`**. (Koneksi internet laptop terputus sementara — normal.)
4. Buka browser ke **`http://192.168.4.1`**.
5. Isi form:
   - **WiFi (SSID):** `ACER NITRO 5 AN515-57`
   - **Password WiFi:** `123456789`
   - **URL Server:** `http://192.168.137.1:8000/api`
6. Klik **SIMPAN & RESTART**. Alat restart, masuk ke hotspot, dan mendapat IP
   `192.168.137.x`.

**C3 — BOOT saat alat dinyalakan (cadangan)**

Matikan alat, **tahan tombol BOOT (GPIO0)** lalu nyalakan; lepas setelah LCD
menampilkan `SETUP MODE`. Setelah itu ikuti C2 nomor 3–5.

Alternatif tanpa menekan tombol: sambungkan USB, buka Serial Monitor 115200,
kirim perintah `portal`.

Cek dari laptop:

```powershell
Get-NetNeighbor -AddressFamily IPv4 | Where-Object { $_.IPAddress -like '192.168.137.*' }
Invoke-RestMethod http://192.168.137.1:8000/api/health
```

Status alat akan berubah **Online** di menu Perangkat IoT setelah heartbeat pertama.

Perintah Serial Monitor yang berguna:

| Perintah | Fungsi |
| --- | --- |
| `portal` | Buka portal setup WiFi tanpa menekan BOOT |
| `status` | Tampilkan status WiFi, IP, server, antrean, heap |
| `srv http://192.168.137.1:8000/api` | Ganti URL server saja (tanpa ganti WiFi) |

> Jika alat di-flash ulang dari nol dan ingin memakai nilai `config.h`, hapus
> dulu NVS (`esptool erase_flash`) atau tetap gunakan portal sekali. Tanpa itu,
> kredensial lama di NVS yang dipakai.

---

## 5. Langkah 3 — Autentikasi `X-Device-Key`

Semua endpoint perangkat wajib menyertakan header:

```http
X-Device-Key: ptd_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX
Content-Type: application/json
```

Cara server memeriksa:

- Key di-hash SHA-256 lalu dicocokkan dengan kolom `devices.device_key`.
- Key tidak dikenal / kosong → **401** `{"message":"Device key tidak valid."}`
- Key valid tetapi dipakai ke perangkat lain (mis. `POST /api/devices/2/heartbeat`
  dengan key alat #1) → **403** `{"message":"Device key tidak cocok dengan perangkat ini."}`

Satu key = satu alat. Jangan menaruh key yang sama di beberapa ESP32.

---

## 6. Langkah 4 — Heartbeat (wajib)

Heartbeat adalah denyut alat: memberi tahu server bahwa alat hidup, sekaligus
mengambil jam absensi, teks LCD, dan perintah dari dashboard.

**Endpoint**

```http
POST /api/device/heartbeat
X-Device-Key: ptd_...
Content-Type: application/json
```

**Body request** (semua field opsional, tapi dianjurkan):

```json
{
  "ip_address": "192.168.1.8",
  "firmware_version": "1.8.0",
  "boot_id": "8f3a1c92",
  "firmware_hash": "ab12cd34",
  "capabilities": [
    "display_text", "display_theme", "boot_id",
    "firmware_hash", "attendance_window", "ota", "scan", "offline_queue", "wifi_config"
  ]
}
```

| Field | Arti |
| --- | --- |
| `ip_address` | IP alat saat ini (ditampilkan di dashboard) |
| `firmware_version` | Versi firmware, dipakai untuk cek update |
| `boot_id` | ID acak **baru setiap alat menyala**; server memakainya untuk tahu alat baru boot |
| `firmware_hash` | Checksum firmware (hex), opsional |
| `capabilities` | Fitur yang didukung firmware; server balas daftar fitur yang dikenal |

**Contoh balasan `200`:**

```json
{
  "message": "Heartbeat diterima.",
  "device": { "id": 1, "device_id": "ESP32-RPL-01", "...": "..." },
  "server_time": "2026-11-05T07:10:00+07:00",
  "server_date": "2026-11-05",
  "entry_time": "05:40",
  "late_time": "06:30",
  "home_time": "15:00",
  "check_out_active": false,
  "timezone": "Asia/Jakarta",
  "restart_requested": false,
  "boot_acknowledged": false,
  "scan_requested": false,
  "scan_expires_in": 0,
  "firmware": { "current": "1.6.0", "latest": null, "update_available": false },
  "ota": null,
  "display": {
    "device_name": "Gerbang RPL 1",
    "device_name_short": "Gerbang RPL 1",
    "device_name_initials": "GR1",
    "school_label": "PresenTech",
    "theme_color": "#1f6feb",
    "date_label": "Kam, 5 Nov 2026",
    "greeting": "Selamat pagi",
    "clock": "07:10:00",
    "day_label": "Kamis",
    "state": "masuk"
  },
  "capabilities": ["display_text", "display_theme", "boot_id", "firmware_hash", "attendance_window", "ota", "scan", "offline_queue", "wifi_config"]
}
```

Saat Admin menekan **Ganti WiFi** di dashboard, balasan heartbeat juga memuat
tiga field tambahan (hanya selama permintaan masih menunggu):

```json
{
  "wifi_setup_ssid": "ACER NITRO 5 AN515-57",
  "wifi_setup_password": "123456789",
  "wifi_setup_token": "a1b2c3d4e5f6g7h8"
}
```

Firmware harus menyimpan SSID/password ke NVS, menyimpan token, lalu memanggil
`POST /api/device/wifi-ack` dengan body `{"token":"..."}` sebelum restart.
Token di NVS mencegah restart berulang bila ack pertama gagal terkirim.

**Yang harus dilakukan firmware:**

| Field balasan | Aksi di alat |
| --- | --- |
| `server_time`, `entry_time`, `late_time`, `home_time`, `check_out_active` | Sinkronkan jam LCD dan tampilkan jadwal masuk/pulang |
| `display.*` | Gambar nama alat, label sekolah, sapaan, jam, status (`masuk`/`terlambat`/`pulang`) |
| `wifi_setup_ssid`, `wifi_setup_password`, `wifi_setup_token` | Simpan kredensial ke NVS, kirim `POST /api/device/wifi-ack`, lalu restart |
| `restart_requested == true` | Restart alat, lalu kirim `POST /api/device/restart-ack` |
| `scan_requested == true` | Masuk mode baca kartu selama `scan_expires_in` detik (lihat bagian 10) |
| `firmware.update_available` / `ota != null` | Tawarkan/unduh update (lihat bagian 10) |
| `capabilities` | Pilih fitur yang didukung; firmware lama boleh mengabaikan field baru |

**Ritme yang disarankan:** setiap **10–30 detik**. Batas server **60 request/menit
per device key**, jadi jangan lebih cepat dari 1 detik sekali.

`boot_id` yang berubah menandakan alat baru menyala. Kalau sebelumnya ada permintaan
restart yang menggantung, server menganggapnya selesai (`boot_acknowledged: true`)
dan tidak mengirim `restart_requested` lagi.

---

## 7. Langkah 5 — Tap kartu = absensi

**Endpoint**

```http
POST /api/attendance
X-Device-Key: ptd_...
Content-Type: application/json

{ "uid": "A1B2C3D4" }
```

`uid` boleh dikirim dengan/tanpa spasi, titik dua, atau huruf kecil — server
menormalkannya. Isi `uid` dengan hasil baca MFRC522 (biasanya 4/7 byte hex).

### Arti hasil

| HTTP | `outcome` | Arti | Tindakan alat |
| --- | --- | --- | --- |
| `201` | `created` | Absensi **masuk** tercatat (Hadir/Terlambat otomatis) | Tampilkan nama + status + jam |
| `200` | `checked_out` | Tap kedua setelah jam pulang → absensi **pulang** | Tampilkan "Pulang" |
| `409` | `duplicate` | Sudah absen / sudah pulang / tap pulang terlalu awal | Tampilkan pesan dari server |
| `404` | `unregistered` | Kartu tidak dikenal atau siswa nonaktif | Tampilkan "Kartu tidak terdaftar" |
| `403` | `maintenance` | Alat sedang mode Maintenance | Tampilkan "Alat maintenance" |
| `401` | — | Device key salah | Cek `X-Device-Key` |
| `429` | — | Terlalu banyak request | Perpanjang interval kirim |

### Contoh balasan `201 created`

```json
{
  "message": "Absensi berhasil dicatat.",
  "attendance": {
    "id": 512,
    "student_id": 31,
    "attendance_date": "2026-11-05",
    "attendance_time": "06:58:12",
    "status": "Hadir",
    "source": "RFID",
    "...": "..."
  },
  "display": {
    "student_name": "Aulia Rahma",
    "student_name_initials": "AR",
    "class_name": "XII RPL 1",
    "status": "Hadir",
    "time": "06:58"
  },
  "server_time": "2026-11-05T06:58:12+07:00",
  "server_date": "2026-11-05",
  "entry_time": "05:40",
  "late_time": "06:30",
  "home_time": "15:00",
  "check_out_active": false,
  "timezone": "Asia/Jakarta",
  "outcome": "created"
}
```

Untuk LCD 20×4, firmware memakai objek `display` dari heartbeat:
`device_name` (baris 1, maksimal 20 karakter), jam masuk/pulang dari
`entry_time`/`home_time` (baris 2, mis. `MSK 05:40  PLG 15:00`), status server
ringkas (baris 3), dan instruksi tap/antrean (baris 4). Nama sudah dipendekkan
server sehingga alat tidak perlu memotong kata sendiri.

### Contoh pesan `409` yang mungkin muncul

- `"Siswa sudah tercatat absensi pada tanggal ini."` → tap dobel
- `"Siswa sudah absen masuk pukul 06:58. Tap pulang dibuka mulai 15:00."` → pulang terlalu awal
- `"Siswa sudah tap pulang pukul 15:04."` → sudah pulang

Aturan penting:

- Status **Hadir/Terlambat** dihitung server dari jam server + pengaturan
  (`late_time`), bukan dari jam alat.
- Tap kedua baru dianggap **pulang** setelah `home_time` dan berjarak minimal
  10 menit dari jam masuk.
- Tap beruntun beberapa kartu berbeda dalam hitungan detik ditandai catatan
  "tap beruntun" untuk diverifikasi Guru/Admin (bukan langsung ditolak).

---

## 8. Langkah 6 — Antrean offline (batch sync)

Kalau WiFi putus, simpan tap di memori alat (LittleFS/SPIFFS/RTC memory), lalu
kirim menyusul. Setiap event **wajib punya `client_event_id` unik** — ini membuat
pengiriman ulang aman (idempoten, tidak menghasilkan baris ganda).

**Endpoint**

```http
POST /api/device/attendance/batch
X-Device-Key: ptd_...
Content-Type: application/json

{
  "events": [
    {
      "client_event_id": "ESP32-RPL-01-1730689200123",
      "uid": "A1B2C3D4",
      "tapped_at": "2026-11-05T06:58:12+07:00",
      "time_verified": true
    },
    {
      "client_event_id": "ESP32-RPL-01-1730689260456",
      "uid": "B2C3D4E5",
      "tapped_at": "2026-11-05T07:01:00+07:00",
      "time_verified": false
    }
  ]
}
```

Aturan payload:

| Aturan | Nilai |
| --- | --- |
| Jumlah event per kiriman | 1–50 |
| `client_event_id` | wajib, maks 64 karakter, unik dalam satu kiriman |
| `uid` | wajib, maks 64 karakter |
| `tapped_at` | opsional, format tanggal ISO-8601 |
| `time_verified` | opsional boolean; `false` = jam alat belum sinkron NTP |
| `schedule_id` | opsional, hanya dipakai bila cocok dengan kelas siswa |

**Balasan selalu `200`** (asal device key valid), dengan hasil per event:

```json
{
  "results": [
    { "client_event_id": "ESP32-RPL-01-1730689200123", "outcome": "created",      "status_code": 201, "attendance_id": 512 },
    { "client_event_id": "ESP32-RPL-01-1730689260456", "outcome": "duplicate",    "status_code": 409, "attendance_id": 512 }
  ]
}
```

Cara pakai di firmware:

1. Simpan event ke antrean lokal saat tap (termasuk saat offline).
2. Saat online, kirim maksimal 50 event per batch.
3. Hapus dari antrean **hanya** event yang sudah muncul di `results`.
4. Kalau koneksi putus di tengah jalan, kirim ulang batch yang sama — server
   mengenali `client_event_id` dan tidak membuat data dobel.

Kalau `tapped_at` lebih dari 7 hari lalu atau lebih dari 10 menit di masa depan,
server memakai jam server dan menambahkan catatan
"Waktu kiriman perangkat belum terverifikasi (NTP belum sinkron)."
Tetap kirim `time_verified: true` bila alat sudah sinkron NTP.

---

## 9. Batas request (rate limit)

| Endpoint | Batas |
| --- | --- |
| Semua endpoint `X-Device-Key` | **60 request/menit per device key** |
| `POST /api/login`, lupa password | 10/menit per identifier + 120/menit per IP |

Kalau lewat, server membalas **429**. Solusi: perpanjang interval heartbeat,
kirim batch (bukan satu-satu), dan jangan retry terlalu agresif (beri jeda 5–15 detik).

---

## 10. Fitur opsional (dipakai kalau firmware mendukung)

### 10.1 Baca UID dari dashboard (daftarkan kartu baru)

1. Admin buka detail perangkat → **Baca UID**.
2. Server menandai `scan_requested_at`; balasan heartbeat berikutnya berisi
   `scan_requested: true` dan `scan_expires_in` (maks 60 detik).
3. Alat masuk mode baca, tempel kartu, lalu kirim:

   ```http
   POST /api/device/scan-result
   X-Device-Key: ptd_...
   { "uid": "A1B2C3D4" }
   ```

4. Dashboard menampilkan UID tersebut → Admin mendaftarkannya ke siswa di menu
   **Kartu RFID**.

Kalau tidak ada permintaan aktif atau sudah kedaluwarsa, server membalas **409**.

### 10.2 Restart dari dashboard

1. Admin menekan **Restart**.
2. Heartbeat berikutnya membalas `restart_requested: true`.
3. Alat restart, lalu kirim:

   ```http
   POST /api/device/restart-ack
   X-Device-Key: ptd_...
   ```

   (Kalau alat mengirim `boot_id` baru pada heartbeat pertama setelah nyala,
   server juga menganggap restart selesai.)

### 10.3 OTA firmware

1. Admin unggah firmware di menu **Firmware**, lalu **Kirim ke perangkat**.
2. Heartbeat membalas objek `ota`:

   ```json
   {
     "version": "1.8.0",
     "url": "http://192.168.1.6:8000/api/firmware/12/download?signature=...",
     "size": 1048576,
     "sha256": "9f2c...",
     "requested_at": "2026-11-05T07:20:00+07:00"
   }
   ```

3. Alat mengunduh dari `url` (URL bertanda tangan, tanpa login), memeriksa
   `sha256`, lalu melapor:

   ```http
   POST /api/device/ota-ack
   X-Device-Key: ptd_...
   { "status": "downloading", "version": "1.8.0", "message": "10%" }
   ```

   Status yang dikenal: `downloading`, `success`, `failed`
   (variasi seperti `ok`, `error`, `checksum_mismatch` juga dipetakan server).

**Penting:** OTA hanya jalan kalau firmware alat benar-benar mengimplementasikan
unduh + flash. Kalau belum, update manual lewat USB seperti biasa.

### 10.4 MQTT (alternatif HTTP)

Kalau sekolah punya broker MQTT (mis. Mosquitto), server bisa menjadi jembatan:

```bash
php artisan devices:mqtt-listen
```

Topik memakai prefix pengaturan (default `presentech`), lalu
`presentech/d/{8 karakter pertama hash device key}/...`

| Arah | Topik | Isi |
| --- | --- | --- |
| Alat → server | `.../heartbeat` | payload heartbeat |
| Alat → server | `.../attendance` | `{ "uid": "..." }` |
| Alat → server | `.../scan-result` | `{ "uid": "..." }` |
| Alat → server | `.../result` | `{ "kind": "ota_ack", ... }` |
| Server → alat | `.../cmd/restart`, `.../cmd/scan`, `.../cmd/cancel` | perintah dashboard |

MQTT bersifat opsional. Jalur HTTP di atas sudah cukup untuk operasional harian.

---

## 11. Troubleshooting

| Gejala | Kemungkinan penyebab | Solusi |
| --- | --- | --- |
| Alat tidak muncul **Online** | Heartbeat tidak terkirim / URL salah / beda jaringan | Cek serial monitor, pastikan `{API_BASE}/health` terbuka dari alat, cek `X-Device-Key` |
| `401 Device key tidak valid.` | Key salah, ter-rotate, atau ada spasi/newline | Salin ulang key, jangan tambahkan spasi; rotate bila lupa |
| `403 Device key tidak cocok dengan perangkat ini.` | Mengirim ke `/api/devices/{id}/heartbeat` dengan id alat lain | Gunakan `/api/device/heartbeat` (tanpa id) |
| `404` saat tap | UID belum terdaftar / kartu nonaktif / siswa nonaktif | Daftarkan kartu lewat menu Kartu RFID (mode baca UID) |
| `409` saat tap | Sudah absen, sudah pulang, atau pulang terlalu awal | Normal — tampilkan `message` dari server |
| `429 Too Many Requests` | Lebih dari 60 request/menit | Perpanjang interval heartbeat, kirim batch, beri jeda retry |
| Discovery tidak menjawab | Listener mati / firewall UDP 8888 / beda subnet | Jalankan `php artisan devices:discovery`, buka firewall, atau isi URL manual |
| Server tidak bisa dibuka dari HP | `artisan serve` hanya bind `127.0.0.1` | Jalankan `php artisan serve --host=0.0.0.0 --port=8000` |
| Jam di LCD/absensi meleset | NTP alat belum sinkron (tap offline) | Tap online memakai jam server; sinkronkan NTP alat; kirim `time_verified` dengan benar |
| Data absensi dobel setelah offline | `client_event_id` berubah setiap kirim | Gunakan ID yang sama untuk event yang sama; server otomatis menolak duplikat |
| Alat restart sendiri terus | `restart_requested` tidak pernah di-ack dan `boot_id` tidak dikirim | Kirim `boot_id` baru tiap boot dan/atau panggil `/device/restart-ack` |

---

## 12. Uji cepat tanpa alat (dari laptop)

Ganti `ptd_...` dengan key asli alat.

### PowerShell

```powershell
$key = "ptd_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX"

# 1. Cek server
Invoke-RestMethod http://192.168.1.6:8000/api/health

# 2. Heartbeat
Invoke-RestMethod -Method Post http://192.168.1.6:8000/api/device/heartbeat `
  -Headers @{ "X-Device-Key" = $key } `
  -ContentType "application/json" `
  -Body '{"ip_address":"192.168.1.8","firmware_version":"1.6.0"}'

# 3. Simulasi tap kartu
Invoke-RestMethod -Method Post http://192.168.1.6:8000/api/attendance `
  -Headers @{ "X-Device-Key" = $key } `
  -ContentType "application/json" `
  -Body '{"uid":"A1B2C3D4"}'
```

### curl

```bash
curl -s http://192.168.1.6:8000/api/health

curl -s -X POST http://192.168.1.6:8000/api/device/heartbeat \
  -H "X-Device-Key: ptd_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX" \
  -H "Content-Type: application/json" \
  -d '{"ip_address":"192.168.1.8","firmware_version":"1.6.0"}'

curl -s -X POST http://192.168.1.6:8000/api/attendance \
  -H "X-Device-Key: ptd_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX" \
  -H "Content-Type: application/json" \
  -d '{"uid":"A1B2C3D4"}'
```

### Tes discovery UDP (PowerShell)

```powershell
$udp = New-Object System.Net.Sockets.UdpClient
$udp.EnableBroadcast = $true
$data = [Text.Encoding]::ASCII.GetBytes("PRESENTECH_DISCOVERY")
$udp.Send($data, $data.Length, "255.255.255.255", 8888) | Out-Null
$udp.Client.ReceiveTimeout = 3000
$remote = New-Object System.Net.IPEndPoint([System.Net.IPAddress]::Any, 0)
[Text.Encoding]::ASCII.GetString($udp.Receive([ref]$remote))
```

Jalankan `php artisan devices:discovery` di terminal lain sebelum tes ini.

---

## 13. Contoh firmware minimal ESP32 (Arduino)

Sketch ini cukup untuk: konek WiFi → heartbeat berkala → baca kartu → kirim absensi.
Sesuaikan pin MFRC522 dan nilai konfigurasi dengan board masing-masing.

```cpp
#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <SPI.h>
#include <MFRC522.h>

// ======================= KONFIGURASI =======================
const char* WIFI_SSID  = "WIFI_SEKOLAH";
const char* WIFI_PASS  = "password_wifi";
const char* API_BASE   = "http://192.168.1.6:8000/api";
const char* DEVICE_KEY = "ptd_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX";

const unsigned long HEARTBEAT_MS = 15000;   // 15 detik (aman di bawah 60/menit)
// ==========================================================

#define SS_PIN   5
#define RST_PIN  4
MFRC522 rfid(SS_PIN, RST_PIN);

unsigned long lastHeartbeat = 0;

void setup() {
  Serial.begin(115200);
  SPI.begin();
  rfid.PCD_Init();

  WiFi.begin(WIFI_SSID, WIFI_PASS);
  Serial.print("Menghubungkan WiFi");
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
  }
  Serial.println();
  Serial.print("Terhubung, IP alat: ");
  Serial.println(WiFi.localIP());
}

void loop() {
  if (millis() - lastHeartbeat >= HEARTBEAT_MS) {
    kirimHeartbeat();
    lastHeartbeat = millis();
  }

  if (rfid.PICC_IsNewCardPresent() && rfid.PICC_ReadCardSerial()) {
    String uid = "";
    for (byte i = 0; i < rfid.uid.size; i++) {
      if (rfid.uid.uidByte[i] < 0x10) uid += "0";
      uid += String(rfid.uid.uidByte[i], HEX);
    }
    uid.toUpperCase();
    kirimTap(uid);
    rfid.PICC_HaltA();
    rfid.PCD_StopCrypto1();
  }
}

void kirimHeartbeat() {
  if (WiFi.status() != WL_CONNECTED) return;

  HTTPClient http;
  http.begin(String(API_BASE) + "/device/heartbeat");
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-Device-Key", DEVICE_KEY);

  String body = String("{\"ip_address\":\"") + WiFi.localIP().toString() +
                "\",\"firmware_version\":\"1.8.0\"}";

  int code = http.POST(body);
  if (code == 200) {
    // Kompatibel ArduinoJson v6 dan v7.
#if ARDUINOJSON_VERSION_MAJOR >= 7
    JsonDocument doc;
#else
    StaticJsonDocument<8192> doc;
#endif
    DeserializationError err = deserializeJson(doc, http.getString());

    if (!err) {
      const char* greeting = doc["display"]["greeting"] | "Selamat datang";
      const char* clock    = doc["display"]["clock"]    | "--:--:--";
      const char* state    = doc["display"]["state"]    | "masuk";
      // TODO: gambar `greeting`, `clock`, `state` ke LCD di sini.

      if (doc["restart_requested"] == true) {
        // Beri tahu server, lalu restart.
        HTTPClient ack;
        ack.begin(String(API_BASE) + "/device/restart-ack");
        ack.addHeader("X-Device-Key", DEVICE_KEY);
        ack.POST("");
        ack.end();
        delay(500);
        ESP.restart();
      }

      // scan_requested == true -> masuk mode baca UID (lihat dokumentasi bagian 10.1)
    }
  } else {
    Serial.printf("Heartbeat gagal: HTTP %d\n", code);
  }

  http.end();
}

void kirimTap(String uid) {
  if (WiFi.status() != WL_CONNECTED) {
    // TODO: simpan ke antrean offline + kirim lewat /device/attendance/batch.
    Serial.println("Offline, tap disimpan ke antrean.");
    return;
  }

  HTTPClient http;
  http.begin(String(API_BASE) + "/attendance");
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-Device-Key", DEVICE_KEY);

  int code = http.POST("{\"uid\":\"" + uid + "\"}");
  String response = http.getString();

#if ARDUINOJSON_VERSION_MAJOR >= 7
  JsonDocument doc;
#else
  StaticJsonDocument<8192> doc;
#endif
  deserializeJson(doc, response);

  const char* message = doc["message"] | "";
  const char* nama    = doc["display"]["student_name"] | "";
  const char* status  = doc["display"]["status"] | "";
  const char* jam     = doc["display"]["time"] | "";

  Serial.printf("Tap %s -> HTTP %d | %s | %s %s %s\n",
                uid.c_str(), code, message, nama, status, jam);

  // TODO: tampilkan `nama`, `status`, `jam` ke LCD.
  http.end();
}
```

Catatan kompilasi:

- Library: **MFRC522** (by GithubCommunity) dan **ArduinoJson**.
- ArduinoJson v7 memakai `JsonDocument doc;`; v6 memakai `StaticJsonDocument<N>`.
  Ganti baris yang diberi komentar sesuai versi yang terpasang.
- Untuk HTTPS, tambahkan sertifikat root; untuk lab cukup HTTP di jaringan lokal.

---

## 14. Checklist sebelum demo

- [ ] MySQL menyala, backend jalan: `php artisan serve --host=0.0.0.0 --port=8000`
- [ ] Discovery jalan (kalau dipakai): `php artisan devices:discovery`
- [ ] Alat #1 `ESP32-RPL-01` sudah **Online** di menu Perangkat IoT
- [ ] Device key alat sudah ditulis di firmware
- [ ] Minimal satu kartu RFID terdaftar dan terhubung ke siswa aktif
- [ ] Tap uji menghasilkan `201 created` dan nama siswa muncul di dashboard
- [ ] Tap kedua setelah jam pulang (atau ubah sementara `home_time`) menghasilkan `200 checked_out`
- [ ] Uji matikan WiFi alat → tap → nyalakan WiFi → batch tersinkron tanpa duplikat
- [ ] Tombol **Baca UID**, **Restart**, dan (bila dipakai) **OTA** sudah dicoba
- [ ] **Ganti WiFi** dari dashboard sudah dicoba: alat restart lalu kembali **Online**
- [ ] Tahan tombol **BOOT 3 detik** membuka portal `PresenTech-Setup`

---

## 15. Ringkasan endpoint untuk firmware

| Method | Endpoint | Auth | Fungsi |
| --- | --- | --- | --- |
| `GET` | `/api/health` | — | Cek server hidup |
| `POST` | `/api/device/heartbeat` | `X-Device-Key` | Denyut + ambil jam, LCD, perintah |
| `POST` | `/api/attendance` | `X-Device-Key` | Tap kartu (masuk/pulang) |
| `POST` | `/api/device/attendance/batch` | `X-Device-Key` | Sinkron antrean offline (maks 50) |
| `POST` | `/api/device/scan-result` | `X-Device-Key` | Kirim UID hasil mode baca |
| `POST` | `/api/device/restart-ack` | `X-Device-Key` | Konfirmasi restart |
| `POST` | `/api/device/wifi-ack` | `X-Device-Key` | Konfirmasi kredensial WiFi sudah disimpan |
| `POST` | `/api/device/ota-ack` | `X-Device-Key` | Laporan status OTA |
| `GET` | `/api/firmware/{id}/download` | URL bertanda tangan | Unduh firmware OTA |

Semua request perangkat memakai `Content-Type: application/json` dan header
`X-Device-Key`. Base URL: `http://<IP-server>:8000/api`.