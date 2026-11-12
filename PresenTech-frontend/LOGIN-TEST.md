# Tes Login PresenTech

1. Jalankan Laravel:
   php artisan serve

2. Jalankan React:
   npm install
   npm run dev

3. Login:
   Admin: admin / admin123
   Guru & Siswa: akun dibuat oleh Admin dari dashboard (bukan dari seeder)

Login dikirim ke:
POST http://127.0.0.1:8000/api/login

Setelah berhasil:
- token disimpan di localStorage
- role disimpan di localStorage
- Axios otomatis mengirim Bearer token
- dashboard dipilih berdasarkan role
