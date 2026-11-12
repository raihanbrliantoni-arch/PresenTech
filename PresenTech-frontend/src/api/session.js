// Kunci sesi PresenTech di localStorage — dipakai bersama oleh
// interceptor api.js dan pembersihan saat logout/sesi kedaluwarsa.
const SESSION_KEYS = [
  "presentechToken",
  "presentechRole",
  "presentechUser",
  "presentechUsername",
  "presentechProfile",
  "presentechUserProfile",
  "pt_siswa_profile",
  "pt_guru_profile",
  "pt_notifications",
];

export const clearPresenTechSession = () => {
  SESSION_KEYS.forEach((key) => localStorage.removeItem(key));
};
