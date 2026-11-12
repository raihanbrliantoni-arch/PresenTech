# Panduan Publikasi ke GitHub

Keputusan proyek ini:

- Repo berisi **web saja** (backend Laravel + frontend React). Firmware ESP32 tidak disertakan.
- Hasil build frontend (`public/app`) **tidak di-commit**; dibangun ulang setelah clone.
- Push memakai **GitHub CLI (`gh`)**.

> GitHub bukan tempat menjalankan Laravel/MySQL. GitHub menyimpan kode, riwayat, dan CI. Untuk web yang bisa diakses orang lain tetap perlu server (laptop + tunnel, VPS, Railway, Render, atau hosting sekolah).

---

## 1. Checklist sebelum commit

- [ ] `PresenTech-Backend/.env` **tidak** ikut (berisi APP_KEY & kredensial DB).
- [ ] `vendor/`, `node_modules/`, `dist/`, `PresenTech-Backend/public/app/` tidak ikut.
- [ ] Tidak ada data siswa asli (seeder hanya membuat akun Admin).
- [ ] `.mcp.json`, `opencode.json`, `AGENTS.md`, `CLAUDE.md`, `boost.json` tidak ikut.
- [ ] Folder tooling AI (`.opencode/`, `.agents/`, `.claude/`) tidak ikut.
- [ ] Screenshot & skrip debug (`PresenTech-frontend/scripts/`) tidak ikut.
- [ ] Firmware (`config.h` berisi WiFi + device key) tidak dimasukkan ke repo.

Aturan tersebut sudah ditangani oleh `.gitignore` di root. Dengan aturan ini repo
hanya berisi kode aplikasi, dokumentasi, dan konfigurasi contoh (sekitar 5 MB).

---

## 2. Inisialisasi repository

Repo ini sudah memiliki commit awal di branch `main`, jadi langkah ini bisa dilewati.
Jalankan perintah di bawah hanya bila kamu menyalin file tanpa folder `.git`:

```powershell
git init -b main
git add .
git status
```

Periksa daftar file. Pastikan **tidak muncul**:

```
PresenTech-Backend/.env
PresenTech-Backend/vendor/
PresenTech-frontend/node_modules/
PresenTech-frontend/dist/
PresenTech-Backend/public/app/
```

Jika salah satu muncul, hentikan dan perbaiki `.gitignore` dulu.

Commit awal:

```powershell
git commit -m "feat: PresenTech web (Laravel API + React dashboard)"
```

Cek file yang benar-benar tercatat:

```powershell
git ls-files | Select-String -Pattern "\.env$|/vendor/|/node_modules/|/dist/|public/app/"
```

Perintah di atas harus **tidak menghasilkan output**.

---

## 3. Install GitHub CLI

```powershell
winget install --id GitHub.cli -e
```

Tutup dan buka ulang terminal, lalu cek:

```powershell
gh --version
```

Login (pilih `GitHub.com` → `HTTPS` → `Login with a web browser`):

```powershell
gh auth login
```

---

## 4. Buat repo dan push

Ganti `NAMA_REPO` sesuai keinginan:

```powershell
gh repo create NAMA_REPO --private --source . --remote origin --push
```

- `--private` disarankan. Ganti menjadi `--public` bila ingin dijadikan portofolio.
- Bila repo sudah dibuat manual di web, gunakan:

```powershell
git remote add origin https://github.com/USERNAME/NAMA_REPO.git
git push -u origin main
```

---

## 5. Verifikasi di GitHub

1. Buka repo → pastikan hanya source code, docs, dan konfigurasi contoh.
2. Cek tidak ada `.env`, `vendor`, `node_modules`, atau `public/app`.
3. Cek branch utama bernama `main`.
4. Opsional: tambahkan deskripsi repo + topik (`laravel`, `react`, `rfid`, `esp32`, `attendance`).

Uji dari clone bersih:

```powershell
cd $env:TEMP
git clone <url-repo> presentech-test
cd presentech-test
```

Ikuti langkah **Instalasi** pada `README.md`. Web harus berjalan tanpa file yang di-ignore.

---

## 6. CI (GitHub Actions)

Workflow sudah tersedia di `.github/workflows/ci.yml` dan langsung aktif setelah push
pertama (tidak perlu membuat file lagi). Isinya:

- **Backend** — PHP 8.3, `composer install` (dengan cache), `php artisan test`, dan
  `vendor/bin/pint --test` untuk menjaga gaya kode.
- **Frontend** — Node 22, `npm ci` (dengan cache), `npm run lint`, dan `npm run build`.

Catatan: test memakai SQLite in-memory (`phpunit.xml`), jadi tidak perlu MySQL di CI.
Node 22 dipakai karena Vite 8 mensyaratkan Node `^20.19 || >=22.12`.

---

## 7. Setelah repo terbit

- Ganti password akun Admin awal (`admin`) sebelum dipakai nyata, dan atur kredensialnya lewat `PRESENTECH_ADMIN_*` bila perlu.
- Rotasi **Device Key** perangkat dari menu Perangkat IoT (key lama pernah tampil di diskusi), lalu tulis key baru ke `config.h` firmware lokal.
- Simpan `.env` produksi hanya di server, bukan di repo.
- Bila repo publik: pastikan tidak pernah menambahkan data siswa asli.
- Lisensi repo memakai **MIT** (`LICENSE`). Ganti nama pemegang hak cipta bila perlu.

---

## 8. Jika tidak sengaja commit rahasia

1. Hapus dari index: `git rm --cached PresenTech-Backend/.env`
2. Commit perbaikan.
3. **Rotasi** kredensial tersebut (APP_KEY, password DB, device key) — riwayat git masih menyimpannya.
4. Untuk repo yang sudah terlanjur publik, bersihkan riwayat dengan `git filter-repo` atau buat repo baru dari commit bersih.

---

## 9. Perintah harian

```powershell
git status
git add -A
git commit -m "feat: ..."
git push
```
