# PresenTech Authentication Backend

Added Laravel Sanctum authentication.

Endpoints:
POST /api/login
GET /api/me
POST /api/logout

Login body (field `identifier` menerima username / NIS / NIP):
{
  "identifier": "admin",
  "password": "admin123"
}

Seeded user (database bersih):
admin / admin123  (bisa diubah lewat PRESENTECH_ADMIN_USERNAME / PRESENTECH_ADMIN_PASSWORD)

Akun Guru dan Siswa tidak dibuat oleh seeder. Buat dari dashboard Admin
(Data Guru / Data Siswa → Buat Akun).

After replacing the backend with this version:

1. composer install
2. php artisan optimize:clear
3. php artisan migrate
4. If this is a fresh development database, use:
   php artisan migrate:fresh --seed
5. php artisan serve

Sanctum's personal_access_tokens table is created by its migration after Composer installs the package.

Important:
All existing API resources are inside auth:sanctum, so they require a Bearer token after login.
