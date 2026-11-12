# Audit PresenTech — 0 sampai 100

> **Catatan repo starter:** dokumen ini adalah audit project versi lengkap (dengan data
> demo). Repo ini sengaja dikosongkan — seeder hanya membuat akun Admin.

Tanggal audit: **3 November 2026** · pembaruan desain: **4 November 2026**
Lingkungan: Laragon / Windows · Laravel 12 · PHP 8.3.33 · React + Vite · ESP32 (HTTP mode)

Dokumen ini berisi **semua yang dicek**, temuan, skor per area, perbaikan yang sudah
dikerjakan pada sesi ini, dan sisa pekerjaan yang belum selesai.

---

## 1. Ringkasan skor

| Area | Skor | Status |
|---|---:|---|
| Autentikasi & siklus akun (admin/guru/siswa) | **90** | Baik, sudah diperbaiki |
| Otorisasi & pembatasan peran | **90** | Baik |
| Keamanan API & konfigurasi server | **90** | Baik, CVE dependensi sudah ditutup |
| Integritas data & backup | **85** | Baik |
| Absensi, hari libur, jadwal, laporan | **88** | Baik, butuh UAT |
| Perangkat ESP32, OTA, MQTT | **78** | Online, OTA belum terdaftar |
| UI/UX & identitas visual | **88** | Palet krem/indigo/kuningan tanpa hijau; sisa uji regresi |
| Aksesibilitas | **86** | Kontras teks utama lolos audit otomatis |
| Performa frontend | **76** | Bundle satu chunk besar |
| Operasional & kesiapan produksi | **62** | Belum siap produksi penuh |
| Pengujian otomatis | **86** | 191 test / 835 assertion lulus |
| Notifikasi orang tua (WA/email) | **45** | Ditunda sesuai keputusan |
| **Total tertimbang** | **83 / 100** | **Siap UAT, belum siap produksi** |

Arti skor: 90–100 siap produksi; 80–89 layak dengan catatan; 60–79 perlu perbaikan;
< 60 belum layak.

---

## 2. Cara audit (bukti yang dipakai)

1. **Membaca kode** seluruh controller API, route, middleware, model, migrasi, dan
   halaman frontend utama.
2. **Menjalankan test backend**: `php artisan test --compact` → **191 test lulus,
   835 assertion**.
3. **Uji HTTP langsung** ke server `http://127.0.0.1:8000`:
   login admin/guru/siswa, pembuatan akun, reset password, blokir 428, header keamanan.
4. **Cek database aktual** (users, students, teachers, devices, attendances, system_logs).
5. **Cek perangkat ESP32**: status online, IP, firmware, heartbeat, absensi terakhir,
   konfigurasi `config.h`, dan proses `devices:discovery`.
6. **Audit dependensi**: `composer audit` dan `npm audit --omit=dev`.
7. **Build frontend produksi**: `scripts\build-frontend.ps1` + verifikasi aset tersaji.
8. **Verifikasi visual browser** (4 November 2026): Chrome headless + DevTools Protocol
   pada lebar 1440 px dan 390 px; login Admin/Guru/Siswa sungguhan, mode terang dan
   gelap, tangkapan layar landing (hero + tiap bagian), login, dashboard ketiga peran,
   mode gelap, menu seluler, dan tampilan ponsel. Hasil: **0 error/warning konsol**.
   Pemeriksaan juga mencakup font terpasang, latar krem, penanda navigasi aktif,
   dan tidak ada gulir mendatar yang tidak disengaja.

---

## 3. Checklist lengkap yang dicek

### A. Akun, login, dan sesi

- [x] Login dengan username, NIS, dan NIP
- [x] Login guru (`guru1`) → berhasil
- [x] Login siswa (`10231`) → berhasil
- [x] Login akun nonaktif → ditolak dengan pesan jelas
- [x] Password di-hash (bcrypt) dan rehash otomatis saat cost berubah
- [x] Pembuatan akun siswa dari dashboard Admin
- [x] Pembuatan akun guru dari dashboard Admin
- [x] Akun tidak bisa dibuat dua kali
- [x] Akun tidak bisa dibuat untuk data berstatus Nonaktif (baru)
- [x] Password sementara punya huruf + angka (baru)
- [x] Password sementara **wajib diganti** saat login pertama (baru)
- [x] Endpoint lain diblokir (HTTP 428) sampai password diganti (baru)
- [x] Admin bisa reset password akun siswa/guru yang sudah ada (baru, dari tabel data)
- [x] Reset password mencabut semua token lama
- [x] Log audit login berhasil/gagal/ditolak (baru)
- [x] Log audit pembuatan akun & reset password (baru)
- [x] Token kedaluwarsa 7 hari (`SANCTUM_TOKEN_EXPIRATION`)
- [x] Logout mencabut token aktif
- [x] Ganti password mencabut token lain
- [ ] 2FA untuk Admin (belum ada)
- [ ] Token disimpan di localStorage (rawan jika XSS; CSP sudah membantu)

### B. Otorisasi

- [x] Middleware `auth:sanctum` pada semua endpoint privat
- [x] Middleware `active` memblokir akun nonaktif
- [x] Middleware `role:admin` dan `role:admin,guru` per grup route
- [x] Siswa hanya bisa membaca data dirinya (dicek di controller)
- [x] Endpoint admin (backup, restore, set-password, OTA, device) hanya Admin
- [x] Endpoint perangkat memakai `X-Device-Key`
- [x] Firmware hanya bisa diunduh lewat signed URL
- [x] Mass assignment `role` ditutup (role tidak fillable)

### C. Keamanan API

- [x] Rate limit login per identifier + per IP
- [x] Rate limit endpoint perangkat per device key
- [x] Validasi input di controller (tipe, panjang, format, exists)
- [x] Pesan login tidak membocorkan apakah akun ada
- [x] Header keamanan: `X-Content-Type-Options`, `X-Frame-Options`,
      `Referrer-Policy`, `Permissions-Policy`, `COOP`, `CSP` (baru)
- [x] HSTS otomatis saat HTTPS (baru)
- [x] `.htaccess` header keamanan untuk SPA statis Apache/Laragon (baru)
- [x] CORS dibatasi origin (localhost + env)
- [x] Backup/restore hanya Admin + konfirmasi `RESTORE`
- [x] Restore berjalan dalam satu transaksi
- [x] Log sistem otomatis dipangkas setelah 30 hari
- [x] `APP_KEY` terisi
- [ ] `APP_DEBUG=true` masih aktif di `.env` (wajib `false` di produksi)
- [x] CVE dependensi ditutup (4 Nov 2026, lihat bagian 5)
- [ ] `allowed_headers: *` dan `allowed_methods: *` di CORS (bisa dipersempit)
- [ ] Belum ada Content-Security-Policy ketat tanpa `unsafe-inline` untuk style

### D. Data & backup

- [x] Migrasi database berjalan (`must_change_password` baru)
- [x] Relasi user ↔ student/teacher konsisten (dicek di DB)
- [x] Nomor absen otomatis per kelas
- [x] Import CSV siswa + RFID
- [x] Backup JSON seluruh tabel
- [x] Restore transaksional
- [x] Retensi log 30 hari
- [ ] Import CSV berhenti setengah jalan jika satu baris gagal (belum atomik)
- [ ] Belum ada uji restore terjadwal (hanya manual)

### E. Absensi, jadwal, libur, laporan

- [x] Absensi RFID via HTTP
- [x] Absensi batch dari antrean offline
- [x] Status Hadir / Terlambat / Pulang
- [x] Hari libur tidak memicu Alpa otomatis
- [x] Hari libur tidak diisi Alpa pada laporan
- [x] Sabtu–Minggu memakai jam umum
- [x] Jam khusus per hari (opsional)
- [x] Filter semester & tahun ajaran
- [x] Rekap kelas dan halaman laporan
- [x] Koreksi absensi (pengajuan/approval)
- [ ] UAT absensi jam pulang & status tap pulang di alat
- [ ] UAT hari libur pada tanggal nyata

### F. Perangkat & OTA

- [x] ESP32-RPL-01 online (IP `192.168.1.8`, firmware `1.5.0`)
- [x] Heartbeat masuk (last seen terus diperbarui)
- [x] Discovery UDP berjalan (`devices:discovery --http-port=8000`)
- [x] Kode antrean offline LittleFS (maks. 200 event)
- [x] Kode OTA: unduh, verifikasi SHA-256, lapor, restart
- [x] Sinkronisasi jam dari heartbeat
- [x] Restart perangkat dari dashboard
- [ ] Registry firmware OTA **kosong** (belum ada file `.bin` diunggah)
- [ ] MQTT masih `MQTT_ENABLE 0` (mode HTTP)
- [ ] Antrean offline pada mode MQTT-only masih memakai HTTP
- [ ] ESP32-RPL-02 dan ESP32-TKJ-01 offline
- [ ] UAT listrik putus, RFID rusak, WiFi putus

### G. Frontend & desain

- [x] Struktur lama dipertahankan, tetapi warna hijau diganti: kertas krem
      `#f4f1e7`, indigo tua `#1e3a6e`, aksen kuningan `#8f6412`
- [x] Tipografi: Fraunces untuk judul, Archivo untuk UI, IBM Plex Mono untuk
      NIS, jam, dan kode
- [x] Landing kembali ke susunan lama: hero dua kolom dengan simulasi perangkat
      RFID/ESP32, Tentang dua kolom, grid kartu fitur, empat langkah cara kerja,
      dan tiga kartu pengguna
- [x] Simulasi tap kartu menampilkan nama, kelas, status, dan jam pada layar LCD
- [x] Penanda navigasi mengikuti bagian yang sedang dibaca (juga saat mentok bawah)
- [x] Login kembali ke satu lembar: panel indigo (RFID · ESP32 · Dashboard) dan
      kartu formulir di kanan
- [x] Dashboard memakai permukaan krem, sudut 10 px, aksen indigo/kuningan
- [x] Topbar dashboard dan menu seluler dibuat opak (tidak lagi tembus isi halaman)
- [x] Ikon fitur dan ikon peran diseragamkan ke palet indigo/kuningan/baja
- [x] Kolom keterangan tabel absensi membungkus agar tidak terpotong di layar lebar
- [x] Status akun (Sudah/Belum) tampil di tabel siswa & guru
- [x] Aksi "Reset Password Akun" di menu baris
- [x] Dialog password sementara bisa disalin sekali klik
- [x] Gerbang wajib ganti password (semua peran)
- [x] `prefers-reduced-motion` dihormati
- [ ] Beberapa halaman dashboard masih memakai pola kartu statistik
- [ ] Belum ada uji visual/browser otomatis (regresi)
- [ ] Font Google Fonts butuh internet (ada fallback sistem)

### H. Aksesibilitas

- [x] `lang="id"`, meta viewport, meta description
- [x] `:focus-visible` jelas
- [x] Label form pada login & gerbang password
- [x] `aria-label` pada tombol ikon utama
- [x] Kontras teks utama memadai
- [x] Modal bisa ditutup dengan Escape
- [ ] Beberapa tombol ikon di dashboard belum punya `aria-label`
- [ ] Belum diuji dengan screen reader

### I. Performa

- [x] Build produksi berhasil
- [x] Aset disajikan dari origin yang sama (`/app`)
- [x] Gzip JS ±146 KB
- [ ] Satu chunk JS 533 KB (belum code splitting)
- [ ] CSS 234 KB belum dipecah per halaman
- [ ] Belum ada cache header eksplisit untuk aset ber-hash

### J. Operasional & produksi

- [x] Perintah backup database
- [x] Panel kesehatan sistem
- [x] Log audit terpusat
- [x] Skrip build frontend berhenti saat build gagal (baru)
- [ ] `APP_ENV=local`, `APP_DEBUG=true` (wajib diubah)
- [ ] Belum ada HTTPS/domain produksi
- [ ] Belum ada CI/CD
- [ ] Belum ada Git repository aktif (hanya `.gitignore`)
- [ ] Belum ada staging
- [ ] Belum ada monitoring uptime eksternal

### K. Notifikasi

- [x] Notifikasi in-app (bell, baca/belum dibaca)
- [x] Notifikasi admin untuk permintaan reset password
- [ ] WhatsApp ke orang tua (ditunda)
- [ ] Email ke orang tua (ditunda)

---

## 4. Temuan utama dan perbaikannya

### 4.1 Akun sudah dibuat tetapi tidak bisa login — **SELESAI**

**Penyebab yang ditemukan:**

1. Backend dan login sebenarnya **bekerja** (diuji langsung: `guru1`, `10231`,
   dan akun baru `252610074` semuanya bisa login).
2. Masalah nyata ada di alur kerja: password sementara hanya muncul **sekali** di
   dialog. Jika Admin menutup dialog atau lupa menyalin, akun tidak bisa dipakai
   lagi karena **tidak ada tombol reset** di tabel siswa/guru.
3. Akun untuk data berstatus **Nonaktif** tetap bisa dibuat, lalu login selalu
   ditolak — terasa seperti "akun tidak bisa login".

**Perbaikan:**

- Password sementara sekarang wajib diganti saat login pertama.
- Server menolak endpoint lain dengan **HTTP 428** sampai password diganti.
- Frontend menampilkan gerbang "Ganti password sementara" untuk semua peran.
- Tabel siswa/guru menampilkan status akun dan aksi **Reset Password Akun**.
- Dialog password bisa disalin sekali klik dan diberi label jelas.
- Pembuatan akun untuk data Nonaktif ditolak dengan pesan yang bisa ditindaklanjuti.
- Setiap login, kegagalan login, pembuatan akun, dan reset password dicatat di log.

### 4.2 Keamanan — **SEBAGIAN BESAR SELESAI**

Ditambahkan: security headers + CSP, HSTS saat HTTPS, audit login, kewajiban ganti
password sementara, pencabutan token saat reset, dan `.htaccess` untuk SPA.

Belum selesai: `APP_DEBUG=true`, 2FA Admin, penyimpanan token di localStorage.

### 4.3 Tampilan — **STRUKTUR LAMA, PALET BARU TANPA HIJAU**

Arah "buku absen sekolah" (putih dingin, tinta navy, stempel jingga, sudut 2 px)
**ditolak pemilik proyek**: terasa seperti redesain, bukan perbaikan. Tampilan
dikembalikan ke nuansa lama, lalu pemilik meminta warna hijau dihilangkan.
Struktur, alur, API, dan data tetap sama; yang berubah hanya identitas warna.

**Palet baru**

- Kertas krem `#f4f1e7`, permukaan `#fffdf7`, garis beige `#ded8c9`.
- Merek indigo tua `#1e3a6e` (gelap `#142a52`, lembut `#e6ecf8`).
- Aksen kuningan `#8f6412` (lembut `#f4ecda`); status "Hadir" memakai baja
  `#2f5f86`, bukan hijau.
- Mode gelap memakai biru-hitam `#0a0f18`, permukaan `#121a27`, dan merek
  biru muda `#8fb0e0` dengan teks gelap pada tombol/avatar.
- Tidak ada lagi token atau hex hijau di CSS aktif; sisa ungu/cyan/oranye
  landing ikut disatukan ke palet ini.

**Yang dipertahankan**

- Tipografi: **Fraunces** untuk judul, Archivo untuk antarmuka, IBM Plex Mono
  untuk NIS/NIP, jam, dan kode.
- Landing: hero dua kolom dengan simulasi perangkat (LCD, tombol tap kartu,
  status RFID/ESP32/Database), Tentang dua kolom, grid kartu fitur, empat langkah
  cara kerja, dan tiga kartu pengguna.
- Login: panel kiri indigo berisi RFID · ESP32 · Dashboard dan kartu formulir di
  kanan; bukan panel "buku absen".
- Dashboard Admin/Guru/Siswa: permukaan krem, kartu membulat (radius 10 px),
  aksen indigo untuk aksi dan kuningan untuk penanda.

**Polesan struktur lama**

- Topbar dashboard dan menu seluler dibuat **opak**; sebelumnya isi halaman
  membayang di belakangnya saat digulir.
- Penanda navigasi memakai posisi elemen nyata (`getBoundingClientRect`) sehingga
  bagian yang aktif tidak tertinggal satu langkah; bagian terakhir ikut aktif saat
  halaman mentok bawah.
- Ikon fitur yang sebelumnya ungu/cyan/oranye dan ikon peran disatukan ke palet
  indigo, kuningan, dan baja.
- Tombol "Aksi Cepat" yang masih tampil sebagai tombol bawaan browser diberi
  bentuk kartu yang konsisten.
- Kolom "Keterangan" pada tabel absensi boleh membungkus, jadi tabel muat tanpa
  terpotong; pembungkus tabel tetap bisa digulir mendatar bila layar sempit.
- Kontras teks pada banner sambutan dan kartu aksi utama diperbaiki untuk mode
  terang maupun gelap. Audit kontras otomatis pada landing, login, dan ketiga
  dashboard (terang/gelap) tidak menemukan teks di bawah ambang WCAG AA.
- Fokus keyboard diberi cincin indigo yang terlihat di latar krem.

**Verifikasi halaman dalam (4 Nov 2026):** seluruh 24 halaman dalam rutin
diklik satu per satu via CDP — Admin (14: Dashboard, Absensi, Siswa, Guru,
Kelas, Jadwal, Perangkat IoT, Monitoring, Kartu RFID, Laporan, Log Sistem,
Koreksi, Reset Password, Pengaturan), Guru (6), Siswa (4). Hasil pada setiap
halaman: **0 temuan kontras WCAG AA** dan **0 warna hijau yang dirender**
(pemindaian computed style semua elemen). Konsol browser bersih
(0 error/warning). Status "Terlambat" kini kuningan, "Alpa"/offline tetap merah
semantik, "Hadir" baja.

**Yang tidak berubah:** route, API, validasi, alur login, keamanan sesi, dan
skema data. `identity.css` (lapisan "buku absen") tidak lagi diimpor, tetapi
filenya dibiarkan ada agar mudah dirujuk.

**Verifikasi:** build produksi berhasil
(`index-DzxGmbdB.js` 533 kB, `index-BDa0S58e.css` 234 kB) dan disalin ke
`PresenTech-Backend/public/app`. Login Admin, Guru, dan Siswa diuji ulang di
browser; landing (hero dan tiap bagian), login terang/gelap, dashboard ketiga
peran, mode gelap, dan lebar 390 px diperiksa lewat tangkapan layar.
**0 error/warning konsol**; font Fraunces terpasang; tidak ada gulir mendatar di
halaman publik.

**Sisa:** beberapa halaman dashboard masih memakai pola kartu statistik, dan belum
ada uji visual otomatis untuk mencegah regresi.

### 4.4 Perangkat — **ONLINE, OTA BELUM SIAP**

- ESP32-RPL-01 **Online**, firmware **1.5.0**, IP **192.168.1.8**.
- Heartbeat terakhir: **3 Nov 2026 19:50 WIB**.
- Absensi RFID terakhir: **3 Nov 2026 19:26:59 — Raihan Briliantoni — Terlambat**.
- Discovery UDP aktif di port 8000.
- **Registry firmware OTA kosong**: alat punya kemampuan OTA, tetapi belum ada
  file `.bin` yang diunggah ke menu Firmware, sehingga OTA belum bisa dipakai.
- MQTT masih nonaktif (`MQTT_ENABLE 0`); alat memakai HTTP.
- Dua perangkat lain (RPL-02, TKJ-01) masih offline.

---

## 5. Celah keamanan dependensi — **SUDAH DIPERBAIKI**

Diperbarui pada **4 November 2026** setelah mendapat persetujuan.

### Backend (`composer update --with-all-dependencies`)

| Paket | Sebelum | Sesudah | Severity |
|---|---|---|---|
| `league/commonmark` | 2.10.0 | **2.10.7** | medium + high |
| `symfony/http-foundation` | 7.4.18 | **7.4.22** | high (CVE-2026-108082) |
| `symfony/mime` | 7.4.18 | **7.4.22** | medium (CVE-2026-45070) |

`composer audit` sekarang: **No security vulnerability advisories found.**

### Frontend (`npm audit fix`)

| Paket | Sebelum | Sesudah | Severity |
|---|---|---|---|
| `follow-redirects` (via axios) | 1.16.1 | **1.16.2** | high |

`npm audit --omit=dev` sekarang: **found 0 vulnerabilities**.

### Sisa: hanya dependensi pengembangan

`npm audit` masih melaporkan **6 moderate** pada rantai `eslint`,
`@eslint/config-array`, `minimatch`, dan `eslint-plugin-react-refresh`.
Paket ini **tidak ikut ke build produksi** (devDependency), jadi tidak
berdampak ke pengguna. Perbaikannya butuh `npm audit fix --force`
(mengganti versi mayor ESLint) dan sebaiknya dilakukan terpisah sambil
menjalankan ulang lint.

### Verifikasi setelah pembaruan

- `php artisan test --compact` → **191 test lulus, 835 assertion**.
- `npm run build` → berhasil; aset produksi terakhir
  `index-DzxGmbdB.js` / `index-BDa0S58e.css`.
- Smoke test login: admin, guru1, 10231 → semuanya berhasil.
- `GET /api/health` → 200.

---

## 6. Prioritas perbaikan berikutnya

### P0 — sebelum produksi

1. Set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://domain`.
2. Pasang HTTPS (Let's Encrypt) + `SESSION_SECURE_COOKIE=true`.
3. ~~Perbaiki CVE dependensi (bagian 5).~~ **Selesai 4 Nov 2026.**
4. Upload minimal satu firmware ke registry OTA dan uji OTA sekali.
5. Uji restore backup di database salinan.
6. Ganti semua password default (`admin123`, `guru123`, `siswa123`) dan
   reset akun demo.

### P1 — setelah produksi awal

7. 2FA untuk Admin.
8. Pindahkan token ke cookie httpOnly (atau pertahankan localStorage +
   CSP ketat tanpa `unsafe-inline`).
9. Code splitting frontend (halaman dashboard dimuat sesuai peran).
10. UAT hardware: listrik putus, WiFi putus, RFID rusak, jam pulang.
11. Aktifkan MQTT + perbaiki antrean offline mode MQTT.
12. Notifikasi WhatsApp/email ke orang tua.

### P2 — penyempurnaan

13. Import CSV atomik (satu transaksi).
14. Uji aksesibilitas dengan screen reader.
15. Monitoring uptime + alert perangkat offline.
16. CI/CD dan staging.
17. Uji regresi visual otomatis untuk landing, login, dan ketiga dashboard.

---

## 7. Perintah verifikasi

```powershell
# Backend
cd C:\laragon\www\pkk\PresenTech-Backend
php artisan test --compact
vendor\bin\pint --format agent <file-yang-diubah>

# Frontend
cd C:\laragon\www\pkk
powershell -ExecutionPolicy Bypass -File scripts\build-frontend.ps1

# Server lokal (dua terminal)
php artisan serve --host=0.0.0.0 --port=8000
php artisan devices:discovery --http-port=8000

# Audit dependensi
composer audit
npm audit --omit=dev
```

**URL aplikasi:** `http://192.168.1.5:8000/app`
**API:** `http://192.168.1.5:8000/api`

---

## 8. Catatan akun aktual (per audit)

| Peran | Username | NIS/NIP | Status |
|---|---|---|---|
| Admin | `admin` | — | Aktif |
| Guru | `guru1`–`guru3` | `1985000001`–`1985000003` | Aktif |
| Guru | `123456789` | `123456789` | Aktif |
| Siswa | `siswa10231` dst. | `10231`, `10242`, `10255`, `10268`, `10273`, `10281` | Aktif |
| Siswa | `252610074` | `252610074` | Aktif, password sudah direset (wajib ganti) |
| Siswa | — | `12345678` (Siswa Demo) | Aktif, belum punya akun |
| Siswa | — | `123456789` (Eka Siswanto) | Nonaktif |

> Akun `252610074` direset saat pengujian dan kini berstatus **wajib ganti
> password**. Buka **Data Siswa → menu baris → Reset Password Akun** untuk
> mendapatkan password sementara baru.
