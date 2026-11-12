# PresenTech Authentication - UI Dipertahankan

Versi ini memakai ZIP frontend asli sebagai basis.
LoginPage.jsx TIDAK ditulis ulang, sehingga desain, CSS, logo, layout,
password toggle, dan komponen lain tetap seperti sebelumnya.

Yang ditambahkan hanya:
- src/api/api.js
- src/api/authApi.js
- src/api/handleAuthLogin.js
- dependency axios

Untuk mengubah proses login pada handler yang sudah ada:
1. import:
   import { handleAuthLogin } from "../api/handleAuthLogin";
2. panggil:
   const user = await handleAuthLogin(identifier, password);
3. navigasikan berdasarkan user.role:
   admin -> admin
   guru -> guru
   siswa -> siswa

Tidak ada perubahan CSS/UI dari versi asli.
