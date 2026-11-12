# PresenTech: Database Bersih + Pembuatan Akun dari Dashboard

Database starter dirancang mulai bersih. Seeder hanya membuat **1 akun Admin**:

- username: `PRESENTECH_ADMIN_USERNAME` (default `admin`)
- password: `PRESENTECH_ADMIN_PASSWORD` (default `admin123`)

Siswa dan Guru **TIDAK** dibuat oleh seeder, dan aplikasi **tidak** menyediakan
pendaftaran publik. Akun mereka dibuat oleh Admin dari dashboard setelah login.

Langkah reset development:

1. Pastikan database `presentech` sudah ada.
2. Jalankan:
   ```
   php artisan migrate:fresh --seed
   ```
3. Jalankan `php artisan serve`.

Setelah reset, cek phpMyAdmin:

- `users` hanya berisi Admin.
- `students` kosong.
- `teachers` kosong.

## Alur pembuatan akun siswa

1. **Data Siswa** → **Tambah** (NIS, nama, kelas).
2. Pada menu baris data → **Buat Akun**.
3. Password sementara sama dengan NIS dan wajib diganti saat login pertama.

## Alur pembuatan akun guru

1. **Data Guru** → **Tambah** (NIP, nama, mata pelajaran).
2. Pada menu baris data → **Buat Akun**.
3. Password sementara sama dengan NIP dan wajib diganti saat login pertama.

## Catatan

- Password disimpan ter-hash (bcrypt) dan tidak pernah ditampilkan dari database.
- Email tidak dipakai untuk autentikasi.
- Admin hanya dibuat oleh seeder, bukan dari halaman publik.
- Seeder aman dijalankan berulang: bila username admin sudah ada, data tidak diubah.
