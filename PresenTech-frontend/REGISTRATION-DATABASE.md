# Registrasi PresenTech berbasis data sekolah

Registrasi Guru/Siswa tidak membuat data orang baru secara bebas.

Alur:
1. Admin memasukkan data Siswa/Guru terlebih dahulu.
2. Siswa mengisi NIS, nama, password.
3. Guru mengisi NIP, nama, password.
4. Laravel mencari NIS/NIP di tabel students/teachers.
5. Nama diverifikasi.
6. Jika belum punya akun, dibuat User dan user_id pada data master diisi.
7. Jika NIS/NIP tidak ditemukan atau sudah punya akun, registrasi ditolak.

Endpoint:
POST /api/register

Request:
{
  "role": "siswa",
  "identifier": "10231",
  "name": "Andi Pratama",
  "password": "passwordbaru"
}

Admin tetap tidak menggunakan register publik.
