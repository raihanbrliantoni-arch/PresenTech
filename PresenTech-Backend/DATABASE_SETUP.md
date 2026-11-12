# PresenTech Database Setup

Backend: Laravel 13 + MySQL
Database: `presentech`
Timezone: `Asia/Jakarta`

## 1. Pastikan `.env`

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=presentech
DB_USERNAME=root
DB_PASSWORD=
APP_TIMEZONE=Asia/Jakarta
```

Sesuaikan `DB_PASSWORD` jika MySQL kamu memakai password.

## 2. Bersihkan konfigurasi

```bash
php artisan config:clear
php artisan cache:clear
```

## 3. Untuk database development yang masih baru

Migration lama dari prototype masih memiliki tabel kosong. Karena struktur baru menggantinya, jalankan:

```bash
php artisan migrate:fresh --seed
```

**Perintah ini menghapus semua tabel dan data di database `presentech`. Jangan gunakan pada database produksi yang sudah berisi data penting.**

## 4. Akun Admin dari seeder

Seeder hanya membuat satu akun Admin:

- Username: `PRESENTECH_ADMIN_USERNAME` (default `admin`)
- Password: `PRESENTECH_ADMIN_PASSWORD` (default `admin123`)
- Nama tampilan: `PRESENTECH_ADMIN_NAME` (default `Administrator`)

Ganti password setelah login pertama. Siswa dan Guru **tidak** dibuat oleh seeder;
akun mereka dibuat dari dashboard Admin (Data Guru / Data Siswa → Buat Akun).
Menjalankan seeder berulang kali aman: akun yang sudah ada tidak diubah.

## 5. Struktur utama

- `users`
- `teachers`
- `school_classes`
- `students`
- `rfid_cards`
- `devices`
- `schedules`
- `attendances`
- `attendance_corrections`
- `notifications`
- `system_logs`

Notifikasi memiliki `expires_at`, sehingga backend dapat menerapkan aturan 24 jam saat mengambil data.
