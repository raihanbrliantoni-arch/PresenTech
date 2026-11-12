# PresenTech Auth Final

UI Login/Register lama dipertahankan.
Perubahan hanya pada logic:
- Login -> POST /api/login
- Register -> POST /api/register
- Token -> localStorage
- Role -> admin/guru/siswa
- Lupa password -> POST /api/forgot-password

Tidak menggunakan email untuk autentikasi.

Admin:
- Username + password
- Admin tidak memakai register publik.

Guru:
- NIP + password

Siswa:
- NIS + password

Catatan:
Password lama tidak ditampilkan. Admin sebaiknya melakukan reset password jika pengguna lupa.
