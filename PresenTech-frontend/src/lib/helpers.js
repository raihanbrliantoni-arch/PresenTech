// Util bersama: tanggal, hari, rumus kehadiran, kelas badge status,
// dan kompresi foto profil. Dipakai Admin/Guru/Siswa agar rumus
// tidak berbeda-beda antar dashboard.

export function localDateKey(date = new Date()) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, "0");
  const d = String(date.getDate()).padStart(2, "0");
  return `${y}-${m}-${d}`;
}

export function localDayName(date = new Date()) {
  return ["Minggu", "Senin", "Selasa", "Rabu", "Kamis", "Jumat", "Sabtu"][date.getDay()];
}

// Sekolah hanya aktif Senin–Jumat. Sabtu/Minggu tidak ditampilkan di jadwal
// agar hari libur tidak muncul sebagai kartu "Tidak ada jadwal".
export const WORK_DAYS = ["Senin", "Selasa", "Rabu", "Kamis", "Jumat"];

// Fallback jurusan tidak boleh bikin-bikin data; kosongkan jika belum ada
// jumlah kelasnya.
export const MAJOR_PLACEHOLDER = "-";

// Persentase kehadiran dihitung dari Hadir + Terlambat saja;
// Izin/Sakit/Alpa tidak dihitung sebagai hadir.
export function attendancePercentage(present, late, total) {
  if (!total) return 0;
  return Math.min(100, Math.round(((present + late) / total) * 100));
}

// Kelas CSS badge status — satu sumber untuk semua tabel agar
// Izin/Sakit/Alpa tidak lagi tampil dengan warna "Terlambat".
export function statusClass(status) {
  switch (status) {
    case "Hadir": return "hadir";
    case "Terlambat": return "late";
    case "Izin": return "izin";
    case "Sakit": return "sakit";
    case "Alpa": return "alpa";
    case "Dikoreksi": return "dikoreksi";
    default: return "late";
  }
}

// Kalender minggu Senin–Jumat berdasarkan tanggal sungguhan,
// bukan record terakhir yang dipetakan paksa ke label hari.
// Sabtu & Minggu tidak dihitung karena sekolah hanya masuk hari kerja.
export function weekCalendar(attendance = [], { weekAnchor = new Date() } = {}) {
  const anchor = new Date(weekAnchor);
  const offsetMonday = (anchor.getDay() + 6) % 7;
  return ["Sen", "Sel", "Rab", "Kam", "Jum"].map((label, i) => {
    const date = new Date(anchor);
    date.setDate(anchor.getDate() - offsetMonday + i);
    const key = localDateKey(date);
    const record = attendance.find((x) => x.date === key);
    return { label, date: key, status: record?.status || "-" };
  });
}

// 7 hari terakhir (terakhir = hari ini), terurut kronologis Sen–Min
// berdasarkan hari sungguhan — untuk grafik batang dashboard.
export function last7Days(attendance = [], { today = new Date() } = {}) {
  return Array.from({ length: 7 }, (_, i) => {
    const date = new Date(today);
    date.setDate(today.getDate() - (6 - i));
    const key = localDateKey(date);
    const rows = attendance.filter((x) => x.date === key);
    return {
      label: ["Sen", "Sel", "Rab", "Kam", "Jum", "Sab", "Min"][(date.getDay() + 6) % 7],
      date: key,
      hadir: rows.filter((x) => x.status === "Hadir").length,
      terlambat: rows.filter((x) => x.status === "Terlambat").length,
      izin: rows.filter((x) => x.status === "Izin").length,
      sakit: rows.filter((x) => x.status === "Sakit").length,
      alpa: rows.filter((x) => x.status === "Alpa").length,
    };
  });
}

// Metadata log dari server berupa objek teknis ({"class_id":9}).
// Ubah jadi kalimat pendek yang dibaca manusia; kunci internal dibuang
// supaya Log Sistem tidak menampilkan kode mentah.
const LOG_META_SKIP = new Set([
  "id", "class_id", "teacher_id", "student_id", "user_id", "schedule_id",
  "device_id", "rfid_card_id", "attendance_id", "created_at", "updated_at",
  "deleted_at", "password", "token",
]);

export function formatLogDetail(metadata) {
  if (!metadata || typeof metadata !== "object") return "";
  return Object.entries(metadata)
    .filter(([key, value]) => {
      if (LOG_META_SKIP.has(key)) return false;
      if (value === null || value === undefined || value === "") return false;
      if (typeof value === "object") return false;
      return true;
    })
    .map(([key, value]) => `${key.replace(/_/g, " ")}: ${value}`)
    .join(" · ");
}

// Kompres foto profil di browser sampai <= maxBytes karakter base64
// (backend membatasi string avatar; 2 MB gambar mentah akan ditolak).
export function fileToAvatarBase64(file, maxBytes = 150 * 1024) {
  const readAsDataURL = (blob) => new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result));
    reader.onerror = () => reject(new Error("Gagal membaca file."));
    reader.readAsDataURL(blob);
  });
  const loadImage = (src) => new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => resolve(img);
    img.onerror = () => reject(new Error("File bukan gambar yang valid."));
    img.src = src;
  });

  return (async () => {
    const dataUrl = await readAsDataURL(file);
    if (!file.type.startsWith("image/")) throw new Error("File harus berupa gambar.");
    const img = await loadImage(dataUrl);
    const canvas = document.createElement("canvas");
    let scale = Math.min(1, 512 / Math.max(img.width || 512, img.height || 512));
    let quality = 0.82;
    let out = dataUrl;
    for (let attempt = 0; attempt < 7; attempt += 1) {
      canvas.width = Math.max(1, Math.round(img.width * scale));
      canvas.height = Math.max(1, Math.round(img.height * scale));
      canvas.getContext("2d").drawImage(img, 0, 0, canvas.width, canvas.height);
      out = quality < 0.82 || scale < 1 ? canvas.toDataURL("image/jpeg", quality) : dataUrl;
      if (out.length <= maxBytes) return out;
      scale *= 0.75;
      quality = Math.max(0.4, quality - 0.12);
    }
    return out;
  })();
}

// Waktu relatif: "baru saja", "12 detik yang lalu", "3 menit yang lalu", dst.
// `now` boleh diisi waktu terbaru agar label ikut berubah tanpa render ulang data
// (dipakai panel notifikasi yang punya timer 1 detik).
export function relativeTime(value, now = Date.now()) {
  if (!value) return "Belum pernah";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);
  const seconds = Math.max(0, Math.floor((now - date.getTime()) / 1000));
  if (seconds < 5) return "Baru saja";
  if (seconds < 60) return seconds + " detik yang lalu";
  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return minutes + " menit yang lalu";
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return hours + " jam yang lalu";
  const days = Math.floor(hours / 24);
  if (days < 30) return days + " hari yang lalu";
  const months = Math.floor(days / 30);
  if (months < 12) return months + " bulan yang lalu";
  return Math.floor(months / 12) + " tahun yang lalu";
}

// Kunci urut kelas: tingkat X, XI, XII lebih dulu; di dalam tingkat baru
// jurusan RPL, DKV, TKJ, TRANSMISI; lalu nomor kelas.
const CLASS_MAJOR_RANK = { RPL: 1, DKV: 2, TKJ: 3, TRANSMISI: 4 };

export function classSortKey(name = "") {
  const text = String(name).toUpperCase().trim();
  const grade = text.startsWith("XII") ? 3 : text.startsWith("XI") ? 2 : text.startsWith("X") ? 1 : 9;
  const parts = text.split(/\s+/);
  const major = CLASS_MAJOR_RANK[parts[1]] ?? 9;
  const numbers = text.match(/\d+/g);
  const number = numbers?.length ? Number(numbers[numbers.length - 1]) : 1;
  return grade * 100000 + major * 1000 + number;
}
