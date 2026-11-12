# Register -> Database

UI Register tetap memakai desain PresenTech yang sudah ada.
Handler register mengirim:
POST /api/register
{
  role,
  identifier, // NIS atau NIP
  name,
  password
}

Backend membuat record users dan students/teachers.
Tidak menggunakan email.
