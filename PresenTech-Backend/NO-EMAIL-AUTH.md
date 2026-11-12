# PresenTech - Authentication tanpa Email

Login sekarang menggunakan:
- Siswa: NIS + password
- Guru: NIP + password
- Admin: username + password

Endpoint:
POST /api/login
GET /api/me
POST /api/logout

Request login:
{
  "identifier": "NIS atau NIP atau username admin",
  "password": "..."
}

Untuk database yang sudah ada:
php artisan migrate

Untuk development dan ingin reset total:
php artisan migrate:fresh --seed
