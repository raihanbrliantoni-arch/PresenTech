import { Fragment, useEffect, useLayoutEffect, useMemo, useRef, useState } from "react";
import { logout as apiLogout, changePassword, updateProfile, getCurrentUser } from "../api/authApi";
import { clearPresenTechSession } from "../api/session";
import {
 getAdminPasswordResetRequests,
 approvePasswordReset,
 rejectPasswordReset,
 adminSetPassword,
} from "../api/passwordResetApi";

import {
 CreditCard,
 ScrollText,
 RotateCcw,
 Bell,
 CalendarDays,
 CalendarOff,
 Check,
 CheckCircle2,
 Clock3,
 Copy,
 ChevronDown,
 ClipboardCheck,
 Cpu,
 Download,
 Edit3,
 FileText,
 GraduationCap,
 LayoutDashboard,
 LogOut,
 Menu,
 Minus,
 Moon,
 Plus,
 RefreshCw,
 Search,
 Settings,
 School,
 ShieldCheck,
 ShieldOff,
 Sun,
 Trash2,
 Users,
 UserRound,
 UserX,
 Wifi,
 WifiOff,
 X,
 SearchCheck,
 Upload,
 History,
 Radio,
 FileWarning,
 KeyRound,
 Eye,
} from "lucide-react";
import "./styles/admin-dashboard.css";

import { toast } from "../lib/toast";
import RowMenu from "./ui/RowMenu";
import DonutGauge from "./ui/DonutGauge";
import TrendChart from "./ui/TrendChart";
import { attendancePercentage, fileToAvatarBase64, formatLogDetail, last7Days, relativeTime, statusClass, WORK_DAYS } from "../lib/helpers";
import AttendanceExportModal from "./AttendanceExportPanel";
import ExportDataModal from "./ExportDataModal";
import ReportsPanel from "./ReportsPanel";

const menu = [
 ["dashboard", "Dashboard", LayoutDashboard],
 ["attendance", "Absensi", ClipboardCheck],
 ["students", "Siswa", Users],
 ["teachers", "Guru", GraduationCap],
 ["classes", "Kelas", School],
 ["schedule", "Jadwal", CalendarDays],
 ["devices", "Perangkat IoT", Cpu],
 ["rfid", "Kartu RFID", CreditCard],
 ["logs", "Log Sistem", ScrollText],
 ["reports", "Laporan", FileText],
 ["settings", "Pengaturan", Settings],
 ["corrections", "Koreksi Absensi", FileWarning],
 ["password-reset", "Reset Password", KeyRound],
];

const pageInfo = {
 dashboard: ["Dashboard", "Ringkasan sistem PresenTech"],
 attendance: ["Absensi", "Kelola riwayat kehadiran siswa"],
 students: ["Siswa", "Kelola data siswa"],
 teachers: ["Guru", "Kelola data guru"],
 classes: ["Kelas", "Kelola data kelas"],
 schedule: ["Jadwal", "Kelola jadwal pelajaran sekolah"],
 devices: ["Perangkat IoT", "Kelola perangkat dan pantau statusnya"],
 rfid: ["Kartu RFID", "Kelola kartu RFID siswa"],
 logs: ["Log Sistem", "Riwayat aktivitas sistem"],
 reports: ["Laporan", "Buat dan export laporan"],
 settings: ["Pengaturan", "Konfigurasi sistem"],
 corrections: ["Koreksi Absensi", "Tinjau pengajuan perubahan kehadiran"],
 "password-reset": ["Reset Password", "Tinjau permintaan reset password pengguna"],
};

// Sidebar terkelompok: tiap kategori bisa dilipat, grup halaman aktif
// selalu terbuka agar konteks navigasi tidak hilang.
const SIDEBAR_GROUPS = [
 { id: "utama", title: "Utama", items: ["dashboard", "attendance"] },
 { id: "master", title: "Data Master", items: ["students", "teachers", "classes", "schedule"] },
 { id: "iot", title: "Manajemen IoT", items: ["devices", "rfid"] },
 { id: "laporan", title: "Laporan & Log", items: ["reports", "logs"] },
 { id: "pengaturan", title: "Sistem & Pengaturan", items: ["corrections", "password-reset", "settings"] },
];

const read = (key, fallback) => {
 try {
 const value = localStorage.getItem(key);
 return value ? JSON.parse(value) : fallback;
 } catch {
 return fallback;
 }
};

const NOTIFICATION_TTL = 24 * 60 * 60 * 1000;

function cleanNotifications(list) {
 const now = Date.now();
 let changed = false;
 const cleaned = (Array.isArray(list) ? list : []).map((item) => {
 if (!item || typeof item !== "object") return item;
 if (item.createdAt) return item;
 changed = true;
 return { ...item, createdAt: now };
 }).filter((item) => {
 if (!item || typeof item !== "object") return false;
 const createdAt = Number(item.createdAt);
 return !Number.isFinite(createdAt) || now - createdAt < NOTIFICATION_TTL;
 });
 return { list: cleaned, changed };
}

const localDateKey = (date = new Date()) => {
 const y = date.getFullYear();
 const m = String(date.getMonth() + 1).padStart(2, "0");
 const d = String(date.getDate()).padStart(2, "0");
 return `${y}-${m}-${d}`;
};

function AdminDashboard({ onNavigate, darkMode, setDarkMode }) {
 const [activePage, setActivePage] = useState("dashboard");
 const [closedGroups, setClosedGroups] = useState(() => {
 try {
 const stored = JSON.parse(localStorage.getItem("pt_sidebar_closed") || "[]");
 return Array.isArray(stored) ? stored : [];
 } catch {
 return [];
 }
 });

 const toggleGroup = (groupId) => {
 setClosedGroups((current) => {
 const next = current.includes(groupId) ? current.filter((id) => id !== groupId) : [...current, groupId];
 localStorage.setItem("pt_sidebar_closed", JSON.stringify(next));
 return next;
 });
 };
 const [sidebarOpen, setSidebarOpen] = useState(false);
 const [students, setStudents] = useState([]);
 const [teachers, setTeachers] = useState([]);
 const [classes, setClasses] = useState([]);
 const [schedule, setSchedule] = useState([]);
 const [devices, setDevices] = useState([]);
 const [attendance, setAttendance] = useState([]);
 const [rfidCards, setRfidCards] = useState([]);
 const [todayHoliday, setTodayHoliday] = useState(null);
 const [logs, setLogs] = useState([]);
 const [notificationsOpen, setNotificationsOpen] = useState(false);
 const notificationWrapRef = useRef(null);

 // Klik di luar dropdown, tekan Escape, atau buka menu lain langsung menutup panel.
 useEffect(() => {
  if (!notificationsOpen) return undefined;
  const onPointerDown = (event) => {
   if (notificationWrapRef.current && !notificationWrapRef.current.contains(event.target)) {
    setNotificationsOpen(false);
   }
  };
  const onKeyDown = (event) => {
   if (event.key === "Escape") setNotificationsOpen(false);
  };
  document.addEventListener("mousedown", onPointerDown);
  document.addEventListener("keydown", onKeyDown);
  return () => {
   document.removeEventListener("mousedown", onPointerDown);
   document.removeEventListener("keydown", onKeyDown);
  };
 }, [notificationsOpen]);
 const [welcomeToast, setWelcomeToast] = useState(true);
 const [modal, setModal] = useState(null);
 const [confirm, setConfirm] = useState(null);
 const [dataLoading, setDataLoading] = useState(true);
 const [loadError, setLoadError] = useState("");
 const [apiHealth, setApiHealth] = useState(null);
 const [notifications, setNotifications] = useState(() => {
 const stored = read("pt_notifications", []);
 const cleaned = cleanNotifications(stored).list;
 return cleaned;
 });
 const [profile, setProfile] = useState(() => read("presentechProfile", {
 name: "Administrator", username: "admin", email: "admin@presentech.local", phone: "", photo: ""
 }));

 useEffect(() => {
 const cleaned = cleanNotifications(notifications).list;
 if (cleaned.length !== notifications.length) setNotifications(cleaned);
 localStorage.setItem("pt_notifications", JSON.stringify(cleaned));
 }, [notifications]);
 useEffect(() => {
 let cancelled=false;
 const load=async()=>{try{const r=await import("../api/notificationApi").then(m=>m.getNotifications({recipient_role:"admin",per_page:50})); const arr=Array.isArray(r)?r:(r?.data||[]); if(!cancelled)setNotifications(arr.map(x=>({id:x.id,title:x.title,detail:x.message,type:x.type||"system",read:x.is_read,createdAt:new Date(x.created_at||Date.now()).getTime(),time:"Baru saja"})));}catch{/* polling notifikasi gagal; dicoba lagi otomatis */}};
 if(localStorage.getItem("presentechToken")) load(); const timer=setInterval(load,10000); return()=>{cancelled=true;clearInterval(timer)};
 }, []);

 useEffect(() => localStorage.setItem("presentechProfile", JSON.stringify(profile)), [profile]);
 // Identitas admin asli dari server (nama/telepon/foto) tanpa ini,
 // dashboard selamanya menampilkan placeholder "Administrator".
 useEffect(() => {
 let cancelled = false;
 (async () => {
 try {
 const me = await getCurrentUser();
 const user = me?.user;
 if (cancelled || !user) return;
 localStorage.setItem("presentechUser", JSON.stringify(user));
 setProfile((p) => ({
 ...p,
 name: user.name || p.name,
 username: user.username || p.username,
 phone: user.phone || "",
 photo: user.avatar || "",
 }));
 } catch { /* gagal memuat profil: lanjut dengan data cache */ }
 })();
 return () => { cancelled = true; };
 }, []);
 useEffect(() => {
 let cancelled = false;
 const unwrap = (value) => Array.isArray(value) ? value : (Array.isArray(value?.data) ? value.data : []);
 const load = async () => {
 setDataLoading(true);
 try {
 setLoadError("");
 const [stu, tea, cls, sch, dev, att, rfid, notif] = await Promise.all([
 import("../api/studentApi").then(m => m.getStudents({ per_page: 1000 })),
 import("../api/teacherApi").then(m => m.getTeachers({ per_page: 1000 })),
 import("../api/classApi").then(m => m.getClasses({ per_page: 1000 })),
 import("../api/scheduleApi").then(m => m.getSchedules({ per_page: 1000 })),
 import("../api/deviceApi").then(m => m.getDevices({ per_page: 1000 })),
 import("../api/attendanceApi").then(m => m.getAttendances({ per_page: 1000 })),
 import("../api/rfidApi").then(m => m.getRfidCards({ per_page: 1000 })),
 import("../api/notificationApi").then(m => m.getNotifications({ recipient_role: "admin", per_page: 50 })),
 ]);
 if (cancelled) return;
 setStudents(unwrap(stu).map(x => ({ id:x.id, user_id:x.user_id || x.user?.id || null, nis:x.nis, name:x.name, no_absen:x.no_absen ?? null, className:x.school_class?.name || "Kelas belum ditentukan", major:x.school_class?.major || "", class_id:x.class_id, uid:x.rfid_cards?.[0]?.uid || "", status:x.status, hasAccount:Boolean(x.user_id || x.user) })));
 setTeachers(unwrap(tea).map(x => ({ id:x.id, user_id:x.user_id || x.user?.id || null, nip:x.nip, name:x.name, subject:x.subject || "", phone:x.phone || "", status:x.status, role:x.user?.role || "guru", hasAccount:Boolean(x.user_id || x.user) })));
 setClasses(unwrap(cls).map(x => ({ id:x.id, name:x.name, major:x.major || "", students:x.students_count ?? x.student_count ?? 0, teacher:x.homeroom_teacher?.name || "" })));
 setSchedule(unwrap(sch).map(x => ({ id:x.id, day:x.day, time:String(x.start_time).slice(0,5), endTime:String(x.end_time).slice(0,5), subject:x.subject, room:x.room || "", teacher:x.teacher?.name || "", teacher_id:x.teacher_id, className:x.school_class?.name || "", class_id:x.class_id, academic_year:x.academic_year, semester:x.semester })));
 setDevices(unwrap(dev).map(x => ({ id:x.id, device_id:x.device_id, name:x.name, location:x.location || "", connection:x.connection_type || "Unknown", display_name:x.display_name || "", school_label:x.school_label || "", theme_color:x.theme_color || "", status:String(x.status || "Offline").toLowerCase(), last_seen_at:x.last_seen_at, last_restart_at:x.last_restart_at, restart_requested_at:x.restart_requested_at, firmware_version:x.firmware_version || "", ip_address:x.ip_address || "", ota_requested_at:x.ota_requested_at || null, ota_status:x.ota_status || "", ota_reported_at:x.ota_reported_at || null, ota_message:x.ota_message || "", firmware_latest:x.firmware_latest || "" })));
 setAttendance(unwrap(att).map(x => ({ id:x.id, nis:x.student?.nis || "", name:x.student?.name || "", noAbsen:x.student?.no_absen ?? null, className:x.student?.school_class?.name || "Kelas belum ditentukan", date:String(x.attendance_date).slice(0,10), time:String(x.attendance_time).slice(0,5), checkOut:x.check_out_time ? String(x.check_out_time).slice(0,5) : "-", earlyLeave:Boolean(x.early_leave), notes:x.notes || "", status:x.status, schedule_id:x.schedule_id })));
 setRfidCards(unwrap(rfid).map(x => ({ id:x.id, uid:x.uid, student:x.student?.name || "", nis:x.student?.nis || "", student_id:x.student_id, className:x.student?.school_class?.name || "", status:x.status, source:x.source || x.label || "Manual" })));
 const ns = unwrap(notif).map(x => ({ id:x.id, title:x.title, detail:x.message, type:x.type || "system", read:x.is_read, createdAt:new Date(x.created_at || Date.now()).getTime(), time:"Baru saja" }));
 if (ns.length) setNotifications(ns);
 } catch (error) {
 console.warn("PresenTech API belum tersedia; data utama tetap dikosongkan agar tidak menampilkan cache palsu.", error);
 if (!cancelled) {
 setLoadError(error?.response?.data?.message || "Data dashboard gagal dimuat. Pastikan API Laravel aktif, lalu muat ulang.");
 }
 } finally {
 if (!cancelled) setDataLoading(false);
 }
 };
 if (localStorage.getItem("presentechToken")) load();
 return () => { cancelled = true; };
 }, []);
 // Status perangkat live: polling devices tiap 5 detik setelah login,
 // plus saat tab kembali aktif tidak menunggu siklus server.
 useEffect(() => {
 let cancelled = false;
 const unwrap = (value) => Array.isArray(value) ? value : (Array.isArray(value?.data) ? value.data : []);
 const refreshDevices = async () => {
 try {
 const dev = await import("../api/deviceApi").then(m => m.getDevices({ per_page: 1000 }));
 if (cancelled) return;
 setDevices(unwrap(dev).map(x => ({ id:x.id, device_id:x.device_id, name:x.name, location:x.location || "", connection:x.connection_type || "Unknown", display_name:x.display_name || "", school_label:x.school_label || "", theme_color:x.theme_color || "", status:String(x.status || "Offline").toLowerCase(), last_seen_at:x.last_seen_at, last_restart_at:x.last_restart_at, restart_requested_at:x.restart_requested_at, firmware_version:x.firmware_version || "", ip_address:x.ip_address || "", ota_requested_at:x.ota_requested_at || null, ota_status:x.ota_status || "", ota_reported_at:x.ota_reported_at || null, ota_message:x.ota_message || "", firmware_latest:x.firmware_latest || "" })));
 } catch { /* polling device gagal: dicoba lagi otomatis */ }
 };
 const focus = () => refreshDevices();
 if (localStorage.getItem("presentechToken")) refreshDevices();
 const timer = setInterval(refreshDevices, 5000);
 window.addEventListener("focus", focus);
 return () => { cancelled = true; clearInterval(timer); window.removeEventListener("focus", focus); };
 }, []);
 useEffect(() => {
 let cancelled=false; const load=async()=>{try{const r=await import("../api/systemLogApi").then(m=>m.getSystemLogs({per_page:100}));const arr=Array.isArray(r)?r:(r?.data||[]);if(!cancelled)setLogs(arr.map(x=>({id:x.id,time:new Date(x.created_at||Date.now()).toLocaleTimeString("id-ID"),message:x.message||x.action,detail:formatLogDetail(x.metadata),type:x.type||"system"})));}catch{/* polling log gagal; dicoba lagi otomatis */}}; if(localStorage.getItem("presentechToken"))load(); const t=setInterval(load,15000); return()=>{cancelled=true;clearInterval(t)};
 }, []);
 // Status hari libur: dipakai agar dashboard tidak menampilkan kehadiran 0%
 // pada tanggal libur. Dicek ulang tiap 5 menit dan saat tab kembali aktif.
 useEffect(() => {
 let cancelled = false;
 const load = async () => {
 try {
 const result = await import("../api/holidayApi").then(m => m.getTodayHoliday());
 if (!cancelled) setTodayHoliday(result?.is_holiday ? result.holiday : null);
 } catch { /* status libur opsional; dashboard tetap tampil normal */ }
 };
 const focus = () => load();
 if (localStorage.getItem("presentechToken")) load();
 const timer = setInterval(load, 5 * 60 * 1000);
 window.addEventListener("focus", focus);
 return () => { cancelled = true; clearInterval(timer); window.removeEventListener("focus", focus); };
 }, []);
 useEffect(() => {
 // Cek kesehatan API sungguhan (endpoint publik /api/health).
 let cancelled = false;
 const check = async () => {
 try {
 await import("../api/healthApi").then(m => m.checkApiHealth());
 if (!cancelled) setApiHealth(true);
 } catch {
 if (!cancelled) setApiHealth(false);
 }
 };
 check();
 const timer = setInterval(check, 10000);
 return () => { cancelled = true; clearInterval(timer); };
 }, []);
 useEffect(() => {
 if (!welcomeToast) return;
 const timer = setTimeout(() => setWelcomeToast(false), 4200);
 return () => clearTimeout(timer);
 }, [welcomeToast]);

 const onlineDevices = devices.filter((d) => d.status === "online").length;
 const currentDate = localDateKey();
 // Persentase kehadiran HARI INI = (Hadir + Terlambat) / total siswa;
 // rumus yang sama dipakai di dashboard Guru dan Siswa.
 const todayAttendance = attendance.filter((a) => a.date === currentDate);
 const presentToday = todayAttendance.filter((x) => x.status === "Hadir").length;
 const lateToday = todayAttendance.filter((x) => x.status === "Terlambat").length;
 const attendanceRate = students.length ? attendancePercentage(presentToday, lateToday, students.length) : 0;

 const navigate = (page) => {
 setActivePage(page);
 setSidebarOpen(false);
 };

 const logout = async () => {
 try { await apiLogout(); } catch { /* sesi sudah tidak valid; tetap bersihkan lokal */ }
 clearPresenTechSession();
 onNavigate?.("login");
 };

 // Muat ulang data siswa & kelas (dipakai setelah import CSV).
 const reloadAll = async () => {
 const unwrap = (value) => Array.isArray(value) ? value : (Array.isArray(value?.data) ? value.data : []);
 const [stu, cls] = await Promise.all([
 import("../api/studentApi").then(m => m.getStudents({ per_page: 1000 })),
 import("../api/classApi").then(m => m.getClasses({ per_page: 1000 })),
 ]);
 setStudents(unwrap(stu).map(x => ({ id:x.id, user_id:x.user_id || x.user?.id || null, nis:x.nis, name:x.name, no_absen:x.no_absen ?? null, className:x.school_class?.name || "Kelas belum ditentukan", major:x.school_class?.major || "", class_id:x.class_id, uid:x.rfid_cards?.[0]?.uid || "", status:x.status, hasAccount:Boolean(x.user_id || x.user) })));
 setClasses(unwrap(cls).map(x => ({ id:x.id, name:x.name, major:x.major || "", students:x.students_count ?? x.student_count ?? 0, teacher:x.homeroom_teacher?.name || "" })));
 };

 const performSaveItem = async (type, item, existing = false) => {
 try {
 let saved;
 if (type === "student") {
 const payload = { nis:item.nis, name:item.name, no_absen:item.no_absen === "" || item.no_absen === null || item.no_absen === undefined ? null : Number(item.no_absen), class_id:item.class_id || null, phone:item.phone || null, status:item.status || "Aktif" };
 saved = existing ? await import("../api/studentApi").then(m=>m.updateStudent(item.id,payload)) : await import("../api/studentApi").then(m=>m.createStudent(payload));
 const x=saved?.data || saved;
 const normalized={id:x.id,nis:x.nis,name:x.name,no_absen:x.no_absen ?? null,className:x.school_class?.name || "Kelas belum ditentukan",major:x.school_class?.major || item.major || "",class_id:x.class_id,uid:x.rfid_cards?.[0]?.uid || item.uid || "",status:x.status};
 setStudents(old=>existing?old.map(v=>v.id===item.id?normalized:v):[...old,normalized]);
 } else if (type === "teacher") {
 const payload={nip:item.nip,name:item.name,subject:item.subject || null,phone:item.phone || null,status:item.status || "Aktif"};
 saved=existing?await import("../api/teacherApi").then(m=>m.updateTeacher(item.id,payload)):await import("../api/teacherApi").then(m=>m.createTeacher(payload));
 const x=saved?.data || saved; setTeachers(old=>existing?old.map(v=>v.id===item.id?{...v,id:x.id,nip:x.nip,name:x.name,subject:x.subject||"",phone:x.phone||"",status:x.status}:v):[...old,{id:x.id,nip:x.nip,name:x.name,subject:x.subject||"",phone:x.phone||"",status:x.status}]);
 } else if (type === "class") {
 const payload={name:item.name,major:item.major||null,student_count:Number(item.students||0),homeroom_teacher_id:item.homeroom_teacher_id?Number(item.homeroom_teacher_id):null};
 saved=existing?await import("../api/classApi").then(m=>m.updateClass(item.id,payload)):await import("../api/classApi").then(m=>m.createClass(payload));
 const x=saved?.data || saved; setClasses(old=>existing?old.map(v=>v.id===item.id?{...v,id:x.id,name:x.name,major:x.major||"",students:x.students_count??x.student_count??0,teacher:x.homeroom_teacher?.name||"",homeroom_teacher_id:x.homeroom_teacher_id || x.homeroom_teacher?.id || null}:v):[...old,{id:x.id,name:x.name,major:x.major||"",students:x.students_count??x.student_count??0,teacher:x.homeroom_teacher?.name||"",homeroom_teacher_id:x.homeroom_teacher_id || x.homeroom_teacher?.id || null}]);
 } else {
 const payload={device_id:item.device_id?.trim() || "",name:item.name,location:item.location,connection_type:item.connection || "WiFi",display_name:item.display_name?.trim() || null,school_label:item.school_label?.trim() || null,theme_color:item.theme_color || null,status:item.status === "online" ? "Online" : item.status === "maintenance" ? "Maintenance" : "Offline"};
 saved=existing?await import("../api/deviceApi").then(m=>m.updateDevice(item.id,payload)):await import("../api/deviceApi").then(m=>m.createDevice(payload));
 const x=saved?.data || saved;
 // Device key baru hanya dikirim server sekali saat create
 // (tersimpan sebagai hash) tampilkan agar bisa dikonfigurasi ke ESP32.
 if (!existing && x?.device_key_plain) showDeviceKeyOnce(x, x.device_key_plain);
 setDevices(old=>existing?old.map(v=>v.id===item.id?{...v,id:x.id,device_id:x.device_id,name:x.name,location:x.location||"",connection:x.connection_type||"Unknown",display_name:x.display_name||"",school_label:x.school_label||"",theme_color:x.theme_color||"",status:String(x.status||"Offline").toLowerCase(),last_seen_at:x.last_seen_at,last_restart_at:x.last_restart_at}:v):[...old,{id:x.id,device_id:x.device_id,name:x.name,location:x.location||"",connection:x.connection_type||"Unknown",display_name:x.display_name||"",school_label:x.school_label||"",theme_color:x.theme_color||"",status:String(x.status||"Offline").toLowerCase(),last_seen_at:x.last_seen_at,last_restart_at:x.last_restart_at}]);
 }
 } catch (error) {
 console.error(error);
 toast.error(error?.response?.data?.message || "Data gagal disimpan ke database. Pastikan API Laravel aktif.");
 // Kirim ulang supaya ConfirmModal tidak menutup; admin masih bisa megerjakan ulang.
 throw error;
 }
 setLogs(old=>[{id:Date.now(),time:new Date().toLocaleTimeString("id-ID"),message:`Admin ${existing?"mengubah":"menambahkan"} data ${typeLabel(type)}`,type:"system"},...old].slice(0,200));
 setModal(null);
 };

 const saveItem = async (type, item) => {
 const existing = item?.__existing;
 const clean = { ...item };
 delete clean.__existing;
 setConfirm({
 title: existing ? `Ubah Data ${typeLabel(type)}?` : `Simpan Data ${typeLabel(type)}?`,
 message: existing ? `Anda yakin ingin mengubah data ${clean.name || typeLabel(type)}? Perubahan akan disimpan ke sistem.` : `Pastikan data ${clean.name || typeLabel(type)} sudah benar sebelum disimpan.`,
 confirmLabel: existing ? "Simpan Perubahan" : "Simpan",
 tone: "primary",
 onConfirm: () => performSaveItem(type, clean, existing),
 });
 };

 const deleteItem = (type, id) => {
 const [setter, label] = type === "student" ? [setStudents, "siswa"] : type === "teacher" ? [setTeachers, "guru"] : type === "class" ? [setClasses, "kelas"] : [setDevices, "perangkat"];
 const target = (type === "student" ? students : type === "teacher" ? teachers : type === "class" ? classes : devices).find((x) => x.id === id);
 if (!target) return;
 setConfirm({
 title: `Hapus ${label}?`,
 message: `Anda yakin ingin menghapus ${target.name || target.className || label} dari sistem? Data ini sebaiknya diarsipkan jika masih dibutuhkan untuk riwayat.`,
 confirmLabel: "Hapus",
 tone: "danger",
 onConfirm: async () => {
 try {
 if (type === "student") await import("../api/studentApi").then(m=>m.deleteStudent(id));
 if (type === "teacher") await import("../api/teacherApi").then(m=>m.deleteTeacher(id));
 if (type === "class") await import("../api/classApi").then(m=>m.deleteClass(id));
 if (type === "device") await import("../api/deviceApi").then(m=>m.deleteDevice(id));
 } catch (error) {
 console.error(error);
 toast.error(error?.response?.data?.message || "Data gagal dihapus dari database.");
 throw error;
 }
 setter((old) => old.filter((x) => x.id !== id));
 setLogs((old) => [{ id: Date.now(), time: new Date().toLocaleTimeString("id-ID"), message: `Admin menghapus ${label}`, type: "system" }, ...old].slice(0,200));
 },
 });
 };
 const bulkDelete = (type, ids) => {
 if (!ids.length) return;

 const [setter, label, deleteOne] = type === "student"
 ? [setStudents, "siswa", (id) => import("../api/studentApi").then((m) => m.deleteStudent(id))]
 : type === "teacher"
 ? [setTeachers, "guru", (id) => import("../api/teacherApi").then((m) => m.deleteTeacher(id))]
 : type === "class"
 ? [setClasses, "kelas", (id) => import("../api/classApi").then((m) => m.deleteClass(id))]
 : [setDevices, "perangkat", (id) => import("../api/deviceApi").then((m) => m.deleteDevice(id))];

 setConfirm({
 title: `Hapus ${ids.length} ${label}?`,
 message: `Anda yakin ingin menghapus ${ids.length} ${label} terpilih? Tindakan ini tidak bisa dibatalkan.`,
 confirmLabel: `Hapus ${ids.length}`,
 tone: "danger",
 onConfirm: async () => {
 let removed = 0;
 let failed = 0;

 for (const id of ids) {
 try {
 await deleteOne(id);
 removed += 1;
 } catch {
 failed += 1;
 }
 }

 const removedIds = new Set(ids);
 setter((old) => old.filter((x) => !removedIds.has(x.id)));
 setLogs((old) => [{ id: Date.now(), time: new Date().toLocaleTimeString("id-ID"), message: `Admin menghapus ${removed} ${label} sekaligus`, type: "system" }, ...old].slice(0, 200));

 if (failed) {
 toast.error(`${failed} ${label} gagal dihapus.`);
 if (type === "student" || type === "teacher" || type === "class") {
 try { await reloadAll?.(); } catch { /* daftar akan disegarkan pengguna */ }
 }
 }
 },
 });
 };

 const refreshDevices = async () => {
 try {
 const r = await import("../api/deviceApi").then(m => m.getDevices({ per_page: 1000 }));
 const arr = Array.isArray(r) ? r : (r?.data || []);
 setDevices(arr.map(x => ({ id:x.id, device_id:x.device_id, name:x.name, location:x.location || "", connection:x.connection_type || "Unknown", display_name:x.display_name || "", school_label:x.school_label || "", theme_color:x.theme_color || "", status:String(x.status || "Offline").toLowerCase(), last_seen_at:x.last_seen_at, last_restart_at:x.last_restart_at, restart_requested_at:x.restart_requested_at, firmware_version:x.firmware_version || "", ip_address:x.ip_address || "", ota_requested_at:x.ota_requested_at || null, ota_status:x.ota_status || "", ota_reported_at:x.ota_reported_at || null, ota_message:x.ota_message || "", firmware_latest:x.firmware_latest || "" })));
 } catch (error) {
 toast.error(error?.response?.data?.message || "Gagal memperbarui status perangkat.");
 }
 };

 // Angkat/Cabut akses Admin dari guru via halaman Data Guru.
 const toggleTeacherAdmin = (teacher) => {
 const isAdmin = teacher.role === "admin";
 setConfirm({
 title: isAdmin ? "Cabut Akses Admin?" : "Angkat Menjadi Admin?",
 message: isAdmin
 ? `${teacher.name} akan kembali menjadi guru biasa dan tidak bisa membuka Dashboard Admin.`
 : `${teacher.name} akan mendapat akses Dashboard Admin dan tetap tercatat sebagai data guru.`,
 confirmLabel: isAdmin ? "Cabut Akses" : "Jadikan Admin",
 tone: isAdmin ? "danger" : "primary",
 chain: true,
 onConfirm: async () => {
 try {
 await import("../api/teacherApi").then(m => (isAdmin ? m.demoteTeacher(teacher.id) : m.promoteTeacher(teacher.id)));
 setTeachers((old) => old.map((t) => (t.id === teacher.id ? { ...t, role: isAdmin ? "guru" : "admin" } : t)));
 setLogs((old) => [{ id: Date.now(), time: new Date().toLocaleTimeString("id-ID"), message: isAdmin ? `Akses Admin dicabut dari ${teacher.name}` : `${teacher.name} diangkat menjadi Admin`, type: "system" }, ...old].slice(0, 200));
 setConfirm({
 title: isAdmin ? "Akses Dicabut" : "Akses Diberikan",
 message: isAdmin ? `${teacher.name} kembali menjadi guru. Login berikutnya masuk Dashboard Guru.` : `${teacher.name} sekarang bisa login ke Dashboard Admin dengan akun yang sama.`,
 confirmLabel: "Mengerti",
 tone: "primary",
 onConfirm: () => {},
 });
 } catch (error) {
 setConfirm({
 title: "Gagal Mengubah Akses",
 message: error?.response?.data?.message || "Tidak bisa mengubah akses Admin guru ini.",
 confirmLabel: "Mengerti",
 tone: "danger",
 onConfirm: () => {},
 });
 }
 },
 });
 };

 const createLoginAccount = (type, item) => {
 const identifier = type === "student" ? item.nis : item.nip;
 setConfirm({
 title: "Buat Akun Login?",
 message: `Akun login untuk ${item.name} akan dibuat dengan username dan password awal yang sama: ${type === "student" ? "NIS" : "NIP"} ${identifier}. Pengguna wajib mengganti password saat login pertama.`,
 confirmLabel: "Buat Akun",
 tone: "primary",
 chain: true,
 onConfirm: async () => {
 try {
 const api = await import(type === "student" ? "../api/studentApi" : "../api/teacherApi");
 const result = type === "student"
 ? await api.createStudentAccount(item.id)
 : await api.createTeacherAccount(item.id);
 if (type === "student") {
 setStudents((old) => old.map((s) => (s.id === item.id ? { ...s, hasAccount: true } : s)));
 } else {
 setTeachers((old) => old.map((t) => (t.id === item.id ? { ...t, hasAccount: true } : t)));
 }
 setLogs((old) => [{ id: Date.now(), time: new Date().toLocaleTimeString("id-ID"), message: `Akun login dibuat untuk ${item.name}`, detail: identifier, type: "system" }, ...old].slice(0, 200));
 setConfirm({
 title: "Akun Login Dibuat",
 message: `Akun siap dipakai.\nUsername: ${identifier}\nPassword sementara: ${identifier}\nPengguna wajib mengganti password saat login pertama.`,
 confirmLabel: "Selesai",
 input: result?.initial_password || identifier,
 copyable: true,
 onConfirm: () => {},
 });
 } catch (error) {
 toast.error(error?.response?.data?.message || "Akun login gagal dibuat.");
 throw error;
 }
 },
 });
 };

 // Akun yang sudah ada tapi password-nya hilang bisa direset langsung
 // tanpa menunggu pengajuan. Password dikembalikan sama dengan NIS/NIP
 // dan sesi lama langsung dicabut agar tidak ada sesi nyangkut.
 const resetLoginPassword = (type, item) => {
 if (!item.user_id) {
 toast.error("Akun login untuk data ini belum tersedia. Buat akun terlebih dahulu.");
 return;
 }
 const identifier = type === "student" ? item.nis : item.nip;
 setConfirm({
 title: "Reset Password Akun?",
 message: `Password untuk ${item.name} akan dikembalikan sama dengan ${type === "student" ? "NIS" : "NIP"} ${identifier}. Sesi login lama langsung berakhir dan pengguna bisa login memakai NIS/NIP.`,
 confirmLabel: "Reset Password",
 tone: "danger",
 chain: true,
 onConfirm: async () => {
 try {
 const result = await adminSetPassword(item.user_id);
 setConfirm({
 title: "Password Direset",
 message: `Akun ${item.name} sekarang memakai username dan password yang sama:\n${identifier}`,
 confirmLabel: "Selesai",
 input: result?.initial_password || identifier,
 copyable: true,
 onConfirm: () => {},
 });
 setLogs((old) => [{ id: Date.now(), time: new Date().toLocaleTimeString("id-ID"), message: `Password direset untuk ${item.name}`, detail: type === "student" ? item.nis : item.nip, type: "system" }, ...old].slice(0, 200));
 } catch (error) {
 toast.error(error?.response?.data?.message || "Password gagal direset.");
 throw error;
 }
 },
 });
 };

 const resetDevice = (device) => {
 setConfirm({
 title: "Restart Perangkat?",
 message: `Anda yakin ingin merestart ${device.name}? Restart memicu perangkat menetapkan ulang koneksi ke server.`,
 confirmLabel: "Restart Perangkat",
 tone: "danger",
 onConfirm: async () => {
 try {
 const response = await import("../api/deviceApi").then(m => m.restartDevice(device.id));
 const x = response?.device || response?.data || response;
 setDevices((old) => old.map((d) => d.id === device.id ? { ...d, last_restart_at: x?.last_restart_at || null } : d));
 setLogs((old) => [{ id: Date.now(), time: new Date().toLocaleTimeString("id-ID"), message: `${device.name} di-restart oleh Admin`, type: "device" }, ...old].slice(0,200));
 } catch (error) {
 toast.error(error?.response?.data?.message || "Perangkat gagal di-restart.");
 throw error;
 }
 },
 });
 };

 const rotateDeviceKey = (device) => {
 setConfirm({
 title: "Generate Device Key Baru?",
 message: `Key lama ${device.name} akan langsung mati. Salin key baru ke perangkat ESP32 sebelum sekarang ditutup, karena key hanya ditampilkan sekali.`,
 confirmLabel: "Generate Key Baru",
 tone: "danger",
 chain: true,
 onConfirm: async () => {
 try {
 const response = await import("../api/deviceApi").then(m => m.rotateDeviceKey(device.id));
 const plainKey = response?.device_key_plain || response?.device_key || response?.data?.device_key_plain || response?.data?.device_key;
 showDeviceKeyOnce(device, plainKey);
 setLogs((old) => [{ id: Date.now(), time: new Date().toLocaleTimeString("id-ID"), message: `Device key ${device.name} dirotasi oleh Admin`, type: "device" }, ...old].slice(0,200));
 } catch (error) {
 toast.error(error?.response?.data?.message || "Key gagal dirotasi.");
 throw error;
 }
 },
 });
 };

 // Tampilkan device key tepat sekali, tanpa penyimpanan ke state/localStorage.
 const showDeviceKeyOnce = (device, plainKey) => {
 if (!plainKey) {
 toast.error("Device key tidak tersedia pada respons server.");
 return;
 }
 setConfirm({
 title: "Device Key Dibuat",
 message: `Key untuk ${device.name}:\n\n${plainKey}\n\nSekarang salin ke perangkat ESP32 (header X-Device-Key). Key ini TIDAK akan ditampilkan lagi tersimpan sebagai hash di server.`,
 confirmLabel: "Saya Sudah Simpan Key",
 tone: "primary",
 onConfirm: () => {},
 });
 };

 return (
 <div className={`admin-dashboard ${darkMode ? "dark" : "light"}`}>
 {sidebarOpen && <button className="admin-overlay" aria-label="Tutup menu" onClick={() => setSidebarOpen(false)} />}

 <aside className={`admin-sidebar ${sidebarOpen ? "open" : ""}`}>
 <div className="admin-brand">
 <button onClick={() => navigate("dashboard")} className="brand-button">
 <img src={darkMode ? `${import.meta.env.BASE_URL}logo-dark.png` : `${import.meta.env.BASE_URL}logo-light.png`} alt="PresenTech" />
 <span><strong>PresenTech</strong><small>ADMIN PANEL</small></span>
 </button>
 <button className="icon-button mobile-close" onClick={() => setSidebarOpen(false)}><X size={19} /></button>
 </div>
 <nav className="nav-groups">
 {SIDEBAR_GROUPS.map((group) => {
 const items = group.items.map((id) => menu.find((entry) => entry[0] === id)).filter(Boolean);
 const groupActive = group.items.includes(activePage);
 const open = groupActive || !closedGroups.includes(group.id);

 return (
 <div className={`nav-group ${open ? "open" : ""}`} key={group.id}>
 <button
 type="button"
 className="nav-group-head"
 aria-expanded={open}
 onClick={() => toggleGroup(group.id)}
 >
 <span>{group.title}</span>
 <ChevronDown size={14} className="nav-group-chevron" />
 </button>
 {open && (
 <div className="nav-group-items">
 {items.map(([id, label, Icon]) => (
 <button key={id} className={`menu-item ${activePage === id ? "active" : ""}`} onClick={() => navigate(id)}>
 <Icon size={18} />
 <span>{label}</span>
 {id === "devices" && <b>{onlineDevices}</b>}
 </button>
 ))}
 </div>
 )}
 </div>
 );
 })}
 </nav>
 <div className="sidebar-account">
 <button className="menu-item" onClick={() => setModal({ type: "profile", item: profile })}><UserRound size={18} /><span>Profil</span></button>
 <button className="logout-button" onClick={logout}><LogOut size={18} /><span>Keluar</span></button>
 </div>
 </aside>

 <section className="admin-main">
 <header className="admin-topbar">
 <div className="topbar-left">
 <button className="icon-button mobile-menu" onClick={() => setSidebarOpen(true)} aria-label="Buka menu"><Menu size={21} /></button>
 <div><h1>{pageInfo[activePage][0]}</h1><p>{pageInfo[activePage][1]}</p></div>
 </div>
 <div className="topbar-actions">
 <button className="icon-button" onClick={() => setDarkMode(!darkMode)} title="Ganti tema">{darkMode ? <Sun size={19} /> : <Moon size={19} />}</button>
 <div className="notification-wrap" ref={notificationWrapRef}>
 <button className="icon-button" onClick={() => setNotificationsOpen((v) => !v)} aria-label="Notifikasi">
 <Bell size={19} />
 {notifications.filter((n) => !n.read && n.type !== "attendance").length > 0 && <i>{Math.min(99, notifications.filter((n) => !n.read && n.type !== "attendance").length)}</i>}
 </button>
 {notificationsOpen && <NotificationPanel notifications={notifications} onRead={async (id) => { try { await import("../api/notificationApi").then(m=>m.markNotificationRead(id)); setNotifications(old=>old.map(n=>n.id===id?{...n,read:true}:n)); } catch {/* gagal tandai baca: biarkan tetap unread agar tidak bertentangan dengan polling */} setNotificationsOpen(false); }} onReadAll={async () => { try { await import("../api/notificationApi").then(m=>m.markAllNotificationsRead()); setNotifications(old=>old.map(n=>({...n,read:true}))); } catch {/* gagal: biarkan tetap unread */} }} />}
 </div>
 <div className="admin-profile-button" title="Profil">
 <Avatar profile={profile} />
 <strong>{profile.name}</strong>
 </div>
 </div>
 </header>

 <main className="admin-content">
 {loadError && (
 <div className="load-error-banner">
 <div><strong>Data gagal dimuat</strong><span>{loadError}</span></div>
 <button className="secondary-button" onClick={() => window.location.reload()}><RefreshCw size={15}/> Muat Ulang</button>
 </div>
 )}
 {dataLoading && !loadError && (
 <div className="loading-banner">Memuat data dari server...</div>
 )}
 {activePage === "dashboard" && <Dashboard profile={profile} students={students} teachers={teachers} attendance={attendance} devices={devices} onlineDevices={onlineDevices} attendanceRate={attendanceRate} todayHoliday={todayHoliday} loading={dataLoading} onNavigate={navigate} apiOk={apiHealth} dbOk={!dataLoading && !loadError} />}
 {activePage === "attendance" && <AttendancePage data={attendance} students={students} classes={classes} setAttendance={setAttendance} setLogs={setLogs} />}
 {activePage === "students" && <CrudPage title="Data Siswa" description="Kelola data siswa PresenTech." type="student" data={students} classes={classes} onImported={reloadAll} onAdd={() => setModal({ type: "student" })} onEdit={(item) => setModal({ type: "student", item })} onDelete={deleteItem} onBulkDelete={(ids) => bulkDelete("student", ids)} onCreateAccount={createLoginAccount} onResetPassword={resetLoginPassword} />}
 {activePage === "teachers" && <CrudPage title="Data Guru" description="Kelola data guru." type="teacher" data={teachers} onAdd={() => setModal({ type: "teacher" })} onEdit={(item) => setModal({ type: "teacher", item })} onDelete={deleteItem} onBulkDelete={(ids) => bulkDelete("teacher", ids)} onToggleAdmin={toggleTeacherAdmin} onCreateAccount={createLoginAccount} onResetPassword={resetLoginPassword} />}
 {activePage === "classes" && <CrudPage title="Data Kelas" description="Kelola kelas dan wali kelas." type="class" data={classes} onAdd={() => setModal({ type: "class" })} onEdit={(item) => setModal({ type: "class", item })} onDelete={deleteItem} onBulkDelete={(ids) => bulkDelete("class", ids)} />}
 {activePage === "schedule" && <SchedulePage schedule={schedule} setSchedule={setSchedule} setLogs={setLogs} setConfirm={setConfirm} classes={classes} teachers={teachers} />}
 {activePage === "devices" && <DevicePage devices={devices} onRefresh={refreshDevices} onRestart={resetDevice} onRotate={rotateDeviceKey} onAdd={() => setModal({ type: "device" })} onEdit={(item) => setModal({ type: "device", item })} onDelete={deleteItem} onBulkDelete={(ids) => bulkDelete("device", ids)} />}
 {activePage === "rfid" && <RfidPage cards={rfidCards} students={students} classes={classes} devices={devices} setCards={setRfidCards} setLogs={setLogs} setConfirm={setConfirm} />}
 {activePage === "logs" && <LogsPage logs={logs} />}
 {activePage === "reports" && <ReportsPanel classes={classes} />}
 {activePage === "settings" && <SettingsPage darkMode={darkMode} setDarkMode={setDarkMode} setLogs={setLogs} setConfirm={setConfirm} onNavigate={navigate} />}
 {activePage === "corrections" && <AdminCorrections />}
 {activePage === "password-reset" && <PasswordResetAdminPage />}
 </main>
 </section>

 {welcomeToast && activePage === "dashboard" && (
 <div className="welcome-toast"><Check size={16} /><div><strong>Selamat datang kembali</strong><span>Dashboard Admin PresenTech siap digunakan.</span></div><button onClick={() => setWelcomeToast(false)} aria-label="Tutup"><X size={14} /></button></div>
 )}

 {modal?.type === "profile" ? <ProfileModal profile={profile} setProfile={setProfile} onClose={() => setModal(null)} onSettings={() => { setModal(null); navigate("settings"); }} /> : modal ? <FormModal type={modal.type} item={modal.item} classes={classes} teachers={teachers} onClose={() => setModal(null)} onSave={saveItem} /> : null}
 {confirm && <ConfirmModal {...confirm} onClose={() => setConfirm(null)} />}
 </div>
 );
}

function Dashboard({ profile, students, teachers, attendance, devices, onlineDevices, attendanceRate, todayHoliday, onNavigate, apiOk, dbOk, loading }) {
 const week = useMemo(() => last7Days(attendance), [attendance]);
 const weekHadir = week.reduce((sum, d) => sum + d.hadir, 0);
 const weekTerlambat = week.reduce((sum, d) => sum + d.terlambat, 0);
 
 return <div className="page-stack">
 <div className="welcome"><div><span className="eyebrow">PRESENTECH ADMIN</span><h2>Selamat datang, {profile.name || "Administrator"}</h2><p>Pantau sistem absensi dan perangkat IoT sekolah dari satu dashboard.</p></div><CalendarDays size={30} /></div>
 {todayHoliday && <div className="holiday-banner"><CalendarOff size={18} /><div><strong>Hari ini libur: {todayHoliday.name}</strong><span>Alpa otomatis tidak dijalankan pada tanggal ini. Tap kartu tetap dapat dicatat.</span></div></div>}
 <div className="summary-row">
 <section className="panel gauge-card">
 <DonutGauge value={loading || todayHoliday ? null : attendanceRate} title="Persentase Kehadiran Hari Ini" caption={loading ? "Memuat" : todayHoliday ? "Hari libur" : "Hari ini"} />
 <div className="gauge-copy">
 <h3>Persentase Kehadiran Hari Ini</h3>
 <p>{todayHoliday ? "Sekolah sedang libur, jadi persentase kehadiran tidak dihitung." : "Perbandingan siswa hadir terhadap total siswa aktif hari ini."}</p>
 {!loading && !todayHoliday && (
 <div className="gauge-legend">
 <span><i />Hadir &amp; terlambat: {attendance.filter((item) => item.date === localDateKey() && ["Hadir", "Terlambat"].includes(item.status)).length}</span>
 <span><i className="muted" />Total siswa: {students.length}</span>
 </div>
 )}
 </div>
 </section>

 <section className="panel quick-card">
 <div className="quick-card-head">
 <div>
 <h3>Pintasan Cepat</h3>
 <p>Menuju tugas yang paling sering dipakai.</p>
 </div>
 <Cpu size={20} />
 </div>
 <div className="quick-actions-grid">
 <button type="button" className="quick-action primary" onClick={() => onNavigate("corrections")}>
 <span className="quick-icon"><FileWarning size={18} /></span>
 <span>Koreksi Absensi<small>Tinjau pengajuan siswa/guru</small></span>
 </button>
 <button type="button" className="quick-action" onClick={() => onNavigate("attendance")}>
 <span className="quick-icon"><ClipboardCheck size={18} /></span>
 <span>Data Absensi<small>Lihat &amp; export kehadiran</small></span>
 </button>
 <button type="button" className="quick-action" onClick={() => onNavigate("devices")}>
 <span className="quick-icon"><Wifi size={18} /></span>
 <span>Perangkat IoT<small>{onlineDevices} perangkat online</small></span>
 </button>
 <button type="button" className="quick-action" onClick={() => onNavigate("students")}>
 <span className="quick-icon"><Users size={18} /></span>
 <span>Data Siswa<small>{students.length} siswa terdaftar</small></span>
 </button>
 </div>
 </section>
 </div>
 <div className="stat-grid"><Stat icon={Users} value={students.length} label="Total Siswa"/><Stat icon={GraduationCap} value={teachers.length} label="Total Guru"/><Stat icon={ClipboardCheck} value={todayHoliday ? "Libur" : `${attendanceRate}%`} label="Kehadiran Hari Ini"/><Stat icon={Cpu} value={`${onlineDevices}/${devices.length}`} label="Perangkat Online"/></div>
 <AdminGlobalTools students={students} devices={devices} onNavigate={onNavigate} apiOk={apiOk} dbOk={dbOk} />
 <div className="two-col">
 <section className="panel">
 <Header title="Kehadiran 7 Hari Terakhir" />
 <TrendChart data={week} />
 <div className="legend"><span><i className="dot blue" />Hadir {weekHadir}</span><span><i className="dot gray" />Terlambat {weekTerlambat}</span></div>
 </section>
 <section className="panel">
 <Header title="Status Perangkat" action="Lihat semua" onClick={() => onNavigate("devices")} />
 {devices.map((d) => (
 <div className="device-row" key={d.id}>
 <div className="device-icon"><Cpu size={17} /></div>
 <div><strong>{d.name}</strong><small>{d.location} · {d.connection}</small></div>
 <span className={`status-badge ${d.status}`}><i />{d.status === "online" ? "Online" : d.status === "maintenance" ? "Maintenance" : "Offline"}</span>
 </div>
 ))}
 </section>
 </div>
 <section className="panel"><Header title="Absensi Terbaru" action="Buka absensi" onClick={()=>onNavigate("attendance")}/><AttendanceTable data={attendance.slice(0,6)}/></section>
 <div className="advanced-grid-admin"><LiveAttendancePanel attendance={attendance} /><WeeklySummaryPanel attendance={attendance} /></div>
 <div className="two-col">
 <ClassMonitor students={students} attendance={attendance} />
 <AttendanceTimeline attendance={attendance} />
 </div>
 </div>;
}

function LiveAttendancePanel({ attendance }) { return <section className="panel live-admin-panel"><Header title="Absensi Terbaru"/><div className="live-feed-admin">{attendance.slice(0,5).map((item)=><div className="live-admin-item" key={item.id}><i/><div><strong>{item.name}</strong><span>{item.className} {item.time}</span></div><b className={`status ${statusClass(item.status)}`}>{item.status}</b></div>)}</div></section>; }

// Pengganti panel "Offline Sync" prototipe: ringkasan nyata dari
// data absensi 7 hari terakhir (tanpa status palsu).
function WeeklySummaryPanel({ attendance }) {
 const week = last7Days(attendance);
 const totals = week.reduce((acc, d) => ({
 hadir: acc.hadir + d.hadir, terlambat: acc.terlambat + d.terlambat,
 izin: acc.izin + d.izin, sakit: acc.sakit + d.sakit, alpa: acc.alpa + d.alpa,
 }), { hadir: 0, terlambat: 0, izin: 0, sakit: 0, alpa: 0 });
 return <section className="panel"><Header title="Ringkasan 7 Hari Terakhir"/><div className="offline-status-box"><div className="weekly-summary"><span><i className="dot blue"/>Hadir <strong>{totals.hadir}</strong></span><span><i className="dot gray"/>Terlambat <strong>{totals.terlambat}</strong></span><span><i className="dot brand"/>Izin <strong>{totals.izin}</strong></span><span><i className="dot violet"/>Sakit <strong>{totals.sakit}</strong></span><span><i className="dot red"/>Alpa <strong>{totals.alpa}</strong></span></div></div></section>;
}

function AdminGlobalTools({ students, devices, onNavigate, apiOk, dbOk }) {
 const [query, setQuery] = useState("");
 const results = query.trim() ? [
 ...students.map((x) => ({ type: "Siswa", title: x.name, detail: `${x.nis} ${x.className}`, page: "students" })),
 ...devices.map((x) => ({ type: "Perangkat", title: x.name, detail: `${x.location} ${x.status}`, page: "devices" })),
 ].filter((x) => `${x.title} ${x.detail}`.toLowerCase().includes(query.toLowerCase())).slice(0, 5) : [];
 const online = devices.filter((x) => x.status === "online").length;
 // Status ditampilkan berdasarkan pemeriksaan nyata, bukan teks statis.
 const apiLabel = apiOk === null ? "CEK..." : apiOk ? "ONLINE" : "OFFLINE";
 return <section className="admin-tools-grid"><div className="panel global-search-panel"><div className="tools-head"><div><span className="eyebrow">PENCARIAN</span><h3>Cari di PresenTech</h3><p>Siswa, NIS, kelas, atau perangkat.</p></div><SearchCheck size={20}/></div><div className="search global-search"><Search size={17}/><input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Cari siswa, NIS, perangkat..." /></div>{results.length > 0 && <div className="global-results">{results.map((r, i) => <button key={`${r.title}-${i}`} onClick={() => { onNavigate(r.page); setQuery(""); }}><strong>{r.title}</strong><span>{r.type} {r.detail}</span></button>)}</div>}</div><div className="panel quick-panel"><div className="tools-head"><div><span className="eyebrow">AKSI CEPAT</span><h3>Aksi Cepat</h3><p>Shortcut pengelolaan Admin.</p></div><Radio size={20}/></div><div className="quick-action-grid"><button onClick={() => onNavigate("students")}><Plus size={16}/>Tambah Siswa</button><button onClick={() => onNavigate("rfid")}><CreditCard size={16}/>Daftar RFID</button><button onClick={() => onNavigate("schedule")}><CalendarDays size={16}/>Tambah Jadwal</button><button onClick={() => onNavigate("reports")}><FileText size={16}/>Laporan</button></div></div><div className="panel system-health-panel"><div className="tools-head"><div><span className="eyebrow">SISTEM</span><h3>Status Sistem</h3><p>Hasil pemeriksaan koneksi aktual.</p></div><History size={20}/></div><div className="health-grid"><span><i className={`health-dot ${apiOk === false ? "warn" : "online"}`}/>API <b>{apiLabel}</b></span><span><i className={`health-dot ${dbOk ? "online" : "warn"}`}/>Data Dashboard <b>{dbOk ? "TERSINKRON" : "BERMASALAH"}</b></span><span><i className="health-dot warn"/>MQTT <b>BELUM DIKONFIGURASI</b></span><span><i className={`health-dot ${devices.length && online === devices.length ? "online" : "warn"}`}/>ESP32 <b>{online}/{devices.length}</b></span></div></div></section>;
}

function Stat({ icon: Icon, value, label }) { return <div className="stat-card"><div className="stat-icon"><Icon size={20}/></div><strong>{value}</strong><span>{label}</span></div>; }
function Header({ title, action, onClick }) { return <div className="panel-header"><div><h3>{title}</h3></div>{action && <button className="text-button" onClick={onClick}>{action}</button>}</div>; }


function Pagination({ page, totalPages, onChange }) {
 if (totalPages <= 1) return null;
 const pages = [];
 const start = Math.max(1, Math.min(page - 2, totalPages - 4));
 const end = Math.min(totalPages, start + 4);
 for (let n = start; n <= end; n++) pages.push(n);
 return <div className="pagination-controls">
 <button className="secondary-button" disabled={page <= 1} onClick={() => onChange(page - 1)}>‹ Sebelumnya</button>
 {start > 1 && <><button className="secondary-button" onClick={() => onChange(1)}>1</button><span></span></>}
 {pages.map(n => <button key={n} className={`secondary-button ${n === page ? "active-page" : ""}`} onClick={() => onChange(n)}>{n}</button>)}
 {end < totalPages && <><span></span><button className="secondary-button" onClick={() => onChange(totalPages)}>{totalPages}</button></>}
 <button className="secondary-button" disabled={page >= totalPages} onClick={() => onChange(page + 1)}>Berikutnya ›</button>
 </div>;
}

function AttendancePage({ data, students = [], classes = [], setAttendance, setLogs }) {
 const [q,setQ]=useState(""); const [status,setStatus]=useState("Semua"); const [cls,setCls]=useState("Semua"); const [page,setPage]=useState(1); const [manualOpen,setManualOpen]=useState(false); const [exportOpen,setExportOpen]=useState(false);
 const [form,setForm]=useState({nis:"",date:localDateKey(),time:"07:00",out:"",status:"Hadir",note:""}); const [saving,setSaving]=useState(false); const perPage=50;
 const filtered=data.filter(x=>(`${x.nis} ${x.name}`.toLowerCase().includes(q.toLowerCase()))&&(status==="Semua"||x.status===status)&&(cls==="Semua"||x.className===cls)); const totalPages=Math.max(1,Math.ceil(filtered.length/perPage)); const safePage=Math.min(page,totalPages); const visible=filtered.slice((safePage-1)*perPage,safePage*perPage); useEffect(()=>setPage(1),[q,status,cls]);
 const totalHadir=filtered.filter(x=>x.status==="Hadir").length; const totalTerlambat=filtered.filter(x=>x.status==="Terlambat").length; const totalTidakHadir=filtered.filter(x=>["Alpa","Izin","Sakit","Dikoreksi"].includes(x.status)).length;
 const saveManual=async e=>{e.preventDefault(); const student=students.find(x=>String(x.nis)===String(form.nis).trim()); if(!student?.id){toast.error("NIS tidak ditemukan di database.");return;} setSaving(true); try{const r=await import("../api/attendanceApi").then(m=>m.createAttendance({nis:String(form.nis).trim(),attendance_date:form.date,attendance_time:`${form.time}:00`,check_out_time:form.out?`${form.out}:00`:undefined,status:form.status,notes:form.note||undefined})); const x=r?.attendance||r?.data?.attendance||r?.data||r; const row={id:x.id,nis:x.student?.nis||student.nis,name:x.student?.name||student.name,className:x.student?.school_class?.name||student.className||"Kelas belum ditentukan",date:String(x.attendance_date||form.date).slice(0,10),time:String(x.attendance_time||`${form.time}:00`).slice(0,5),checkOut:x.check_out_time?String(x.check_out_time).slice(0,5):"-",earlyLeave:Boolean(x.early_leave),notes:x.notes||"",status:x.status||form.status}; setAttendance(old=>{const i=old.findIndex(r=>String(r.id)===String(row.id)); if(i>=0){const next=[...old]; next[i]=row; return next;} return [row,...old];}); setLogs(old=>[{id:Date.now(),time:new Date().toLocaleTimeString("id-ID"),message:`Absensi manual ${student.name}`,detail:`${student.nis} ${form.date} ${form.time}`,type:"system"},...old].slice(0,200)); setForm({nis:"",date:localDateKey(),time:"07:00",out:"",status:"Hadir",note:""}); setManualOpen(false);}catch(error){toast.error(error?.response?.data?.message||"Absensi manual gagal disimpan.");}finally{setSaving(false)}};
 return <div className="page-stack"><PageHead title="Data Absensi" description="Kelola dan export riwayat kehadiran siswa." action={<div className="head-actions-inline"><button className="secondary-button" onClick={()=>setExportOpen(true)}><Download size={15}/>Export</button><button className="secondary-button" onClick={()=>setManualOpen(v=>!v)}><Plus size={16}/>Absensi Manual</button></div>}/><AttendanceExportModal open={exportOpen} onClose={()=>setExportOpen(false)} classes={classes}/>{manualOpen&&<section className="panel"><Header title="Tambah Absensi Manual"/><form className="schedule-form" onSubmit={saveManual}><label>NIS<input value={form.nis} onChange={e=>setForm({...form,nis:e.target.value})} placeholder="Masukkan NIS" required/></label><label>Tanggal<input type="date" value={form.date} onChange={e=>setForm({...form,date:e.target.value})} required/></label><label>Waktu<input type="time" value={form.time} onChange={e=>setForm({...form,time:e.target.value})} required/></label><label>Jam Pulang<input type="time" value={form.out} onChange={e=>setForm({...form,out:e.target.value})}/></label><label>Status<select value={form.status} onChange={e=>setForm({...form,status:e.target.value})}><option>Hadir</option><option>Terlambat</option><option>Izin</option><option>Sakit</option><option>Alpa</option></select></label><label>Catatan<input value={form.note} onChange={e=>setForm({...form,note:e.target.value})} placeholder="Mis. surat dokter / izin pulang"/></label><button className="primary-button" type="submit" disabled={saving}>{saving?"Menyimpan...":"Simpan Absensi"}</button></form></section>}<div className="attendance-overview"><div className="attendance-overview-card hadir"><div className="attendance-overview-icon"><CheckCircle2 size={20}/></div><div className="attendance-overview-text"><strong>{totalHadir}</strong><span>Hadir</span></div></div><div className="attendance-overview-card terlambat"><div className="attendance-overview-icon"><Clock3 size={20}/></div><div className="attendance-overview-text"><strong>{totalTerlambat}</strong><span>Terlambat</span></div></div><div className="attendance-overview-card tidak-hadir"><div className="attendance-overview-icon"><UserX size={20}/></div><div className="attendance-overview-text"><strong>{totalTidakHadir}</strong><span>Tidak Hadir</span></div></div></div><div className="toolbar"><div className="search"><Search size={17}/><input placeholder="Cari NIS atau nama..." value={q} onChange={e=>setQ(e.target.value)}/></div><select value={cls} onChange={e=>setCls(e.target.value)}><option>Semua</option>{[...new Set(data.map(x=>x.className))].map(x=><option key={x}>{x}</option>)}</select><select value={status} onChange={e=>setStatus(e.target.value)}><option>Semua</option><option>Hadir</option><option>Terlambat</option><option>Izin</option><option>Sakit</option><option>Alpa</option><option>Dikoreksi</option></select></div><section className="panel"><div className="log-summary"><span>Menampilkan {filtered.length?(safePage-1)*perPage+1:0}–{Math.min(safePage*perPage,filtered.length)} dari {filtered.length} data</span><span>{perPage} data per halaman</span></div><AttendanceTable data={visible}/><Pagination page={safePage} totalPages={totalPages} onChange={setPage}/></section></div>;
}
function AttendanceTable({data}) { return <div className="table-wrap attendance-table"><table><thead><tr><th>NIS</th><th>Nama</th><th>Kelas</th><th>Tanggal</th><th>Jam Masuk</th><th>Jam Pulang</th><th>Status</th><th>Keterangan</th></tr></thead><tbody>{data.length ? data.map(x=><tr key={x.id}><td>{x.nis}</td><td><strong>{x.name}</strong></td><td>{x.className}</td><td>{x.date}</td><td>{x.time}</td><td>{x.checkOut||"-"}{x.earlyLeave&&<small className="log-type"> izin</small>}</td><td><span className={`status ${statusClass(x.status)}`}>{x.status}</span></td><td><small>{x.notes||"-"}</small></td></tr>) : <tr><td colSpan="8" className="empty">Data tidak ditemukan.</td></tr>}</tbody></table></div>; }

const noAbsenValue = (x) => {
 const value = Number(x?.no_absen);
 return Number.isFinite(value) ? value : Number.MAX_SAFE_INTEGER;
};

function CrudPage({ title, description, type, data, classes = [], onImported, onAdd, onEdit, onDelete, onToggleAdmin, onCreateAccount, onResetPassword, onBulkDelete }) {
 const [q, setQ] = useState("");
 const [status, setStatus] = useState("Semua");
 const [classFilter, setClassFilter] = useState("Semua");
 const [page, setPage] = useState(1);
 const [importMessage, setImportMessage] = useState("");
 const [importing, setImporting] = useState(false);
 const [selected, setSelected] = useState([]);
 const [perPage, setPerPage] = useState(20);
 const [exportOpen, setExportOpen] = useState(false);
 const isStudent = type === "student";
 const classField = (x) => x.className || "Kelas belum ditentukan";
 const classOptions = isStudent
 ? [...new Set(data.map(classField).concat(classes.map((c) => c.name).filter(Boolean)))].sort((a, b) => a.localeCompare(b, "id"))
 : [];
 const classCounts = data.reduce((acc, x) => { const name = classField(x); acc[name] = (acc[name] || 0) + 1; return acc; }, {});
 const activeClass = classFilter === "Semua" || classOptions.includes(classFilter) ? classFilter : "Semua";
 const filtered = data.filter((x) => JSON.stringify(x).toLowerCase().includes(q.toLowerCase()) && (type !== "student" || status === "Semua" || (x.status || "Aktif") === status) && (type !== "student" || activeClass === "Semua" || classField(x) === activeClass));
 const totalPages = Math.max(1, Math.ceil(filtered.length / perPage));
 const safePage = Math.min(page, totalPages);
 const sorted = isStudent
 ? [...filtered].sort((a, b) => classField(a).localeCompare(classField(b), "id") || noAbsenValue(a) - noAbsenValue(b) || String(a.name || "").localeCompare(String(b.name || ""), "id"))
 : filtered;
 const visible = sorted.slice((safePage - 1) * perPage, safePage * perPage);
 const columns = type === "student" ? ["nis", "name", "no_absen", "className", "major", "uid", "account", "status"] : type === "teacher" ? ["nip", "name", "subject", "account", "status"] : ["name", "students", "teacher"];
 const labels = { nis: "NIS", nip: "NIP", name: "Nama", no_absen: "No Absen", className: "Kelas", major: "Jurusan", uid: "RFID UID", subject: "Mata Pelajaran", students: "Siswa", teacher: "Wali Kelas", status: "Status", account: "Akun Login" };

 // Data untuk modal export: nilai yang tampil di tabel dipakai apa adanya
 // supaya file sama dengan yang dilihat pengguna (Status kosong -> Aktif,
 // akun ditulis Sudah/Belum, kelas kosong diberi keterangan).
 const exportRows = sorted.map((x) => {
 if (type === "student" || type === "teacher") {
 return {
 ...x,
 status: x.status || "Aktif",
 account: x.hasAccount ? "Sudah" : "Belum",
 className: x.className || "Kelas belum ditentukan",
 };
 }
 return x;
 });
 const exportColumns = columns.map((c) => ({ key: c, label: labels[c] }));
 const exportFilters = type === "student"
 ? [
 { id: "status", label: "Status", value: status, options: ["Semua", "Aktif", "Nonaktif", "Lulus", "Pindah"].map((v) => ({ value: v, label: v === "Semua" ? "Semua status" : v })), onChange: setStatus },
 { id: "class", label: "Kelas", value: activeClass, options: [{ value: "Semua", label: "Semua kelas" }, ...classOptions.map((name) => ({ value: name, label: `${name} (${classCounts[name] ?? 0})` }))], onChange: setClassFilter },
 ]
 : [];

 // Import CSV siswa: NIS,Nama,Kelas,Jurusan,RFID UID,Status,No Absen 
 // setiap baris valid disimpan ke database lewat API.
 const importStudents = (event) => {
 const file = event.target.files?.[0];
 if (!file || type !== "student") return;
 const reader = new FileReader();
 reader.onerror = () => {
 setImportMessage("Gagal membaca file CSV.");
 setImporting(false);
 };
 reader.onload = async () => {
 const rows = parseCsvToRows(String(reader.result)).slice(1);
 const validRows = rows.filter((r) => r[0] && r[1]);
 if (!validRows.length) {
 setImportMessage("Tidak ada baris valid di file CSV. Pastikan format: NIS,Nama,Kelas.");
 return;
 }
 setImporting(true);
 setImportMessage(`Mengimpor ${validRows.length} data siswa...`);
 const studentApi = await import("../api/studentApi");
 const rfidApi = await import("../api/rfidApi");
 let success = 0;
 let failed = 0;
 for (const r of validRows) {
 try {
 const cls = classes.find((c) => c.name.toLowerCase() === String(r[2] || "").trim().toLowerCase());
 const saved = await studentApi.createStudent({
 nis: r[0],
 name: r[1],
 class_id: cls?.id || null,
 no_absen: String(r[6] || "").trim() ? Number(String(r[6]).trim()) : null,
 status: r[5] || "Aktif",
 });
 const sid = saved?.data?.id || saved?.id;
 const uid = String(r[4] || "").trim();
 // Kartu RFID diimpor setelah siswa tersimpan; kegagalan kartu
 // tidak membatalkan data siswa yang sudah masuk.
 if (sid && uid) {
 try {
 await rfidApi.createRfidCard({ student_id: sid, uid, label: "Import CSV", status: "Aktif" });
 } catch {
 failed++;
 }
 }
 success++;
 } catch {
 failed++;
 }
 }
 setImporting(false);
 setImportMessage(`Import selesai: ${success} siswa tersimpan${failed ? `, ${failed} gagal (NIS duplikat, kartu RFID gagal, atau data tidak valid)` : ""}.`);
 if (success > 0 && onImported) {
 try { await onImported(); } catch { /* muat ulang gagal; data tetap tersimpan di server */ }
 }
 };
 reader.readAsText(file);
 event.target.value = "";
 };
 useEffect(() => setPage(1), [q, status, type, classFilter]);
 const [selectMode, setSelectMode] = useState(false);
 const allSelected = visible.length > 0 && visible.every((x) => selected.includes(x.id));
 const toggleAll = () => setSelected(allSelected
 ? selected.filter((id) => !visible.some((x) => x.id === id))
 : [...new Set([...selected, ...visible.map((x) => x.id)])]);
 const toggleOne = (id) => setSelected((current) => (current.includes(id) ? current.filter((value) => value !== id) : [...current, id]));

 return (
 <div className="page-stack">
 <PageHead
 title={title}
 description={description}
 action={
 <div className="head-actions-inline">
 <button type="button" className={`secondary-button ${selectMode ? "active" : ""}`} onClick={() => { setSelectMode((value) => !value); setSelected([]); }}><Check size={15} />{selectMode ? "Batal Pilih" : "Pilih"}</button>
 <button className="secondary-button" onClick={() => setExportOpen(true)}><Download size={16} />Export</button>
 {type === "student" && (
 <label className={`secondary-button file-action ${importing ? "disabled" : ""}`}>
 {importing ? <RefreshCw size={16} /> : <Upload size={16} />}
 {importing ? "Mengimpor..." : "Import CSV"}
 <input type="file" accept=".csv,text/csv" disabled={importing} onChange={importStudents} />
 </label>
 )}
 <button className="primary-button" onClick={onAdd}><Plus size={17} />Tambah</button>
 </div>
 }
 />

 <div className="toolbar">
 <div className="search">
 <Search size={17} />
 <input
 placeholder={`Cari ${type === "student" ? "NIS, nama, atau kelas" : "data"}...`}
 value={q}
 onChange={(e) => setQ(e.target.value)}
 />
 </div>
 {type === "student" && (
 <select value={status} onChange={(e) => setStatus(e.target.value)}>
 <option>Semua</option>
 <option>Aktif</option>
 <option>Nonaktif</option>
 <option>Lulus</option>
 <option>Pindah</option>
 </select>
 )}
 {isStudent && (
 <select className="class-filter" value={activeClass} onChange={(e) => setClassFilter(e.target.value)} aria-label="Pilih kelas">
 <option value="Semua">Semua Kelas</option>
 {classOptions.map((name) => (
 <option key={name} value={name}>{`${name} (${classCounts[name] ?? 0})`}</option>
 ))}
 </select>
 )}
 <select value={perPage} onChange={(e) => { setPerPage(Number(e.target.value)); setPage(1); }} aria-label="Baris per halaman">
 <option value={20}>20 / halaman</option>
 <option value={50}>50 / halaman</option>
 <option value={100}>100 / halaman</option>
 </select>
 </div>

 {importMessage && <div className="save-toast"><Check size={16} />{importMessage}</div>}

 <section className="panel">
 {selectMode && selected.length > 0 && (
 <div className="bulk-bar">
 <span className="bulk-count">{selected.length} data dipilih</span>
 {onBulkDelete && (
 <button
 type="button"
 className="danger"
 onClick={() => {
 onBulkDelete(selected);
 setSelected([]);
 }}
 >
 <Trash2 size={14} />
 Hapus terpilih
 </button>
 )}
 <button type="button" onClick={() => setSelected([])}>Batal</button>
 </div>
 )}

 <div className="log-summary">
 <span>
 Menampilkan {filtered.length ? (safePage - 1) * perPage + 1 : 0}
 {"\u2013"}
 {Math.min(safePage * perPage, filtered.length)} dari {filtered.length} data
 </span>
 <span>{perPage} data per halaman</span>
 </div>

 <div className="table-wrap">
 <table className="data-table">
 <thead>
 <tr>
 {selectMode && (
 <th className="row-check">
 <input type="checkbox" checked={allSelected} onChange={toggleAll} aria-label="Pilih semua data" />
 </th>
 )}
 {columns.map((c) => <th key={c}>{labels[c]}</th>)}
 <th style={{ width: 56 }} aria-label="Aksi" />
 </tr>
 </thead>
 <tbody>
 {visible.length ? visible.map((x, index) => (
 <Fragment key={x.id}>
 {isStudent && (index === 0 || classField(visible[index - 1]) !== classField(x)) && (
 <tr className="class-group-row">
 <td colSpan={columns.length + (selectMode ? 2 : 1)}>
 <strong>{classField(x)}</strong>
 </td>
 </tr>
 )}
 <tr className={selectMode && selected.includes(x.id) ? "selected" : ""}>
 {selectMode && (
 <td className="row-check">
 <input
 type="checkbox"
 checked={selected.includes(x.id)}
 onChange={() => toggleOne(x.id)}
 aria-label={`Pilih ${x.name || x.id}`}
 />
 </td>
 )}
 {columns.map((c) => (
 <td key={c} title={c === "name" ? x[c] : undefined}>
 {c === "name" ? (
 <strong>
 {x[c]}
 {type === "teacher" && x.role === "admin" && <span className="admin-tag">Admin</span>}
 </strong>
 ) : c === "status" ? (
 <span className={`status-badge ${x[c] === "Aktif" ? "online" : "offline"}`}>
 <i />
 {x[c] || "Aktif"}
 </span>
 ) : c === "account" ? (
 <span className={`status-badge ${x.hasAccount ? "online" : "offline"}`}>
 <i />
 {x.hasAccount ? "Sudah" : "Belum"}
 </span>
 ) : (
 x[c] || (c === "className" ? "Kelas belum ditentukan" : "")
 )}
 </td>
 ))}
 <td>
 <RowMenu
 items={[
 { label: "Edit", icon: <Edit3 size={15} />, onClick: () => onEdit({ ...x, __existing: true }) },
 ...((type === "student" || type === "teacher") && !x.hasAccount
 ? [{ label: "Buat Akun Login", icon: <UserRound size={15} />, onClick: () => onCreateAccount(type, x) }]
 : []),
 ...((type === "student" || type === "teacher") && x.hasAccount && onResetPassword
 ? [{ label: "Reset Password Akun", icon: <KeyRound size={15} />, onClick: () => onResetPassword(type, x) }]
 : []),
 ...(type === "teacher"
 ? [{
 label: x.role === "admin" ? "Cabut Admin" : "Jadikan Admin",
 icon: x.role === "admin" ? <ShieldOff size={15} /> : <ShieldCheck size={15} />,
 onClick: () => onToggleAdmin(x),
 }]
 : []),
 { label: "Hapus", icon: <Trash2 size={15} />, danger: true, onClick: () => onDelete(type, x.id) },
 ]}
 />
 </td>
 </tr>
 </Fragment>
 )) : (
 <tr><td colSpan={columns.length + (selectMode ? 2 : 1)} className="empty">Data tidak ditemukan.</td></tr>
 )}
 </tbody>
 </table>
 </div>

 <Pagination page={safePage} totalPages={totalPages} onChange={setPage} />
 </section>

 <ExportDataModal
 open={exportOpen}
 onClose={() => setExportOpen(false)}
 title={`Export ${title}`}
 subtitle={description}
 rows={exportRows}
 columns={exportColumns}
 filenameBase={`presentech-${type}`}
 filters={exportFilters}
 meta={(count) => [
 `${count} baris data ${title.toLowerCase()}`,
 q ? `Pencarian: "${q}"` : "Tanpa kata kunci pencarian",
 ]}
 appliedNote={q ? `Pencarian "${q}" ikut diterapkan pada file.` : ""}
 />
 </div>
 );
}

function DevicePage({ devices, onRefresh, onRestart, onRotate, onAdd, onEdit, onDelete, onBulkDelete }) {
 const [page, setPage] = useState(1);
 const [selected, setSelected] = useState([]);
 const [selectMode, setSelectMode] = useState(false);
 const perPage = 12;
 const totalPages = Math.max(1, Math.ceil(devices.length / perPage));
 const safePage = Math.min(page, totalPages);
 const visible = devices.slice((safePage - 1) * perPage, safePage * perPage);

 useEffect(() => setPage(1), [devices.length]);
 useEffect(() => setSelected((current) => current.filter((id) => devices.some((device) => device.id === id))), [devices]);

 const allSelected = visible.length > 0 && visible.every((device) => selected.includes(device.id));
 const toggleAll = () => setSelected(allSelected
 ? selected.filter((id) => !visible.some((device) => device.id === id))
 : [...new Set([...selected, ...visible.map((device) => device.id)])]);
 const toggleOne = (id) => setSelected((current) => (current.includes(id) ? current.filter((value) => value !== id) : [...current, id]));

 const statusLabel = (status) => (status === "online" ? "Online" : status === "maintenance" ? "Maintenance" : "Offline");
 const online = devices.filter((x) => x.status === "online").length;
 const offline = devices.filter((x) => x.status === "offline").length;
 const maintenance = devices.filter((x) => x.status === "maintenance").length;

 // Pratinjau layar: teks yang dikirim server ke alat (nama sudah dipendekkan
 // dan aman untuk layar 16 karakter) sehingga Admin bisa mengecek tampilan
 // tanpa harus mem-flash firmware.
 const [preview, setPreview] = useState({ open: false, device: null, data: null, loading: false, error: "" });
 const closePreview = () => setPreview((current) => ({ ...current, open: false }));
 const openPreview = async (device) => {
  setPreview({ open: true, device, data: null, loading: true, error: "" });
  try {
   const result = await import("../api/deviceApi").then((m) => m.getDevicePreview(device.id));
   setPreview((current) => (String(current.device?.id) === String(device.id) ? { ...current, data: result, loading: false } : current));
  } catch (error) {
   setPreview((current) => (String(current.device?.id) === String(device.id) ? { ...current, loading: false, error: error?.response?.data?.message || "Pratinjau gagal dimuat." } : current));
  }
 };
 const stateLabel = (state) => (state === "pulang" ? "Siap pulang" : state === "terlambat" ? "Terlambat" : "Siap masuk");

 // Ganti WiFi: Admin mengisi SSID/password, server mengirimkannya lewat
 // heartbeat, lalu alat menyimpan ke NVS dan restart sendiri.
 const [wifi, setWifi] = useState({ open: false, device: null, ssid: "", password: "", saving: false, error: "" });
 const closeWifi = () => setWifi((current) => ({ ...current, open: false, saving: false, error: "" }));
 const openWifi = (device) => setWifi({ open: true, device, ssid: device.wifi_ssid || "", password: "", saving: false, error: "" });
 const submitWifi = async (event) => {
  event.preventDefault();
  const ssid = wifi.ssid.trim();
  if (!ssid) {
   setWifi((current) => ({ ...current, error: "Nama WiFi (SSID) wajib diisi." }));
   return;
  }
  setWifi((current) => ({ ...current, saving: true, error: "" }));
  try {
   await import("../api/deviceApi").then((m) => m.setDeviceWifi(wifi.device.id, { ssid, password: wifi.password }));
   toast.success(`Perintah ganti WiFi dikirim ke ${wifi.device.name}. Alat akan restart setelah menerima konfigurasi.`);
   setWifi((current) => ({ ...current, open: false, saving: false }));
   onRefresh?.();
  } catch (error) {
   setWifi((current) => ({ ...current, saving: false, error: error?.response?.data?.message || "Perintah ganti WiFi gagal dikirim." }));
  }
 };

 return (
 <div className="page-stack">
 <PageHead
 title="Perangkat IoT"
 description="Kelola ESP32, status koneksi, dan ringkasan perangkat."
 action={
 <div className="head-actions-inline">
 <button className="secondary-button" onClick={onRefresh}><RefreshCw size={17} />Refresh</button>
 <button type="button" className={`secondary-button ${selectMode ? "active" : ""}`} onClick={() => { setSelectMode((value) => !value); setSelected([]); }}><Check size={15} />{selectMode ? "Batal Pilih" : "Pilih"}</button>
 <button className="primary-button" onClick={onAdd}><Plus size={17} />Tambah</button>
 </div>
 }
 />
 <div className="stat-grid"><Stat icon={Cpu} value={devices.length} label="Total Perangkat" /><Stat icon={Wifi} value={online} label="Online" /><Stat icon={WifiOff} value={offline} label="Offline" /><Stat icon={Settings} value={maintenance} label="Maintenance" /></div>

 <section className="panel">
 {selectMode && selected.length > 0 && (
 <div className="bulk-bar">
 <span className="bulk-count">{selected.length} perangkat dipilih</span>
 <button
 type="button"
 onClick={() => {
 devices.filter((device) => selected.includes(device.id)).forEach((device) => onRestart(device));
 }}
 >
 <RefreshCw size={14} />
 Restart
 </button>
 {onBulkDelete && (
 <button
 type="button"
 className="danger"
 onClick={() => {
 onBulkDelete(selected);
 setSelected([]);
 }}
 >
 <Trash2 size={14} />
 Hapus terpilih
 </button>
 )}
 <button type="button" onClick={() => setSelected([])}>Batal</button>
 </div>
 )}

 <div className="table-wrap">
 <table className="data-table">
 <thead>
 <tr>
 {selectMode && (
 <th className="row-check">
 <input type="checkbox" checked={allSelected} onChange={toggleAll} aria-label="Pilih semua perangkat" />
 </th>
 )}
 <th>Perangkat</th>
 <th>Lokasi</th>
 <th>Koneksi</th>
 <th>Status</th>
 <th>Terakhir Terlihat</th>
 <th style={{ width: 56 }} aria-label="Aksi" />
 </tr>
 </thead>
 <tbody>
 {visible.length ? visible.map((device) => (
 <tr key={device.id} className={selectMode && selected.includes(device.id) ? "selected" : ""}>
 {selectMode && (
 <td className="row-check">
 <input
 type="checkbox"
 checked={selected.includes(device.id)}
 onChange={() => toggleOne(device.id)}
 aria-label={`Pilih ${device.name}`}
 />
 </td>
 )}
 <td>
 <strong>{device.name}</strong>
 <small className="cell-sub">{device.device_id || "ID belum diset"}</small>
 </td>
 <td>{device.location || "-"}</td>
 <td>{device.connection}</td>
 <td>
 <span className={`status-badge ${device.status}`}>
 <i />
 {statusLabel(device.status)}
 </span>
 </td>
 <td>
 {device.last_seen_at ? (
 <>
 <strong className="cell-relative">{relativeTime(device.last_seen_at)}</strong>
 <small className="cell-sub">{String(device.last_seen_at).slice(0, 16).replace("T", " ")}</small>
 </>
 ) : "Belum pernah"}
 </td>
 <td>
 <RowMenu
 items={[
 { label: "Pratinjau Layar", icon: <Eye size={15} />, onClick: () => openPreview(device) },
 { label: "Edit", icon: <Edit3 size={15} />, onClick: () => onEdit(device) },
 { label: "Restart", icon: <RefreshCw size={15} />, onClick: () => onRestart(device) },
 { label: "Ganti WiFi", icon: <Wifi size={15} />, onClick: () => openWifi(device) },
 { label: "Rotate Key", icon: <RotateCcw size={15} />, onClick: () => onRotate(device) },
 { label: "Hapus", icon: <Trash2 size={15} />, danger: true, onClick: () => onDelete("device", device.id) },
 ]}
 />
 </td>
 </tr>
 )) : (
 <tr><td colSpan={selectMode ? 7 : 6} className="empty">Belum ada perangkat terdaftar.</td></tr>
 )}
 </tbody>
 </table>
 </div>

 <Pagination page={safePage} totalPages={totalPages} onChange={setPage} />
 </section>

 {wifi.open && (
 <div className="modal-backdrop" onClick={() => !wifi.saving && closeWifi()} role="presentation">
 <form className="modal" onClick={(event) => event.stopPropagation()} onSubmit={submitWifi} role="dialog" aria-modal="true" aria-label="Ganti WiFi perangkat">
 <div className="modal-head">
 <div>
 <h2>Ganti WiFi</h2>
 <p>{wifi.device?.name} • {wifi.device?.device_id || "ID belum diset"}</p>
 </div>
 <button type="button" className="modal-close" onClick={closeWifi} disabled={wifi.saving} aria-label="Tutup"><X size={18} /></button>
 </div>

 <label className="setting"><span>Nama WiFi (SSID)</span><input value={wifi.ssid} onChange={(event) => setWifi((current) => ({ ...current, ssid: event.target.value }))} maxLength={64} required placeholder="Contoh: ACER NITRO 5" autoFocus /></label>
 <label className="setting"><span>Password WiFi</span><input type="password" value={wifi.password} onChange={(event) => setWifi((current) => ({ ...current, password: event.target.value }))} maxLength={63} placeholder="Kosongkan untuk WiFi tanpa password" /></label>

 <p className="export-note">Alat harus <b>online</b> dan terhubung ke server. Perintah diterapkan pada heartbeat berikutnya (±3 detik), lalu alat <b>restart otomatis</b>. Jika WiFi baru salah, tahan tombol <b>BOOT 3 detik</b> untuk membuka portal setup.</p>
 {wifi.error && <p className="field-error">{wifi.error}</p>}

 <div className="modal-actions">
 <button type="button" className="secondary-button" onClick={closeWifi} disabled={wifi.saving}>Batal</button>
 <button className="primary-button" type="submit" disabled={wifi.saving}>{wifi.saving ? "Mengirim..." : <><Wifi size={16} />Kirim</>}</button>
 </div>
 </form>
 </div>
 )}

 {preview.open && (
 <div className="modal-backdrop" onClick={closePreview} role="presentation">
 <div className="modal device-preview-modal" onClick={(event) => event.stopPropagation()} role="dialog" aria-modal="true" aria-label="Pratinjau layar perangkat">
 <div className="modal-head">
 <div>
 <h2>Pratinjau Layar</h2>
 <p>{preview.device?.name} • {preview.device?.device_id || "ID belum diset"}</p>
 </div>
 <button type="button" className="modal-close" onClick={closePreview} aria-label="Tutup"><X size={18} /></button>
 </div>

 {preview.loading && <p className="export-note">Memuat pratinjau layar…</p>}
 {preview.error && <p className="export-note">{preview.error}</p>}

 {preview.data && (
 <>
 <div className="device-screen" style={{ "--screen-accent": preview.data.display.theme_color }}>
 <div className="device-screen-bar">
 <span className="device-screen-brand">{preview.data.display.school_label}</span>
 <span className="device-screen-clock">{preview.data.display.clock}</span>
 </div>
 <div className="device-screen-body">
 <strong>{preview.data.display.device_name}</strong>
 <small>{preview.data.display.day_label} • {preview.data.display.greeting}</small>
 </div>
 <div className="device-screen-foot">
 <span className="device-screen-state">{stateLabel(preview.data.display.state)}</span>
 <span className="device-screen-hint">Tempel kartu RFID</span>
 </div>
 </div>

 <dl className="device-preview-facts">
 <div><dt>Nama di layar</dt><dd><b>{preview.data.display.device_name_short}</b><em>{preview.data.display.device_name_short.length} dari maks. 16 karakter</em></dd></div>
 <div><dt>Versi inisial</dt><dd><b>{preview.data.display.device_name_initials}</b><em>untuk layar sangat sempit</em></dd></div>
 <div><dt>Label sekolah</dt><dd><b>{preview.data.display.school_label}</b><em>baris atas layar</em></dd></div>
 <div><dt>Warna tema</dt><dd><b><i className="device-preview-swatch" style={{ background: preview.data.display.theme_color }} />{preview.data.display.theme_color}</b><em>aksen garis & status</em></dd></div>
 <div><dt>Jam absensi</dt><dd><b>{preview.data.window.entry_time || "-"} / {preview.data.window.late_time || "-"} / {preview.data.window.home_time}</b><em>masuk / terlambat / pulang</em></dd></div>
 </dl>

 <p className="export-note">Alat firmware v1.6.0 tetap memakai nama lama. Teks di atas otomatis dipakai setelah firmware yang membaca field <code>display</code> dipasang.</p>
 </>
 )}

 <div className="modal-actions">
 <button type="button" className="secondary-button" onClick={closePreview}>Tutup</button>
 <button type="button" className="primary-button" onClick={() => { closePreview(); onEdit(preview.device); }}>Ubah Tampilan</button>
 </div>
 </div>
 </div>
 )}
 </div>
 );
}

function ClassMonitor({ students, attendance }) {
 const groups = [...new Set(students.map((x) => x.className))];

 return (
 <section className="panel">
 <Header title="Monitor Kelas" />
 <div className="class-monitor-list">
 {groups.map((className) => {
 const total = students.filter((s) => s.className === className).length;
 const present = attendance.filter(
 (a) => a.className === className && a.status === "Hadir"
 ).length;
 const late = attendance.filter(
 (a) => a.className === className && a.status === "Terlambat"
 ).length;
 const rate = attendancePercentage(present, late, total);

 return (
 <div className="class-monitor" key={className}>
 <div className="class-monitor-head">
 <strong>{className}</strong>
 <span>{rate}%</span>
 </div>
 <div className="progress">
 <i style={{ width: `${rate}%` }} />
 </div>
 <div className="class-monitor-meta">
 <span>Hadir {present}</span>
 <span>Terlambat {late}</span>
 <span>Total {total}</span>
 </div>
 </div>
 );
 })}
 </div>
 </section>
 );
}

function AttendanceTimeline({ attendance }) {
 return (
 <section className="panel">
 <Header title="Riwayat Absensi" />
 <div className="timeline">
 {attendance.slice(0, 6).map((item) => (
 <div className="timeline-item" key={item.id}>
 <div className="timeline-dot" />
 <div>
 <strong>{item.name}</strong>
 <small>
 {item.time} {item.className}
 </small>
 </div>
 <span className={`status ${statusClass(item.status)}`}>
 {item.status}
 </span>
 </div>
 ))}
 </div>
 </section>
 );
}

function RfidPage({ cards, students, classes = [], devices, setCards, setLogs, setConfirm }) {
 const [query, setQuery] = useState("");
 const [classFilter, setClassFilter] = useState("Semua");
 const [statusFilter, setStatusFilter] = useState("Semua");
 const [importing, setImporting] = useState(false);
 const [importMessage, setImportMessage] = useState("");
 const [mode, setMode] = useState("device");
 const [form, setForm] = useState({ uid: "", studentId: "" });
 const [deviceState, setDeviceState] = useState("ready");
 const [scanDeviceId, setScanDeviceId] = useState("");
 const [scanState, setScanState] = useState("idle");
 const [editingCard, setEditingCard] = useState(null);
 const [editForm, setEditForm] = useState({ uid: "", studentId: "", status: "Aktif" });
 const [editSaving, setEditSaving] = useState(false);
 const [exportOpen, setExportOpen] = useState(false);
 const onlineDevices = devices.filter((device) => device.status === "online");
 const effectiveDeviceId = scanDeviceId && onlineDevices.some((device) => String(device.id) === String(scanDeviceId))
 ? scanDeviceId
 : (onlineDevices[0] ? String(onlineDevices[0].id) : "");
 const selectedDevice = devices.find((device) => String(device.id) === String(effectiveDeviceId));
 const filtered = cards.filter((card) => `${card.uid} ${card.student} ${card.nis} ${card.className || ""}`.toLowerCase().includes(query.toLowerCase())
  && (classFilter === "Semua" || card.className === classFilter)
  && (statusFilter === "Semua" || card.status === statusFilter));
 const [page, setPage] = useState(1);
 const perPage = 20;
 const totalPages = Math.max(1, Math.ceil(filtered.length / perPage));
 const safePage = Math.min(page, totalPages);
 const visibleCards = filtered.slice((safePage - 1) * perPage, safePage * perPage);
 useEffect(() => setPage(1), [query]);
 const selectedStudent = students.find((s) => String(s.id) === String(form.studentId));
 const register = async (event) => {
 event.preventDefault();
 const uid = form.uid.trim().toUpperCase();
 if (!uid) {
 toast.error("UID kartu belum ada. Baca kartu dari alat atau isi di mode Manual.");
 return;
 }
 if (!selectedStudent?.id) {
 toast.error("Pilih nama siswa terlebih dahulu.");
 return;
 }
 if (cards.some((card) => String(card.uid).toUpperCase() === uid)) {
 setConfirm({ title: "RFID Sudah Terdaftar", message: `UID ${uid} sudah terdaftar.`, confirmLabel: "Mengerti", tone: "danger", onConfirm: () => {} });
 return;
 }
 try {
 const response = await import("../api/rfidApi").then(m=>m.createRfidCard({ student_id: selectedStudent.id, uid, label: mode === "device" ? "Alat RFID" : "Manual", status: "Aktif" }));
 const x=response?.data || response;
 const card={id:x.id,uid:x.uid,student:x.student?.name || selectedStudent.name,nis:x.student?.nis || selectedStudent.nis,className:x.student?.school_class?.name || selectedStudent.className || "",student_id:x.student_id,status:x.status,source:mode === "device" ? "Alat RFID" : "Manual"};
 setCards(old=>[card,...old]);
 } catch(error) {
 console.error(error);
 toast.error(error?.response?.data?.message || "Kartu RFID gagal disimpan ke database.");
 return;
 }
 setLogs(old=>[{id:Date.now(),time:new Date().toLocaleTimeString("id-ID"),message:`Kartu RFID ${uid} didaftarkan untuk ${selectedStudent.name}`,type:"rfid"},...old].slice(0,200));
 setForm({uid:"",studentId:""}); setDeviceState("ready"); setScanState("idle");
 toast.success(`Kartu ${uid} terdaftar untuk ${selectedStudent.name}.`);
 if (mode === "device" && effectiveDeviceId) {
 try {
 await import("../api/deviceApi").then((m) => m.cancelDeviceScan(effectiveDeviceId));
 } catch {
 // Sesi akan dibersihkan otomatis oleh server.
 }
 }
 };
 const startScan = () => {
 if (!effectiveDeviceId) {
 toast.error("Tidak ada perangkat online. Nyalakan alat terlebih dahulu.");
 return;
 }
 setConfirm({
 title: "Aktifkan Mode Baca UID?",
 message: `Perangkat ${selectedDevice?.name || "ESP32"} akan masuk mode baca UID.\nTempelkan kartu setelah mode aktif.`,
 confirmLabel: "Oke",
 cancelText: "Batal",
 tone: "primary",
 onConfirm: async () => {
 try {
 await import("../api/deviceApi").then((m) => m.requestDeviceScan(effectiveDeviceId));
 } catch (error) {
 toast.error(error?.response?.data?.message || "Gagal mengaktifkan mode baca UID.");
 throw error;
 }
 setMode("device");
 setDeviceState("waiting");
 setScanState("waiting");
 toast.success("Perintah terkirim. Tunggu LCD menampilkan MODE BACA UID (maks 3 dtk), lalu tempelkan kartu.");
 },
 });
 };

 const cancelScan = async () => {
 setScanState("idle");
 setDeviceState("ready");
 if (!effectiveDeviceId) return;
 try {
 await import("../api/deviceApi").then((m) => m.cancelDeviceScan(effectiveDeviceId));
 } catch {
 // Sesi tetap berakhir otomatis di server setelah kedaluwarsa.
 }
 };

 const finishScan = async () => {
 setScanState("idle");
 setDeviceState("ready");
 setForm({ uid: "", studentId: "" });
 if (effectiveDeviceId) {
 try {
 await import("../api/deviceApi").then((m) => m.cancelDeviceScan(effectiveDeviceId));
 } catch {
 // Alat sudah kembali ke mode absensi secara otomatis.
 }
 }
 toast.success("Sesi selesai. Alat kembali ke mode absensi.");
 };

 const switchMode = (nextMode) => {
 if (scanState === "waiting") {
 cancelScan();
 }
 setMode(nextMode);
 setDeviceState("ready");
 };

 useEffect(() => {
 if (mode !== "device" || scanState !== "waiting" || !effectiveDeviceId) return;
 let cancelled = false;
 let timer = null;
 const poll = async () => {
 try {
 const status = await import("../api/deviceApi").then((m) => m.getDeviceScanStatus(effectiveDeviceId));
 if (cancelled) return;
 if (status.status === "completed") {
 cancelled = true;
 if (timer) clearInterval(timer);
 const uid = String(status.uid || "").toUpperCase();
 setScanState("idle");
 setDeviceState("detected");
 setForm((prev) => ({ ...prev, uid }));
 setConfirm({
 title: "UID Kartu Terbaca",
 message: `UID ${uid} terbaca dari ${selectedDevice?.name || "alat"}.\nGunakan UID ini untuk pendaftaran?`,
 confirmLabel: "Oke",
 cancelText: "Batal",
 tone: "primary",
 onConfirm: async () => {
 try {
 await import("../api/deviceApi").then((m) => m.cancelDeviceScan(effectiveDeviceId));
 } catch {
 // Hasil scan tetap tersimpan sampai kedaluwarsa.
 }
 setLogs((old) => [{ id: Date.now(), time: new Date().toLocaleTimeString("id-ID"), message: `UID ${uid} dibaca dari ${selectedDevice?.name || "alat"}`, type: "rfid" }, ...old].slice(0, 200));
 toast.success(`UID ${uid} siap didaftarkan.`);
 },
 onCancel: async () => {
 setForm((prev) => ({ ...prev, uid: "" }));
 setDeviceState("ready");
 setScanState("idle");
 try {
 await import("../api/deviceApi").then((m) => m.cancelDeviceScan(effectiveDeviceId));
 } catch {
 // Sesi tetap berakhir otomatis di server setelah kedaluwarsa.
 }
 },
 });
 } else if (status.status === "idle") {
 cancelled = true;
 if (timer) clearInterval(timer);
 setScanState("idle");
 setDeviceState("ready");
 toast.error("Sesi baca UID berakhir. Silakan coba lagi.");
 }
 } catch (error) {
 if (cancelled) return;
 cancelled = true;
 if (timer) clearInterval(timer);
 setScanState("idle");
 setDeviceState("ready");
 toast.error(error?.response?.data?.message || "Gagal memeriksa sesi baca UID.");
 }
 };
 poll();
 timer = setInterval(poll, 1500);
 return () => {
 cancelled = true;
 if (timer) clearInterval(timer);
 };
 }, [effectiveDeviceId, mode, scanState, selectedDevice?.name, setConfirm, setLogs]);
 const toggle = (id) => {
 const card = cards.find((x) => x.id === id);
 if (!card) return;
 const nextStatus = card.status === "Aktif" ? "Nonaktif" : "Aktif";
 setConfirm({ title: `${nextStatus === "Aktif" ? "Aktifkan" : "Nonaktifkan"} Kartu RFID?`, message: `Anda yakin ingin ${nextStatus === "Aktif" ? "mengaktifkan" : "menonaktifkan"} kartu ${card.uid} milik ${card.student}?`, confirmLabel: nextStatus === "Aktif" ? "Aktifkan" : "Nonaktifkan", tone: nextStatus === "Aktif" ? "primary" : "danger", onConfirm: async () => {
 try {
 await import("../api/rfidApi").then(m=>m.updateRfidCard(id,{status:nextStatus}));
 } catch(error) {
 toast.error(error?.response?.data?.message || "Status kartu gagal diubah.");
 return;
 }
 setCards(old=>old.map(item=>item.id===id?{...item,status:nextStatus}:item));
 setLogs(old=>[{id:Date.now(),time:new Date().toLocaleTimeString("id-ID"),message:`Kartu RFID ${card.uid} ${nextStatus.toLowerCase()}`,type:"rfid"},...old].slice(0,200));
 } });
 };
 const startEdit = (card) => {
 setEditingCard(card);
 setEditForm({ uid: card.uid || "", studentId: String(card.student_id || ""), status: card.status || "Aktif" });
 };
 const saveEdit = async (event) => {
 event.preventDefault();
 const uid = editForm.uid.trim().toUpperCase();
 if (!uid) { toast.error("UID kartu wajib diisi."); return; }
 if (!editForm.studentId) { toast.error("Pilih siswa pemilik kartu."); return; }
 if (cards.some((card) => String(card.uid).toUpperCase() === uid && card.id !== editingCard.id)) { toast.error("UID tersebut sudah dipakai kartu lain."); return; }
 setEditSaving(true);
 try {
 const response = await import("../api/rfidApi").then(m => m.updateRfidCard(editingCard.id, { uid, student_id: Number(editForm.studentId), status: editForm.status }));
 const x = response?.data || response;
 const student = students.find((s) => String(s.id) === String(editForm.studentId));
 setCards(old => old.map(card => card.id === editingCard.id ? { ...card, uid: x.uid || uid, student: x.student?.name || student?.name || card.student, nis: x.student?.nis || student?.nis || card.nis, className: x.student?.school_class?.name || student?.className || card.className, student_id: x.student_id || Number(editForm.studentId), status: x.status || editForm.status } : card));
 setLogs(old => [{ id: Date.now(), time: new Date().toLocaleTimeString("id-ID"), message: `Kartu RFID ${uid} diperbarui`, type: "rfid" }, ...old].slice(0, 200));
 toast.success("Kartu RFID diperbarui.");
 setEditingCard(null);
 } catch (error) {
 toast.error(error?.response?.data?.message || "Perubahan kartu RFID gagal disimpan.");
 } finally {
 setEditSaving(false);
 }
 };
 const removeCard = (card) => {
 setConfirm({ title: "Hapus Kartu RFID?", message: `Kartu ${card.uid} milik ${card.student} akan dihapus permanen.`, confirmLabel: "Hapus", tone: "danger", onConfirm: async () => {
 try {
 await import("../api/rfidApi").then(m => m.deleteRfidCard(card.id));
 } catch (error) {
 toast.error(error?.response?.data?.message || "Kartu RFID gagal dihapus.");
 return;
 }
 setCards(old => old.filter(item => item.id !== card.id));
 setLogs(old => [{ id: Date.now(), time: new Date().toLocaleTimeString("id-ID"), message: `Kartu RFID ${card.uid} dihapus`, type: "rfid" }, ...old].slice(0, 200));
 toast.success("Kartu RFID dihapus.");
 } });
 };
 const importCards = (event) => {
 const file = event.target.files?.[0];
 if (!file) return;
 const reader = new FileReader();
 reader.onerror = () => { setImporting(false); setImportMessage("Gagal membaca file CSV."); };
 reader.onload = async () => {
  const rows = parseCsvToRows(String(reader.result)).slice(1);
  const validRows = rows.filter((r) => r[0] && r[1]);
  if (!validRows.length) {
   setImportMessage("Tidak ada baris valid. Format: UID,NIS,Status.");
   event.target.value = "";
   return;
  }
  setImporting(true);
  const seen = new Set(cards.map((card) => String(card.uid).toUpperCase()));
  let success = 0;
  let failed = 0;
  for (const row of validRows) {
   const uid = String(row[0]).trim().toUpperCase();
   const nis = String(row[1]).trim();
   const status = String(row[2] || "Aktif").trim() || "Aktif";
   const student = students.find((item) => String(item.nis) === nis);
   if (!uid || !student || seen.has(uid)) { failed++; continue; }
   try {
    const response = await import("../api/rfidApi").then((m) => m.createRfidCard({ student_id: student.id, uid, label: "Import CSV", status }));
    const x = response?.data || response;
    seen.add(uid);
    setCards((old) => [{ id: x.id, uid: x.uid || uid, student: x.student?.name || student.name, nis: x.student?.nis || student.nis, className: x.student?.school_class?.name || student.className || "", student_id: x.student_id || student.id, status: x.status || status, source: "Import CSV" }, ...old]);
    success++;
   } catch {
    failed++;
   }
  }
  setImporting(false);
  setImportMessage(`Import selesai: ${success} kartu tersimpan${failed ? `, ${failed} gagal (NIS tidak ditemukan atau UID duplikat)` : ""}.`);
  if (success > 0) {
   setLogs((old) => [{ id: Date.now(), time: new Date().toLocaleTimeString("id-ID"), message: `Import ${success} kartu RFID`, type: "rfid" }, ...old].slice(0, 200));
  }
  event.target.value = "";
 };
 reader.readAsText(file);
 };
 const exportColumns = [
 { key: "uid", label: "RFID UID" },
 { key: "nis", label: "NIS" },
 { key: "student", label: "Siswa" },
 { key: "className", label: "Kelas" },
 { key: "status", label: "Status" },
 ];
 const exportFilters = [
 { id: "class", label: "Kelas", value: classFilter, options: [{ value: "Semua", label: "Semua kelas" }, ...classes.map((item) => ({ value: item.name, label: item.name }))], onChange: setClassFilter },
 { id: "status", label: "Status", value: statusFilter, options: [{ value: "Semua", label: "Semua status" }, { value: "Aktif", label: "Aktif" }, { value: "Nonaktif", label: "Nonaktif" }], onChange: setStatusFilter },
 ]; return <div className="page-stack">
 <PageHead title="Kartu RFID" description="Daftarkan dan kelola kartu RFID siswa." />
 <section className="panel">
 <Header title="Daftarkan Kartu" />
 <div className="rfid-mode-switch"><button className={mode === "device" ? "active" : ""} onClick={() => switchMode("device")}><Cpu size={16} /> Dari Alat RFID</button><button className={mode === "manual" ? "active" : ""} onClick={() => switchMode("manual")}><Edit3 size={16} /> Manual</button></div>
 {mode === "device" && <>
 <div className="rfid-device-picker"><label htmlFor="rfid-device-picker">Perangkat</label><select id="rfid-device-picker" value={effectiveDeviceId} onChange={(e) => setScanDeviceId(e.target.value)} disabled={scanState === "waiting"}>{onlineDevices.length ? onlineDevices.map((device) => <option key={device.id} value={device.id}>{device.name}{device.location ? ` ${device.location}` : ""}</option>) : <option value="">Tidak ada perangkat online</option>}</select></div>
 <div className={`rfid-reader-state ${deviceState}`}><div className="reader-icon"><CreditCard size={22} /></div><div><strong>{scanState === "waiting" ? "Menunggu alat siap..." : deviceState === "detected" ? "Kartu terdeteksi" : "Siap membaca kartu"}</strong><span>{scanState === "waiting" ? `Perintah dikirim ke ${selectedDevice?.name || "alat"}. Tunggu LCD menampilkan MODE BACA UID (maks 3 dtk), lalu tempelkan kartu.` : deviceState === "detected" ? "UID sudah diambil dari alat RFID. Pilih siswa lalu daftarkan." : onlineDevices.length ? "Pilih perangkat lalu tekan Baca UID dari Alat." : "Tidak ada perangkat online. Nyalakan alat terlebih dahulu."}</span></div>{scanState === "waiting" ? <button type="button" className="secondary-button" onClick={cancelScan}>Batalkan</button> : <div className="rfid-reader-actions"><button type="button" className="secondary-button" onClick={startScan} disabled={!effectiveDeviceId}>{deviceState === "detected" ? "Baca Ulang" : "Baca UID dari Alat"}</button>{deviceState === "detected" && <button type="button" className="small-action" onClick={finishScan}>Selesai</button>}</div>}</div>
 <p className="rfid-auto-note">Alat masuk mode baca maksimal 3 detik setelah perintah. Sesi aktif 60 detik; alat kembali ke absensi otomatis setelah UID terbaca, timeout, atau dibatalkan.</p>
 </>}
 <form className="rfid-form" onSubmit={register}><label className="setting"><span>RFID UID</span><input value={form.uid} onChange={(e) => setForm((prev) => ({ ...prev, uid: e.target.value }))} placeholder={mode === "device" ? "Otomatis dari alat" : "Contoh: A3:B7:91:2F"} readOnly={mode === "device"} /></label><div className="setting"><span>Nama Siswa</span><StudentPicker students={students} value={form.studentId} onChange={(student) => setForm((prev) => ({ ...prev, studentId: String(student.id) }))} /></div><label className="setting"><span>NIS</span><input readOnly value={selectedStudent?.nis || ""} placeholder="Otomatis dari siswa" /></label><label className="setting"><span>Kelas</span><input readOnly value={selectedStudent?.className || ""} placeholder="Otomatis dari siswa" /></label><button className="primary-button" type="submit"><CreditCard size={16} /> Daftarkan</button></form>
 </section>
 <div className="toolbar">
 <div className="search"><Search size={17} /><input placeholder="Cari UID, nama, atau NIS..." value={query} onChange={(e) => setQuery(e.target.value)} /></div>
 <select value={classFilter} onChange={(e) => setClassFilter(e.target.value)} aria-label="Filter kelas"><option>Semua</option>{classes.map((item) => <option key={item.id} value={item.name}>{item.name}</option>)}</select>
 <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)} aria-label="Filter status"><option>Semua</option><option>Aktif</option><option>Nonaktif</option></select>
 <button type="button" className="secondary-button" onClick={() => setExportOpen(true)}><Download size={15} />Export</button>
 <label className={`secondary-button file-action ${importing ? "disabled" : ""}`} title="Format CSV: UID,NIS,Status">
 {importing ? <RefreshCw size={15} /> : <Upload size={15} />}
 {importing ? "Mengimpor..." : "Import CSV"}
 <input type="file" accept=".csv,text/csv" disabled={importing} onChange={importCards} />
 </label>
 </div>
 {importMessage && <div className="save-toast"><Check size={16} />{importMessage}</div>}
 <section className="panel"><div className="table-wrap"><table><thead><tr><th>RFID UID</th><th>NIS</th><th>Siswa</th><th>Status</th><th style={{ width: 56 }} aria-label="Aksi" /></tr></thead><tbody>{visibleCards.length ? visibleCards.map((card) => <tr key={card.id}><td><strong>{card.uid}</strong></td><td>{card.nis}</td><td>{card.student}{card.className ? <small className="cell-sub">{card.className}</small> : null}</td><td><span className={`status-badge ${card.status === "Aktif" ? "online" : "offline"}`}><i />{card.status === "Aktif" ? "Aktif" : "Nonaktif"}</span></td><td><RowMenu items={[{ label: "Edit", icon: <Edit3 size={15} />, onClick: () => startEdit(card) }, { label: card.status === "Aktif" ? "Nonaktifkan" : "Aktifkan", icon: card.status === "Aktif" ? <ShieldOff size={15} /> : <ShieldCheck size={15} />, onClick: () => toggle(card.id) }, { label: "Hapus", icon: <Trash2 size={15} />, danger: true, onClick: () => removeCard(card) }]} /></td></tr>) : <tr><td colSpan="5" className="empty">Data tidak ditemukan.</td></tr>}</tbody></table></div><Pagination page={safePage} totalPages={totalPages} onChange={setPage}/></section>
 {editingCard && (
 <div className="modal-backdrop" onClick={() => !editSaving && setEditingCard(null)} role="presentation">
 <div className="modal export-modal" onClick={(event) => event.stopPropagation()} role="dialog" aria-modal="true" aria-label="Edit kartu RFID">
 <div className="modal-head">
 <div><h2>Edit Kartu RFID</h2><p>Perbarui UID, siswa pemilik, atau status kartu.</p></div>
 <button type="button" className="modal-close" onClick={() => setEditingCard(null)} aria-label="Tutup"><X size={18} /></button>
 </div>
 <form onSubmit={saveEdit}>
 <label className="setting"><span>RFID UID</span><input value={editForm.uid} onChange={(e) => setEditForm((prev) => ({ ...prev, uid: e.target.value }))} placeholder="Contoh: A3:B7:91:2F" /></label>
 <div className="setting"><span>Nama Siswa</span><StudentPicker students={students} value={editForm.studentId} onChange={(student) => setEditForm((prev) => ({ ...prev, studentId: String(student.id) }))} /></div>
 <label className="setting"><span>Status</span><select value={editForm.status} onChange={(e) => setEditForm((prev) => ({ ...prev, status: e.target.value }))}><option>Aktif</option><option>Nonaktif</option></select></label>
 <div className="modal-actions"><button type="button" className="secondary-button" onClick={() => setEditingCard(null)} disabled={editSaving}>Batal</button><button type="submit" className="primary-button" disabled={editSaving}>{editSaving ? "Menyimpan..." : <><Check size={16} />Simpan</>}</button></div>
 </form>
 </div>
 </div>
 )}
 <ExportDataModal
 open={exportOpen}
 onClose={() => setExportOpen(false)}
 title="Export Kartu RFID"
 subtitle="Unduh daftar kartu beserta siswanya dalam format pilihan."
 rows={filtered}
 columns={exportColumns}
 filenameBase="presentech-kartu-rfid"
 filters={exportFilters}
 meta={(count) => [
 `${count} kartu RFID`,
 `${cards.filter((card) => card.status === "Aktif").length} kartu aktif dari ${cards.length} total`,
 query ? `Pencarian: "${query}"` : "Tanpa kata kunci pencarian",
 ]}
 appliedNote={query ? `Pencarian "${query}" ikut diterapkan pada file.` : ""}
 />
 </div>;
}

function StudentPicker({ students = [], value, onChange }) {
 const [open, setOpen] = useState(false);
 const [query, setQuery] = useState("");
 const [activeIndex, setActiveIndex] = useState(0);
  const [panelStyle, setPanelStyle] = useState(null);
 const containerRef = useRef(null);
  const panelRef = useRef(null);

 const selectable = useMemo(() => students
 .filter((student) => (student.status || "Aktif") === "Aktif")
 .slice()
 .sort((a, b) => String(a.className || "").localeCompare(String(b.className || ""), "id") || String(a.name || "").localeCompare(String(b.name || ""), "id")), [students]);

 const selected = students.find((student) => String(student.id) === String(value));

 const filtered = useMemo(() => {
 const keyword = query.trim().toLowerCase();
 if (!keyword) return selectable;
 return selectable.filter((student) => `${student.name || ""} ${student.nis || ""} ${student.className || ""}`.toLowerCase().includes(keyword));
 }, [query, selectable]);

 const groups = useMemo(() => {
 const map = new Map();
 filtered.forEach((student) => {
 const key = student.className || "Kelas belum ditentukan";
 if (!map.has(key)) map.set(key, []);
 map.get(key).push(student);
 });
 return [...map.entries()];
 }, [filtered]);

 useEffect(() => {
 if (!open) return;
 const onPointerDown = (event) => {
 if (!containerRef.current?.contains(event.target)) {
 setOpen(false);
 setQuery("");
 }
 };
 document.addEventListener("mousedown", onPointerDown);
 return () => document.removeEventListener("mousedown", onPointerDown);
 }, [open]);


  // Panel siswa memakai position:fixed + flip otomatis agar tidak terpotong
  // oleh modal/panel yang dapat di-scroll saat trigger berada di bawah.
  useLayoutEffect(() => {
    if (!open) {
      setPanelStyle(null);
      return undefined;
    }

    const update = () => {
      const trigger = containerRef.current?.querySelector(".student-picker-trigger");
      if (!trigger) return;
      const rect = trigger.getBoundingClientRect();
      const spaceBelow = window.innerHeight - rect.bottom - 12;
      const spaceAbove = rect.top - 12;
      const openUp = spaceBelow < 220 && spaceAbove > spaceBelow;
      const maxHeight = Math.max(180, Math.min(360, openUp ? spaceAbove : spaceBelow));
      setPanelStyle({
        position: "fixed",
        left: Math.max(8, Math.min(rect.left, window.innerWidth - rect.width - 8)),
        width: rect.width,
        maxHeight,
        top: openUp ? Math.max(8, rect.top - maxHeight - 6) : rect.bottom + 6,
      });
    };

    update();
    window.addEventListener("resize", update);
    window.addEventListener("scroll", update, true);

    return () => {
      window.removeEventListener("resize", update);
      window.removeEventListener("scroll", update, true);
    };
  }, [open]);
 const closePicker = () => {
 setOpen(false);
 setQuery("");
 };

 const openPicker = () => {
 setQuery("");
 setActiveIndex(0);
 setOpen(true);
 };

 const choose = (student) => {
 onChange?.(student);
 closePicker();
 };

 const onSearchKeyDown = (event) => {
 if (event.key === "ArrowDown") {
 event.preventDefault();
 setActiveIndex((index) => Math.min(filtered.length - 1, index + 1));
 } else if (event.key === "ArrowUp") {
 event.preventDefault();
 setActiveIndex((index) => Math.max(0, index - 1));
 } else if (event.key === "Enter") {
 event.preventDefault();
 const student = filtered[activeIndex];
 if (student) choose(student);
 } else if (event.key === "Escape") {
 event.preventDefault();
 closePicker();
 }
 };

 return (
 <div className="student-picker" ref={containerRef}>
 <button type="button" className={`student-picker-trigger ${open ? "open" : ""}`} onClick={() => (open ? closePicker() : openPicker())} aria-haspopup="listbox" aria-expanded={open}>
 <span className={selected ? "" : "placeholder"}>{selected ? `${selected.name} ${selected.nis || "-"} ${selected.className || "Kelas belum ditentukan"}` : "Pilih siswa..."}</span>
 <ChevronDown size={15} />
 </button>
 {open && <div className="student-picker-panel" ref={panelRef} style={panelStyle || { visibility: "hidden" }}>
 <div className="student-picker-search"><Search size={14} /><input autoFocus value={query} onChange={(event) => { setQuery(event.target.value); setActiveIndex(0); }} onKeyDown={onSearchKeyDown} placeholder="Cari nama, NIS, atau kelas..." aria-label="Cari siswa" /></div>
 <div className="student-picker-list" role="listbox" style={{ maxHeight: panelStyle ? Math.max(120, panelStyle.maxHeight - 92) : 250 }}>
 {filtered.length ? groups.map(([className, items]) => <div key={className}><div className="student-picker-group">{className}</div>{items.map((student) => {
 const index = filtered.findIndex((item) => String(item.id) === String(student.id));
 return <button key={student.id} type="button" role="option" aria-selected={String(student.id) === String(value)} className={`student-picker-option ${index === activeIndex ? "active" : ""}`} onMouseEnter={() => setActiveIndex(index)} onClick={() => choose(student)}><strong>{student.name}</strong><small>{student.nis || "-"} {className}</small></button>;
 })}</div>) : <div className="student-picker-empty">Siswa tidak ditemukan.</div>}
 </div>
 </div>}
 </div>
 );
}

function LogsPage({ logs }) { const [query,setQuery]=useState(""); const [type,setType]=useState("Semua"); const [page,setPage]=useState(1); const perPage=50; const systemLogs=logs.filter(log=>log.type!=="attendance"); const types={system:"Sistem",device:"Perangkat",rfid:"RFID",settings:"Pengaturan",api:"API"}; const filtered=systemLogs.filter(log=>`${log.message} ${log.detail||""}`.toLowerCase().includes(query.toLowerCase())&&(type==="Semua"||types[log.type]===type)); const totalPages=Math.max(1,Math.ceil(filtered.length/perPage)); const safePage=Math.min(page,totalPages); const visible=filtered.slice((safePage-1)*perPage,safePage*perPage); useEffect(()=>setPage(1),[query,type]); return <div className="page-stack"><PageHead title="Log Sistem" description="Riwayat perubahan dan aktivitas penting sistem. Absensi siswa dikelola terpisah di menu Absensi."/><div className="toolbar"><div className="search"><Search size={17}/><input placeholder="Cari aktivitas, perangkat, atau admin..." value={query} onChange={e=>setQuery(e.target.value)}/></div><select value={type} onChange={e=>setType(e.target.value)}><option>Semua</option><option>Sistem</option><option>Perangkat</option><option>RFID</option><option>Pengaturan</option><option>API</option></select></div><section className="panel"><div className="log-summary"><span>{filtered.length} aktivitas ditemukan</span><span>50 data per halaman</span></div><div className="system-log-list">{visible.length?visible.map(log=><div className="system-log" key={log.id}><div className={`log-icon ${log.type||"system"}`}>{log.type==="device"?<Cpu size={16}/>:log.type==="rfid"?<CreditCard size={16}/>:log.type==="settings"?<Settings size={16}/>:<ScrollText size={16}/>}</div><div className="log-content"><div className="log-title-row"><strong>{log.message}</strong><span className="log-type">{types[log.type]||"Sistem"}</span></div><small>{log.time}</small>{log.detail&&<p>{log.detail}</p>}</div></div>):<div className="empty">Tidak ada aktivitas yang cocok.</div>}</div><Pagination page={safePage} totalPages={totalPages} onChange={setPage}/></section></div>; }


const DEFAULT_SUBJECTS = [
 "Matematika", "Bahasa Indonesia", "Bahasa Inggris", "Bahasa Jawa", "Pendidikan Agama",
 "PPKn", "IPA", "IPS", "Informatika", "PJOK", "Seni Budaya", "Prakarya",
 "Pemrograman Web", "Pemrograman Dasar", "Pemrograman Mobile", "Basis Data",
 "Produktif RPL", "Produktif DKV", "Produktif TKJ", "Produk Kreatif dan Kewirausahaan",
];

// Slot jam cepat untuk input jadwal (bisa diubah manual setelah diklik).
const TIME_SLOTS = [
 ["07:30", "08:15"],
 ["08:15", "09:00"],
 ["09:15", "10:00"],
 ["10:00", "10:45"],
 ["10:45", "11:30"],
 ["12:30", "13:15"],
 ["13:15", "14:00"],
 ["14:00", "14:45"],
];

const addMinutesToTime = (time, minutes) => {
 const [hour, minute] = String(time || "07:30").split(":").map(Number);
 const total = (((hour * 60) + (minute || 0) + minutes) % 1440 + 1440) % 1440;
 return `${String(Math.floor(total / 60)).padStart(2, "0")}:${String(total % 60).padStart(2, "0")}`;
};

const normalizeSubject = (value) => String(value || "").trim().toLowerCase();

function SchedulePage({ schedule, setSchedule, setLogs, setConfirm, classes = [], teachers = [] }) {
 const days = [...WORK_DAYS];
 const defaultTeacher = teachers[0];
 const defaultClass = classes[0];
 const [day, setDay] = useState("Senin");
 const [query, setQuery] = useState("");
 const [editing, setEditing] = useState(null);
 const [applyToMany, setApplyToMany] = useState(false);
 const [bulkEntries, setBulkEntries] = useState([]);
 const [customSubject, setCustomSubject] = useState(false);
 const [saving, setSaving] = useState(false);
 const [exportOpen, setExportOpen] = useState(false);
 const [exportDay, setExportDay] = useState("Semua");
 const [form, setForm] = useState({
  time: "07:30",
  endTime: "08:30",
  subject: "",
  room: "",
  teacher: defaultTeacher?.name || "",
  teacher_id: defaultTeacher ? String(defaultTeacher.id) : "",
  className: defaultClass?.name || "",
  class_id: defaultClass ? String(defaultClass.id) : "",
 });

 // Kelas/guru baru tersedia setelah data server dimuat: isi nilai awal
 // tanpa menimpa pilihan yang sudah dibuat Admin.
 useEffect(() => {
  if (!teachers.length && !classes.length) return;
  setForm((current) => {
   const teacherId = current.teacher_id || (defaultTeacher ? String(defaultTeacher.id) : "");
   const teacherName = current.teacher || defaultTeacher?.name || "";
   const classId = current.class_id || (defaultClass ? String(defaultClass.id) : "");
   const className = current.className || defaultClass?.name || "";
   if (teacherId === current.teacher_id && teacherName === current.teacher && classId === current.class_id && className === current.className) return current;
   return { ...current, teacher_id: teacherId, teacher: teacherName, class_id: classId, className };
  });
 }, [teachers.length, classes.length]);

 const subjectOptions = useMemo(() => {
  const set = new Set(DEFAULT_SUBJECTS);
  teachers.forEach((item) => { if (String(item.subject || "").trim()) set.add(String(item.subject).trim()); });
  schedule.forEach((item) => { if (String(item.subject || "").trim()) set.add(String(item.subject).trim()); });
  if (String(form.subject || "").trim()) set.add(String(form.subject).trim());
  return [...set].sort((a, b) => a.localeCompare(b, "id"));
 }, [teachers, schedule, form.subject]);

 // Guru hanya yang mapelnya cocok dengan mata pelajaran terpilih.
 // Bila belum ada guru dengan mapel itu, semua guru ditampilkan agar
 // penjadwalan tidak buntu (label peringatan tetap muncul).
 const subjectTeachers = teachers.filter((item) => normalizeSubject(item.subject) === normalizeSubject(form.subject));
 const hasSubjectTeacher = subjectTeachers.length > 0;
 const selectedTeacher = teachers.find((item) => String(item.id) === String(form.teacher_id));
 const baseTeacherChoices = hasSubjectTeacher ? subjectTeachers : teachers;
 // Guru yang sedang terpilih tetap ditampilkan agar nilai lama tidak hilang
 // saat Admin mengedit jadwal lama.
 const teacherChoices = selectedTeacher && !baseTeacherChoices.some((item) => String(item.id) === String(selectedTeacher.id))
  ? [selectedTeacher, ...baseTeacherChoices]
  : baseTeacherChoices;
 const selectedClassId = form.class_id || (defaultClass ? String(defaultClass.id) : "");

 const toggleBulkClass = (schoolClass) => {
  setBulkEntries((entries) => entries.some((entry) => entry.id === String(schoolClass.id))
   ? entries.filter((entry) => entry.id !== String(schoolClass.id))
   : [...entries, { id: String(schoolClass.id), time: form.time, endTime: form.endTime }]);
 };

 const updateBulkEntry = (id, patch) => {
  setBulkEntries((entries) => entries.map((entry) => (entry.id === id ? { ...entry, ...patch } : entry)));
 };

 const filtered = schedule
  .filter((item) => item.day === day && JSON.stringify(item).toLowerCase().includes(query.toLowerCase()))
  .sort((a, b) => String(a.time).localeCompare(String(b.time)));
 const [page, setPage] = useState(1);
 const perPage = 20;
 const totalPages = Math.max(1, Math.ceil(filtered.length / perPage));
 const safePage = Math.min(page, totalPages);
 const visible = filtered.slice((safePage - 1) * perPage, safePage * perPage);
 useEffect(() => setPage(1), [day, query]);

 // Export Jadwal: hari dipilih di modal (default mengikuti tab yang aktif),
 // jadi satu tombol bisa mengunduh semua jadwal atau per hari.
 const exportScheduleRows = (exportDay === "Semua" ? schedule : schedule.filter((item) => item.day === exportDay))
 .filter((item) => JSON.stringify(item).toLowerCase().includes(query.toLowerCase()))
 .sort((a, b) => String(WORK_DAYS.indexOf(a.day)).localeCompare(String(WORK_DAYS.indexOf(b.day))) || String(a.time).localeCompare(String(b.time)));
 const exportColumns = [
 { key: "day", label: "Hari" },
 { key: "time", label: "Jam Mulai" },
 { key: "endTime", label: "Jam Selesai" },
 { key: "subject", label: "Mata Pelajaran" },
 { key: "room", label: "Ruangan" },
 { key: "teacher", label: "Guru" },
 { key: "className", label: "Kelas" },
 ];
 const openExport = () => {
 setExportDay(day);
 setExportOpen(true);
 };

 const resetForm = () => {
  setEditing(null);
  setCustomSubject(false);
  setApplyToMany(false);
  setBulkEntries([]);
  setForm({
   time: "07:30",
   endTime: "08:30",
   subject: "",
   room: "",
   teacher: defaultTeacher?.name || "",
   teacher_id: defaultTeacher ? String(defaultTeacher.id) : "",
   className: defaultClass?.name || "",
   class_id: defaultClass ? String(defaultClass.id) : "",
  });
 };

 const changeSubject = (value) => {
  if (value === "__custom") {
   setCustomSubject(true);
   setForm((current) => ({ ...current, subject: "" }));
   return;
  }
  // Guru otomatis mengikuti mapel yang dipilih, tetapi tetap bisa diganti
  // (mis. Matematika diampu 2 guru).
  const matches = teachers.filter((item) => normalizeSubject(item.subject) === normalizeSubject(value));
  const first = matches[0];
  setForm((current) => ({
   ...current,
   subject: value,
   teacher_id: first ? String(first.id) : "",
   teacher: first ? first.name : "",
  }));
 };

 const save = (event) => {
  event.preventDefault();
  const subject = String(form.subject || "").trim();
  // Setiap kelas tujuan punya jam sendiri; mode satu kelas memakai jam form.
  const plans = (applyToMany && !editing
   ? bulkEntries.map((entry) => ({
      target: classes.find((item) => String(item.id) === entry.id),
      time: entry.time,
      endTime: entry.endTime,
     }))
   : [{ target: classes.find((item) => String(item.id) === String(selectedClassId)), time: form.time, endTime: form.endTime }])
   .filter((plan) => plan.target);
  const teacher = teachers.find((item) => String(item.id) === String(form.teacher_id));

  if (!subject) { toast.error("Pilih mata pelajaran terlebih dahulu."); return; }
  if (!form.teacher_id) { toast.error("Pilih guru pengajar."); return; }
  if (!plans.length) { toast.error(applyToMany ? "Pilih minimal satu kelas tujuan." : "Pilih kelas."); return; }
  const invalidPlan = plans.find((plan) => !plan.time || !plan.endTime || String(plan.endTime) <= String(plan.time));
  if (invalidPlan) { toast.error(`Jam kelas ${invalidPlan.target.name} belum benar (selesai harus setelah mulai).`); return; }

  setConfirm({
   title: editing ? "Ubah Jadwal?" : plans.length > 1 ? `Simpan ${plans.length} Jadwal?` : "Simpan Jadwal?",
   message: `${subject} · ${day} ${form.time}-${form.endTime}\n${plans.map((plan) => plan.target.name).join(", ")}`,
   confirmLabel: editing ? "Simpan Perubahan" : "Simpan Jadwal",
   tone: "primary",
   onConfirm: async () => {
    setSaving(true);
    try {
     if (editing) {
      const target = plans[0].target;
      const payload = { class_id: Number(target.id), teacher_id: Number(form.teacher_id), day, start_time: form.time, end_time: form.endTime, subject, room: form.room.trim() || null, academic_year: "2026/2027", semester: "Ganjil", is_active: true };
      const response = await import("../api/scheduleApi").then((m) => m.updateSchedule(editing.id, payload));
      const x = response?.data || response;
      const normalized = { id: x.id || editing.id, day: x.day || day, time: String(x.start_time || form.time).slice(0, 5), endTime: String(x.end_time || form.endTime).slice(0, 5), subject: x.subject || subject, room: x.room || "", teacher: x.teacher?.name || teacher?.name || form.teacher, className: x.school_class?.name || target.name, teacher_id: x.teacher_id || Number(form.teacher_id), class_id: x.class_id || target.id, academic_year: x.academic_year, semester: x.semester };
      setSchedule((old) => old.map((item) => (item.id === editing.id ? normalized : item)));
      setLogs((old) => [{ id: Date.now(), time: new Date().toLocaleTimeString("id-ID"), message: `Admin mengubah jadwal ${subject} (${day})`, detail: `${form.time}-${form.endTime} ${target.name} ${teacher?.name || form.teacher}`, type: "system" }, ...old].slice(0, 200));
      setConfirm(null);
      resetForm();
      toast.success("Jadwal diperbarui.");
      return;
     }

     const created = [];
     for (const plan of plans) {
      const target = plan.target;
      const payload = { class_id: Number(target.id), teacher_id: Number(form.teacher_id), day, start_time: plan.time, end_time: plan.endTime, subject, room: form.room.trim() || null, academic_year: "2026/2027", semester: "Ganjil", is_active: true };
      const response = await import("../api/scheduleApi").then((m) => m.createSchedule(payload));
      const x = response?.data || response;
      created.push({ id: x.id, day: x.day || day, time: String(x.start_time || plan.time).slice(0, 5), endTime: String(x.end_time || plan.endTime).slice(0, 5), subject: x.subject || subject, room: x.room || "", teacher: x.teacher?.name || teacher?.name || form.teacher, className: x.school_class?.name || target.name, teacher_id: x.teacher_id || Number(form.teacher_id), class_id: x.class_id || target.id, academic_year: x.academic_year, semester: x.semester });
     }

     setSchedule((old) => [...old, ...created]);
     setLogs((old) => [{ id: Date.now(), time: new Date().toLocaleTimeString("id-ID"), message: `Admin menambahkan ${created.length} jadwal ${subject} (${day})`, detail: `${form.time}-${form.endTime} ${plans.map((plan) => plan.target.name).join(", ")} ${teacher?.name || form.teacher}`, type: "system" }, ...old].slice(0, 200));
     setConfirm(null);

     // Mode input cepat: kelas/mapel/guru/ruangan dipertahankan, jam otomatis
     // lanjut ke slot berikutnya supaya tidak perlu klik dari awal.
     const nextStart = form.endTime;
     setForm((current) => ({ ...current, time: nextStart, endTime: addMinutesToTime(nextStart, 45) }));
     toast.success(created.length > 1 ? `${created.length} jadwal ditambahkan. Lanjut isi jam berikutnya.` : "Jadwal ditambahkan. Lanjut isi jam berikutnya.");
    } catch (error) {
     console.error(error);
     toast.error(error?.response?.data?.message || "Jadwal gagal disimpan ke database. Pastikan API Laravel aktif.");
     throw error;
    } finally {
     setSaving(false);
    }
   },
  });
 };

 const edit = (item) => {
  setEditing(item);
  setDay(item.day);
  setApplyToMany(false);
  setBulkEntries([]);
  setCustomSubject(false);
  setForm({
   time: item.time,
   endTime: item.endTime || item.time,
   subject: item.subject,
   room: item.room || "",
   teacher: item.teacher || "",
   teacher_id: item.teacher_id || teachers.find((t) => t.name === item.teacher)?.id || "",
   className: item.className || "",
   class_id: item.class_id || classes.find((c) => c.name === item.className)?.id || "",
  });
 };

 const duplicate = (item) => {
  setEditing(null);
  setDay(item.day);
  setApplyToMany(false);
  setBulkEntries([]);
  setCustomSubject(false);
  setForm({ time: item.time, endTime: item.endTime, subject: item.subject, room: item.room || "", teacher: item.teacher || "", teacher_id: item.teacher_id || "", className: item.className || "", class_id: item.class_id || "" });
  toast.info("Salin jadwal: ubah kelas atau hari, lalu simpan sebagai jadwal baru.");
 };

 const remove = (id) => {
  const item = schedule.find((x) => x.id === id);
  if (!item) return;
  setConfirm({ title: "Hapus Jadwal?", message: `Hapus ${item.subject} pada ${item.day}?`, confirmLabel: "Hapus Jadwal", tone: "danger", onConfirm: async () => {
   try {
    await import("../api/scheduleApi").then((m) => m.deleteSchedule(id));
    setSchedule((old) => old.filter((x) => x.id !== id));
    setLogs((old) => [{ id: Date.now(), time: new Date().toLocaleTimeString("id-ID"), message: `Admin menghapus jadwal ${item.subject} (${item.day})`, type: "system" }, ...old].slice(0, 200));
    setConfirm(null);
   } catch (error) {
    console.error(error);
    toast.error(error?.response?.data?.message || "Jadwal gagal dihapus dari database.");
    throw error;
   }
  } });
 };

 return (
 <div className="page-stack">
 <PageHead title="Jadwal" description="Atur jadwal pelajaran yang tampil di dashboard Guru dan Siswa." action={<button type="button" className="secondary-button" onClick={openExport}><Download size={16} />Export</button>} />
 <section className="panel schedule-admin-panel">
 <div className="schedule-admin-top">
 <div className="schedule-days">{days.map((d) => <button type="button" key={d} className={day === d ? "active" : ""} onClick={() => { setDay(d); resetForm(); }}>{d}</button>)}</div>
 <div className="search schedule-search"><Search size={16} /><input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Cari mata pelajaran..." /></div>
 </div>

 <div className="schedule-editor">
 <div className="schedule-editor-head">
 <div>
 <strong>{editing ? "Ubah Jadwal" : "Tambah Jadwal"}</strong>
 <small>{editing ? "Perubahan langsung dipakai dashboard Guru/Siswa." : "Pilih mapel & guru dari daftar; jam otomatis lanjut setelah simpan."}</small>
 </div>
 {!editing && (
 <button type="button" className={`secondary-button schedule-bulk-button ${applyToMany ? "active" : ""}`} onClick={() => { setApplyToMany((value) => !value); if (applyToMany) setBulkEntries([]); }}>
 {applyToMany ? <Minus size={15} /> : <Plus size={15} />} Beberapa kelas
 </button>
 )}
 {editing && <button className="secondary-button" onClick={resetForm}>Batal Edit</button>}
 </div>

 <div className="schedule-slots">
 {TIME_SLOTS.map(([start, end]) => (
 <button type="button" key={start} className={`small-action ${form.time === start && form.endTime === end ? "active" : ""}`} onClick={() => setForm((current) => ({ ...current, time: start, endTime: end }))}>{start}-{end}</button>
 ))}
 </div>

 <form className="schedule-form" onSubmit={save}>
 <label>Jam<input type="time" value={form.time} onChange={(e) => setForm({ ...form, time: e.target.value })} required /></label>
 <label>Selesai<input type="time" value={form.endTime} onChange={(e) => setForm({ ...form, endTime: e.target.value })} required /></label>

 <label>Mata Pelajaran
 {customSubject ? (
 <div className="schedule-custom-subject">
 <input value={form.subject} onChange={(e) => setForm({ ...form, subject: e.target.value })} placeholder="Tulis mata pelajaran" required />
 <button type="button" className="small-action" onClick={() => { setCustomSubject(false); setForm((current) => ({ ...current, subject: "" })); }}>Pilih</button>
 </div>
 ) : (
 <select value={form.subject} onChange={(e) => changeSubject(e.target.value)} required>
 <option value="">Pilih mata pelajaran</option>
 {subjectOptions.map((subject) => <option key={subject} value={subject}>{subject}</option>)}
 <option value="__custom">+ Mata pelajaran lain…</option>
 </select>
 )}
 </label>

 <label>Ruangan<input value={form.room} onChange={(e) => setForm({ ...form, room: e.target.value })} placeholder="Opsional" /></label>

 <label>Guru
 <select value={form.teacher_id} disabled={!String(form.subject || "").trim()} onChange={(e) => { const t = teachers.find((x) => String(x.id) === e.target.value); setForm({ ...form, teacher_id: e.target.value, teacher: t?.name || "" }); }}>
 <option value="">{String(form.subject || "").trim() ? (hasSubjectTeacher ? `Pilih guru ${form.subject}` : "Belum ada guru untuk mapel ini") : "Pilih mata pelajaran dulu"}</option>
 {teacherChoices.map((t) => <option key={t.id} value={t.id}>{t.name}{hasSubjectTeacher ? "" : (t.subject ? ` - ${t.subject}` : "")}</option>)}
 </select>
 {String(form.subject || "").trim() && !hasSubjectTeacher && (
 <small className="schedule-field-note">Belum ada guru dengan mapel ini. Pilih guru, lalu sesuaikan mapelnya di Data Guru.</small>
 )}
 </label>

 <label>Kelas
 <select value={selectedClassId} onChange={(e) => { const c = classes.find((x) => String(x.id) === e.target.value); setForm({ ...form, class_id: e.target.value, className: c?.name || "" }); }} disabled={applyToMany && !editing}>
 <option value="">Pilih kelas</option>
 {classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
 </select>
 </label>

 <button className="primary-button" type="submit" disabled={saving}><CalendarDays size={16} />{saving ? "Menyimpan..." : editing ? "Simpan Perubahan" : "Tambah Jadwal"}</button>

 {applyToMany && !editing && (
 <div className="schedule-bulk">
 <div className="schedule-bulk-head">
 <strong>Kelas tujuan ({bulkEntries.length} dipilih)</strong>
 <div>
 <button type="button" className="small-action" onClick={() => setBulkEntries(classes.map((c) => ({ id: String(c.id), time: form.time, endTime: form.endTime })))}>Pilih semua</button>
 <button type="button" className="small-action" onClick={() => setBulkEntries([])}>Kosongkan</button>
 </div>
 </div>
 <p className="schedule-bulk-note">Centang kelas, lalu atur jam mulai & selesai masing-masing kelas.</p>
 <div className="schedule-bulk-grid">
 {classes.map((c) => {
 const entry = bulkEntries.find((item) => item.id === String(c.id));
 return (
 <div key={c.id} className={`schedule-bulk-item ${entry ? "selected" : ""}`}>
 <label>
 <input type="checkbox" checked={Boolean(entry)} onChange={() => toggleBulkClass(c)} />
 <span>{c.name}</span>
 </label>
 {entry && (
 <div className="schedule-bulk-time">
 <input type="time" value={entry.time} onChange={(e) => updateBulkEntry(entry.id, { time: e.target.value })} aria-label={`Jam mulai ${c.name}`} />
 <span>-</span>
 <input type="time" value={entry.endTime} onChange={(e) => updateBulkEntry(entry.id, { endTime: e.target.value })} aria-label={`Jam selesai ${c.name}`} />
 </div>
 )}
 </div>
 );
 })}
 </div>
 </div>
 )}
 </form>
 </div>
 </section>

 <section className="panel schedule-table-panel">
 <div className="table-wrap">
 <table className="schedule-table">
 <thead><tr><th>Jam</th><th>Mata Pelajaran</th><th>Ruangan</th><th>Guru</th><th>Kelas</th><th>Aksi</th></tr></thead>
 <tbody>
 {visible.length ? visible.map((item) => (
 <tr key={item.id}>
 <td><strong>{item.time}–{item.endTime}</strong><small>{item.day}</small></td>
 <td><strong>{item.subject}</strong></td>
 <td>{item.room || "-"}</td>
 <td>{item.teacher || "Belum ditentukan"}</td>
 <td>{item.className || "Belum ditentukan"}</td>
 <td><div className="row-actions"><button type="button" onClick={() => edit(item)} title="Edit"><Edit3 size={15} /></button><button type="button" onClick={() => duplicate(item)} title="Duplikat"><Copy size={15} /></button><button type="button" onClick={() => remove(item.id)} title="Hapus"><Trash2 size={15} /></button></div></td>
 </tr>
 )) : <tr><td colSpan="6" className="empty">Belum ada jadwal untuk {day}.</td></tr>}
 </tbody>
 </table>
 </div>
 </section>
 <Pagination page={safePage} totalPages={totalPages} onChange={setPage} />

 <ExportDataModal
 open={exportOpen}
 onClose={() => setExportOpen(false)}
 title="Export Jadwal"
 subtitle="Unduh jadwal pelajaran dalam format PDF, Excel, atau CSV."
 rows={exportScheduleRows}
 columns={exportColumns}
 filenameBase="presentech-jadwal"
 filters={[
 { id: "day", label: "Hari", value: exportDay, options: [{ value: "Semua", label: "Semua hari" }, ...days.map((d) => ({ value: d, label: d }))], onChange: setExportDay },
 ]}
 meta={(count) => [
 `${count} jadwal ${exportDay === "Semua" ? "semua hari" : `hari ${exportDay}`}`,
 `${schedule.length} total jadwal tersimpan`,
 query ? `Pencarian: "${query}"` : "Tanpa kata kunci pencarian",
 ]}
 appliedNote={query ? `Pencarian "${query}" ikut diterapkan pada file.` : ""}
 />
 </div>
 );
}

function PasswordResetAdminPage() {
 const [items, setItems] = useState([]);
 const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, total: 0, per_page: 20 });
 const [page, setPage] = useState(1);
 const [loading, setLoading] = useState(true);
 const [busyId, setBusyId] = useState(null);
 const [error, setError] = useState("");
 const [search, setSearch] = useState("");
 const [confirm, setConfirm] = useState(null);

 const load = async (pageNumber = 1) => {
 setLoading(true);
 setError("");
 try {
 const result = await getAdminPasswordResetRequests({ page: pageNumber, per_page: 20 });
 const payload = result?.requests || {};
 const data = Array.isArray(payload) ? payload : (payload.data || []);
 setItems(data);
 setPagination(Array.isArray(payload) ? { current_page: pageNumber, last_page: 1, total: data.length, per_page: 20 } : payload);
 setPage(payload.current_page || pageNumber);
 } catch (e) {
 setError(e?.response?.data?.message || "Gagal mengambil permintaan reset password.");
 } finally {
 setLoading(false);
 }
 };

 useEffect(() => { load(1); }, []);

 const process = (item, action) => {
 if (action === "approve") {
 setBusyId(item.id);
 setError("");
 approvePasswordReset(item.id)
 .then(async (result) => {
 const password = result?.initial_password || item.identifier;
 await load(page);
 setConfirm({
 title: "Permintaan Disetujui",
 message: `Password sementara ${item.user?.name || item.identifier} sudah direset sama dengan NIS/NIP. Sampaikan data login berikut; pengguna wajib menggantinya saat login:`,
 confirmLabel: "Selesai",
 input: password,
 copyable: true,
 onConfirm: () => {},
 });
 })
 .catch((e) => setError(e?.response?.data?.message || "Permintaan gagal diproses."))
 .finally(() => setBusyId(null));
 return;
 }
 setConfirm({
 title: "Tolak Permintaan?",
 message: `Tolak permintaan reset password untuk ${item.user?.name || item.identifier}?`,
 confirmLabel: "Tolak Permintaan",
 tone: "danger",
 input: "Permintaan tidak disetujui Admin.",
 onConfirm: async (note) => {
 setBusyId(item.id);
 setError("");
 try {
 await rejectPasswordReset(item.id, note || "");
 await load(page);
 } catch (e) {
 setError(e?.response?.data?.message || "Permintaan gagal diproses.");
 throw e;
 } finally {
 setBusyId(null);
 }
 },
 });
 };

 // Reset password langsung tanpa menunggu pengajuan untuk pengguna
 // yang benar-benar terkunci. Password dikembalikan sama dengan NIS/NIP.
 const resetDirect = async (item) => {
 if (!item.user_id) {
 setError("Pengguna tidak ditemukan pada permintaan ini.");
 return;
 }
 setBusyId(item.id);
 setError("");
 try {
 const result = await adminSetPassword(item.user_id);
 const password = result?.initial_password || item.identifier;
 setConfirm({
 title: "Password Direset",
 message: `Password sementara ${item.identifier} sekarang sama dengan NIS/NIP akunnya dan wajib diganti saat login.\nGunakan data berikut:`,
 confirmLabel: "Selesai",
 input: password,
 onConfirm: async () => { await load(page); },
 });
 } catch (e) {
 setError(e?.response?.data?.message || "Reset langsung gagal diproses.");
 } finally {
 setBusyId(null);
 }
 };

 const filtered = items.filter((item) => {
 const q = search.trim().toLowerCase();
 if (!q) return true;
 return `${item.identifier || ""} ${item.user?.name || ""} ${item.role || ""} ${item.status || ""}`.toLowerCase().includes(q);
 });

 return <div className="page-stack">
 <PageHead title="Permintaan Reset Password" description="Periksa dan proses permintaan reset password dari Guru dan Siswa." />
 {error && <div className="log-notice"><KeyRound size={16}/><div><strong>Terjadi kesalahan</strong><span>{error}</span></div></div>}
 <section className="panel">
 <div className="toolbar">
 <div className="search"><Search size={17}/><input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Cari NIS/NIP atau nama..." /></div>
 <button className="secondary-button" onClick={() => load(page)} disabled={loading}><RefreshCw size={15}/> Refresh</button>
 </div>
 <div className="log-summary"><span>{pagination.total || 0} permintaan ditemukan</span><span>20 data per halaman</span></div>
 <div className="system-log-list">
 {loading ? <div className="empty">Memuat permintaan...</div> : filtered.length ? filtered.map((item) => <div className="system-log" key={item.id}>
 <div className="log-icon system"><KeyRound size={16}/></div>
 <div className="log-content">
 <div className="log-title-row"><strong>{item.user?.name || "Pengguna"} {item.identifier}</strong><span className={`log-type reset-status-${String(item.status).toLowerCase()}`}>{item.status}</span></div>
 <small>{String(item.role || "").toUpperCase()} {item.created_at ? new Date(item.created_at).toLocaleString("id-ID") : "-"}</small>
 {item.review_note && <p>{item.review_note}</p>}
 <div className="row-actions">
 {item.status === "PENDING" && <>
 <button className="primary-button" onClick={() => process(item, "approve")} disabled={busyId === item.id}>{busyId === item.id ? "Memproses..." : "Setujui"}</button>
 <button className="danger-button" onClick={() => process(item, "reject")} disabled={busyId === item.id}>Tolak</button>
 </>}
 {item.user_id && <button className="secondary-button" onClick={() => resetDirect(item)} disabled={busyId === item.id}>Reset Langsung</button>}
 </div>
 </div>
 </div>) : <div className="empty">Belum ada permintaan reset password.</div>}
 </div>
 <div className="pagination-controls">
 <button className="secondary-button" disabled={page <= 1 || loading} onClick={() => load(page - 1)}>‹ Sebelumnya</button>
 <span>Halaman {pagination.current_page || page} dari {pagination.last_page || 1}</span>
 <button className="secondary-button" disabled={page >= (pagination.last_page || 1) || loading} onClick={() => load(page + 1)}>Berikutnya ›</button>
 </div>
 </section>
 {confirm && <ConfirmModal {...confirm} onClose={() => setConfirm(null)} />}
 </div>;
}

function AdminCorrections() {
 const [items,setItems]=useState([]); const [page,setPage]=useState(1); const [pagination,setPagination]=useState({current_page:1,last_page:1,total:0}); const [loading,setLoading]=useState(true); const [error,setError]=useState(""); const [busyId,setBusyId]=useState(null);
 const load=async(p=1)=>{setLoading(true);setError("");try{const r=await import("../api/correctionApi").then(m=>m.getCorrections({page:p,per_page:20}));const data=Array.isArray(r)?r:(r?.data||[]);setItems(data);setPagination(Array.isArray(r)?{current_page:p,last_page:1,total:data.length}:r);setPage(p);}catch(e){setError(e?.response?.data?.message||"Gagal mengambil data koreksi.");}finally{setLoading(false);}};
 useEffect(()=>{load(1);},[]);
 const decide=async(item,status)=>{if(busyId)return;setBusyId(item.id);setError("");try{await import("../api/correctionApi").then(m=>m.updateCorrection(item.id,{status:status==="Disetujui"?"APPROVED":"REJECTED"})); await load(page);}catch(e){setError(e?.response?.data?.message||"Keputusan koreksi gagal disimpan.");}finally{setBusyId(null);}};
 return <div className="page-stack"><PageHead title="Koreksi Absensi" description="Tinjau pengajuan perubahan absensi dari Guru dan Siswa."/>{error&&<div className="log-notice"><FileWarning size={16}/><div><strong>Terjadi kesalahan</strong><span>{error}</span></div></div>}<section className="panel"><div className="log-summary"><span>{pagination.total||0} pengajuan</span><span>20 data per halaman</span></div><div className="system-log-list">{loading?<div className="empty">Memuat pengajuan...</div>:items.length?items.map(item=>{const status=item.status==="PENDING"?"Menunggu":item.status==="APPROVED"?"Disetujui":"Ditolak";return <div className="system-log" key={item.id}><div className="log-icon system"><FileWarning size={16}/></div><div className="log-content"><div className="log-title-row"><strong>{item.student?.name||"Siswa"} {item.student?.nis||"-"}</strong><span className="log-type">{status}</span></div><small>{item.attendance_date} {String(item.expected_time||item.actual_time||"").slice(0,5)} {item.requester?.name||"Pengguna"}</small><p>{item.reason}</p>{item.review_note&&<p>{item.review_note}</p>}{item.status==="PENDING"&&<div className="correction-admin-actions"><button className="primary-button" onClick={()=>decide(item,"Disetujui")} disabled={busyId===item.id}><Check size={15}/>{busyId===item.id?"Memproses...":"Setujui"}</button><button className="danger-button" onClick={()=>decide(item,"Ditolak")} disabled={busyId===item.id}><X size={15}/>Tolak</button></div>}</div></div>;}):<div className="empty">Belum ada pengajuan koreksi.</div>}</div><Pagination page={pagination.current_page||page} totalPages={pagination.last_page||1} onChange={load}/></section></div>;
}

const DAY_TIME_ROWS = [
 { key: "monday", label: "Senin" },
 { key: "tuesday", label: "Selasa" },
 { key: "wednesday", label: "Rabu" },
 { key: "thursday", label: "Kamis" },
 { key: "friday", label: "Jumat" },
];

const DEFAULT_TIMES = { entry: "07:00", late: "07:15", home: "14:00" };

// Isi tiap hari dari jam khusus yang tersimpan; hari tanpa jam khusus
// memakai jam umum supaya Admin selalu melihat jam yang berlaku.
const buildDayTimes = (saved = {}, fallback = DEFAULT_TIMES) => Object.fromEntries(DAY_TIME_ROWS.map((day) => {
 const times = saved?.[day.key] || {};
 return [day.key, {
  entry: String(times.entry_time || fallback.entry || "").slice(0, 5),
  late: String(times.late_time || fallback.late || "").slice(0, 5),
  home: String(times.home_time || fallback.home || "").slice(0, 5),
 }];
}));

function SettingsPage({ darkMode, setDarkMode, setLogs, setConfirm, onNavigate }) {
 const [saveResult, setSaveResult] = useState(null);
 const [checkingDevices, setCheckingDevices] = useState(false);
 const [loading, setLoading] = useState(true);
 const [saving, setSaving] = useState(false);
 const [error, setError] = useState("");
 const [dayTimes, setDayTimes] = useState(() => buildDayTimes());
 const [baselineTimes, setBaselineTimes] = useState(null);
 const [holidays, setHolidays] = useState([]);
 const [holidayYear, setHolidayYear] = useState(() => new Date().getFullYear());
 const [holidayForm, setHolidayForm] = useState({ date: "", name: "", type: "nasional" });
 const [holidayBusy, setHolidayBusy] = useState(false);
 const [holidayError, setHolidayError] = useState("");
 const [form, setForm] = useState({
  entry: "07:00",
  late: "07:15",
  home: "14:00",
  timezone: "Asia/Jakarta",
  academicYear: "2026/2027",
  semester: "Ganjil",
  mqttEnabled: false,
  mqtt: "mqtt://localhost",
  port: "1883",
  mqttTls: false,
  mqttUser: "",
  mqttPass: "",
  mqttPassConfigured: false,
  topic: "presentech",
 });
 const update = (k, v) => setForm((f) => ({ ...f, [k]: v }));
 const updateDayTime = (day, field, value) => setDayTimes((old) => ({
  ...old,
  [day]: { ...old[day], [field]: value },
 }));

 const applySettings = (s) => {
  const fallback = {
   entry: String(s.entry_time || DEFAULT_TIMES.entry).slice(0, 5),
   late: String(s.late_time || DEFAULT_TIMES.late).slice(0, 5),
   home: String(s.home_time || DEFAULT_TIMES.home).slice(0, 5),
  };

  setForm((f) => ({
   ...f,
   entry: fallback.entry,
   late: fallback.late,
   home: fallback.home,
   timezone: s.timezone || f.timezone,
   academicYear: s.academic_year || f.academicYear,
   semester: s.semester || f.semester,
   mqttEnabled: Boolean(s.mqtt_enabled),
   mqtt: s.mqtt_broker || f.mqtt,
   port: String(s.mqtt_port || f.port),
   mqttTls: Boolean(s.mqtt_tls),
   mqttUser: s.mqtt_username || "",
   // Password tidak pernah dikirim server; kosong berarti "jangan diubah".
   mqttPass: "",
   mqttPassConfigured: Boolean(s.mqtt_password_configured),
   topic: s.mqtt_topic || f.topic,
  }));

  // Hari yang belum punya jam khusus diisi dari jam umum agar Admin
  // langsung melihat jam yang berlaku dan bisa mengubahnya per hari.
  const nextDays = buildDayTimes(s.day_times || {}, fallback);
  setDayTimes(nextDays);
  // Simpan salinan untuk mendeteksi perubahan yang belum disimpan.
  setBaselineTimes({ ...fallback, days: nextDays });
 };

 useEffect(() => {
  let cancelled = false;
  (async () => {
   try {
    const result = await import("../api/settingsApi").then((m) => m.getSettings());
    if (!cancelled) applySettings(result?.settings || {});
   } catch (e) {
    if (!cancelled) setError(e?.response?.data?.message || "Pengaturan gagal dimuat dari server.");
   } finally {
    if (!cancelled) setLoading(false);
   }
  })();
  return () => { cancelled = true; };
 }, []);

 // Kalender hari libur per tahun yang sedang dilihat.
 useEffect(() => {
  let cancelled = false;
  (async () => {
   try {
    const result = await import("../api/holidayApi").then((m) => m.getHolidays({ year: holidayYear, per_page: 500 }));
    const list = Array.isArray(result) ? result : (result?.data || []);
    if (!cancelled) {
     setHolidays(list);
     setHolidayError("");
    }
   } catch (e) {
    if (!cancelled) setHolidayError(e?.response?.data?.message || "Daftar hari libur gagal dimuat.");
   }
  })();
  return () => { cancelled = true; };
 }, [holidayYear]);

 const addHoliday = async (event) => {
  event.preventDefault();
  if (holidayBusy) return;

  const name = holidayForm.name.trim();

  if (!holidayForm.date || !name) {
   setHolidayError("Tanggal dan nama hari libur wajib diisi.");
   return;
  }

  setHolidayBusy(true);
  setHolidayError("");

  try {
   const created = await import("../api/holidayApi").then((m) => m.createHoliday({
    date: holidayForm.date,
    name,
    type: holidayForm.type,
   }));

   setHolidays((old) => [...old, created].sort((a, b) => String(a.date).localeCompare(String(b.date))));
   setHolidayForm({ date: "", name: "", type: "nasional" });
   toast.success(`Hari libur "${created.name}" ditambahkan.`);
   setLogs((old) => [{ id: Date.now(), time: new Date().toLocaleTimeString("id-ID"), message: `Admin menambahkan hari libur: ${created.name} (${created.date})`, type: "settings" }, ...old].slice(0, 200));
  } catch (e) {
   setHolidayError(e?.response?.data?.message || "Hari libur gagal disimpan. Periksa tanggal dan nama.");
  } finally {
   setHolidayBusy(false);
  }
 };

 const removeHoliday = (holiday) => {
  setConfirm({
   title: "Hapus Hari Libur?",
   message: `${holiday.name}\n${formatHolidayDate(holiday.date)}\n\nTanggal ini akan kembali dihitung sebagai hari sekolah, sehingga siswa yang tidak tap bisa ditandai Alpa otomatis.`,
   confirmLabel: "Hapus Hari Libur",
   tone: "danger",
   onConfirm: async () => {
    try {
     await import("../api/holidayApi").then((m) => m.deleteHoliday(holiday.id));
     setHolidays((old) => old.filter((item) => item.id !== holiday.id));
     toast.success("Hari libur dihapus.");
     setLogs((old) => [{ id: Date.now(), time: new Date().toLocaleTimeString("id-ID"), message: `Admin menghapus hari libur: ${holiday.name} (${holiday.date})`, type: "settings" }, ...old].slice(0, 200));
    } catch (e) {
     toast.error(e?.response?.data?.message || "Hari libur gagal dihapus.");
     throw e;
    }
   },
  });
 };

 const formatHolidayDate = (value) => {
  const date = new Date(`${String(value).slice(0, 10)}T00:00:00`);

  return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleDateString("id-ID", { weekday: "long", day: "2-digit", month: "long", year: "numeric" });
 };

 const timeValueChanged = (left, right, field) => (left?.[field] || "") !== (right?.[field] || "");
 const baselineDays = baselineTimes?.days || null;
 const changedDayLabels = baselineTimes
  ? DAY_TIME_ROWS.filter((day) => ["entry", "late", "home"].some((field) => timeValueChanged(dayTimes[day.key], baselineDays?.[day.key], field)))
  : [];
 const timesDirty = changedDayLabels.length > 0;

 const resetTimeChanges = () => {
  if (!baselineTimes) return;
  setDayTimes(baselineTimes.days);
  setForm((f) => ({ ...f, entry: baselineTimes.entry, late: baselineTimes.late, home: baselineTimes.home }));
 };

 const requestSave = () => {
  if (saving || loading) return;

  // Tanpa perubahan jam, simpan langsung (mis. ubah MQTT atau semester).
  if (!timesDirty) {
   save();
   return;
  }

  const summary = changedDayLabels
   .map((day) => {
    const times = dayTimes[day.key];
    return `• ${day.label}: masuk ${times.entry}, terlambat ${times.late}, pulang ${times.home}`;
   })
   .join("\n");

  setConfirm({
   title: "Simpan & Restart Semua Alat?",
   message: `Jam absensi berikut akan diubah:\n\n${summary}\n\nSetelah disimpan:\n• Semua alat ESP32 restart otomatis (±3 detik).\n• Alat berbunyi sekali sebagai tanda jadwal baru diterima.\n• Layar alat menampilkan jam masuk, lalu berganti ke jam pulang saat waktunya tiba.\n\nPerubahan baru berlaku setelah tombol Simpan Perubahan ditekan.`,
   confirmLabel: "Simpan & Restart Alat",
   onConfirm: save,
  });
 };


 const save = async () => {
  if (saving) return;
  setSaving(true);
  setError("");
  try {
   const result = await import("../api/settingsApi").then((m) => m.updateSettings({
    entry_time: form.entry,
    late_time: form.late,
    home_time: form.home,
    timezone: form.timezone,
    academic_year: form.academicYear,
    semester: form.semester,
    // Kirim hanya hari yang jamnya berbeda dari jam umum; hari lain
    // otomatis memakai jam umum sebagai fallback.
    day_times: Object.fromEntries(DAY_TIME_ROWS
     .map((day) => {
      const times = dayTimes[day.key];
      return {
       key: day.key,
       entry: times.entry || form.entry,
       late: times.late || form.late,
       home: times.home || form.home,
      };
     })
     .filter((day) => day.entry !== form.entry || day.late !== form.late || day.home !== form.home)
     .map((day) => [day.key, {
      entry_time: day.entry,
      late_time: day.late,
      home_time: day.home,
     }])),
    mqtt_enabled: form.mqttEnabled,
    mqtt_broker: form.mqtt,
    mqtt_port: form.port,
    mqtt_tls: form.mqttTls,
    mqtt_username: form.mqttUser || null,
    mqtt_topic: form.topic,
    // Kirim password hanya bila Admin mengetik password baru. Dengan begitu
    // menyimpan pengaturan lain tidak menghapus password MQTT yang tersimpan.
    ...(form.mqttPass ? { mqtt_password: form.mqttPass } : {}),
   }));
   applySettings(result?.settings || {});
   setLogs((old) => [{ id: Date.now(), time: new Date().toLocaleTimeString("id-ID"), message: "Admin mengubah pengaturan sistem", detail: `Jam masuk ${form.entry}; batas terlambat ${form.late}; jam pulang ${form.home}.`, type: "settings" }, ...old].slice(0, 200));
   // Tampilkan dialog hasil simpan (bukan toast sekilas) agar Admin tahu
   // apakah perangkat sudah diminta restart dan bisa memeriksa statusnya.
   setSaveResult(result || { message: "Pengaturan berhasil disimpan ke server." });

   // Dialog selalu menampilkan info alat seperti versi lama. Saat jam tidak
   // berubah, backend tidak mengirim daftar perangkat, jadi statusnya
   // diambil langsung dari endpoint perangkat.
   if (result?.device_sync !== "restart_requested") {
    checkDevices();
   }
  } catch (e) {
   setError(e?.response?.data?.message || "Pengaturan gagal disimpan ke server.");
  } finally {
   setSaving(false);
  }
 };

 // Periksa ulang status perangkat: restart_requested_at kosong berarti
 // alat sudah mengonfirmasi restart dan memakai jam terbaru.
 const checkDevices = async () => {
  if (checkingDevices) return;
  setCheckingDevices(true);
  try {
   const response = await import("../api/deviceApi").then((m) => m.getDevices({ per_page: 1000 }));
   const list = Array.isArray(response) ? response : (response?.data || []);
   setSaveResult((previous) => (previous ? {
    ...previous,
    devices: list.map((device) => ({
     id: device.id,
     name: device.name,
     status: String(device.status || "Offline"),
     restart_pending: Boolean(device.restart_requested_at),
     last_seen_at: device.last_seen_at,
    })),
   } : previous));
  } catch {
   toast.error("Status perangkat gagal diperiksa. Coba lagi.");
  } finally {
   setCheckingDevices(false);
  }
 };

 return (
 <div className="page-stack">
 <PageHead
  title="Pengaturan Sistem"
  description="Konfigurasi aturan absensi dan preferensi tampilan dashboard."
  action={<button className="primary-button" onClick={requestSave} disabled={saving || loading}><Check size={17} />{saving ? "Menyimpan..." : "Simpan Perubahan"}</button>}
 />

 {error && <div className="log-notice"><Settings size={16}/><div><strong>Pengaturan gagal diproses</strong><span>{error}</span></div></div>}

 <div className="settings-grid">
  <section className="panel settings-section settings-attendance-section">
   <div className="settings-section-head">
    <div><span className="settings-kicker">ABSENSI</span><h3>Aturan Kehadiran</h3><p>Jam masuk, batas terlambat, dan jam pulang diatur per hari kerja (Senin–Jumat).</p></div>
    <ClipboardCheck size={20} />
   </div>
   <Setting label="Timezone" type="select" value={form.timezone} onChange={(v) => update("timezone", v)} options={["Asia/Jakarta","Asia/Makassar","Asia/Jayapura"]} />
   <div className="setting-inline-grid">
    <Setting label="Tahun Ajaran" value={form.academicYear} onChange={(v) => update("academicYear", v)} />
    <Setting label="Semester" type="select" value={form.semester} onChange={(v) => update("semester", v)} options={["Ganjil","Genap"]} />
   </div>
   <div className="settings-days-title">Jam Absensi per Hari</div>
   <div className="settings-days-head"><span>Hari</span><span>Jam Masuk</span><span>Batas Terlambat</span><span>Jam Pulang</span></div>
   {DAY_TIME_ROWS.map((day) => {
    const times = dayTimes[day.key];
    return <div className="settings-day-row" key={day.key}>
     <span className="settings-day-name">{day.label}</span>
     <label className="settings-day-field"><span>Masuk</span><input type="time" value={times.entry} aria-label={`Jam masuk ${day.label}`} onChange={(e) => updateDayTime(day.key, "entry", e.target.value)} /></label>
     <label className="settings-day-field"><span>Terlambat</span><input type="time" value={times.late} aria-label={`Batas terlambat ${day.label}`} onChange={(e) => updateDayTime(day.key, "late", e.target.value)} /></label>
     <label className="settings-day-field"><span>Pulang</span><input type="time" value={times.home} aria-label={`Jam pulang ${day.label}`} onChange={(e) => updateDayTime(day.key, "home", e.target.value)} /></label>
    </div>;
   })}
   <div className={`settings-restart-notice ${timesDirty ? "is-dirty" : ""}`}>
    <RefreshCw size={18} />
    <div>
     <strong>{timesDirty ? "Ada perubahan jam yang belum disimpan" : "Sinkronisasi jam ke alat"}</strong>
     <span>Setelah <b>Simpan Perubahan</b> ditekan, semua alat ESP32 <b>restart otomatis (±3 detik)</b>, <b>berbunyi sekali</b> sebagai tanda jadwal baru diterima, lalu layar menampilkan jam masuk dan berganti ke jam pulang saat waktunya tiba. Perubahan tidak berlaku sebelum disimpan.</span>
    </div>
   </div>
   <div className="setting-help">Tap setelah <strong>batas terlambat hari itu</strong> tercatat <strong>Terlambat</strong>; siswa tanpa tap sampai jam itu ditandai <strong>Alpa otomatis</strong> (dicek tiap 5 menit di hari kerja). Tap kedua setelah <strong>jam pulang hari itu</strong> dicatat sebagai <strong>jam pulang</strong>. Sabtu dan Minggu tidak memakai jadwal khusus. Alat ESP32 membaca jam hari ini dari server saat heartbeat, jadi perubahan langsung berlaku tanpa flash ulang.</div>
  </section>

  <section className="panel settings-section settings-holiday-section">
   <div className="settings-section-head">
    <div><span className="settings-kicker">KALENDER</span><h3>Hari Libur</h3><p>Tanggal yang terdaftar di sini tidak ditandai Alpa otomatis dan tidak diisi Alpa pada laporan.</p></div>
    <CalendarOff size={20} />
   </div>
   <div className="settings-holiday-toolbar">
    <label className="settings-holiday-year"><span>Tahun</span><input type="number" min="2000" max="2100" value={holidayYear} onChange={(e) => setHolidayYear(Number(e.target.value) || new Date().getFullYear())} /></label>
    <span className="settings-holiday-count">{holidays.length} tanggal libur</span>
   </div>
   <form className="settings-holiday-form" onSubmit={addHoliday}>
    <label><span>Tanggal</span><input type="date" value={holidayForm.date} onChange={(e) => setHolidayForm((f) => ({ ...f, date: e.target.value }))} required /></label>
    <label><span>Nama</span><input type="text" maxLength={120} placeholder="Contoh: Hari Raya Idul Fitri" value={holidayForm.name} onChange={(e) => setHolidayForm((f) => ({ ...f, name: e.target.value }))} required /></label>
    <label><span>Jenis</span><select value={holidayForm.type} onChange={(e) => setHolidayForm((f) => ({ ...f, type: e.target.value }))}><option value="nasional">Libur Nasional</option><option value="sekolah">Libur Sekolah</option><option value="semester">Libur Semester</option></select></label>
    <button className="primary-button" type="submit" disabled={holidayBusy}><Plus size={16} />{holidayBusy ? "Menyimpan..." : "Tambah"}</button>
   </form>
   {holidayError && <div className="setting-help setting-help-error">{holidayError}</div>}
   {holidays.length === 0
    ? <div className="setting-help">Belum ada hari libur untuk tahun {holidayYear}. Tambahkan tanggal merah atau libur sekolah agar siswa tidak ditandai Alpa.</div>
    : <div className="settings-holiday-list">{holidays.map((holiday) => <div className="settings-holiday-row" key={holiday.id}>
      <div className="settings-holiday-date"><strong>{formatHolidayDate(holiday.date)}</strong><small>{holiday.type_label || holiday.type}</small></div>
      <span className="settings-holiday-name">{holiday.name}</span>
      <button type="button" className="icon-button danger" onClick={() => removeHoliday(holiday)} aria-label={`Hapus ${holiday.name}`}><Trash2 size={15} /></button>
     </div>)}</div>}
   <div className="setting-help">Penandaan Alpa otomatis (<code>attendance:mark-alpa</code>) melewati tanggal ini. Jika sudah terlanjur ada Alpa otomatis pada tanggal libur, bersihkan dengan <code>php artisan attendance:clear-holiday-alpa</code>.</div>
  </section>

  <section className="panel settings-section">
   <div className="settings-section-head">
    <div><span className="settings-kicker">TAMPILAN</span><h3>Preferensi Dashboard</h3><p>Atur tampilan yang digunakan Admin.</p></div>
    <Sun size={20} />
   </div>
   <div className="theme-setting">
    <div><strong>Mode gelap</strong><small>Gunakan tampilan gelap untuk dashboard.</small></div>
    <button type="button" className={`switch ${darkMode ? "on" : ""}`} onClick={() => setDarkMode(!darkMode)}><i /></button>
   </div>
  </section>
 </div>

{timesDirty && <div className="settings-sticky-save" role="status">
   <div className="settings-sticky-text">
    <strong>Ada perubahan jam yang belum disimpan.</strong>
    <span>{changedDayLabels.map((day) => day.label).join(", ")} — semua alat akan restart dan berbunyi setelah disimpan.</span>
   </div>
   <div className="settings-sticky-actions">
    <button type="button" className="secondary-button" onClick={resetTimeChanges} disabled={saving}>Batalkan Perubahan</button>
    <button type="button" className="primary-button" onClick={requestSave} disabled={saving || loading}><Check size={17} />{saving ? "Menyimpan..." : "Simpan Perubahan"}</button>
   </div>
  </div>}

  {saveResult && <SettingsSavedModal result={saveResult} checking={checkingDevices} onCheck={checkDevices} onClose={() => setSaveResult(null)} onDevices={() => { setSaveResult(null); onNavigate?.("devices"); }} />}
 </div>
 );
}

/**
 * Dialog hasil simpan Pengaturan. Menampilkan pesan server dan status
 * perangkat: "Menunggu restart" sampai alat memakai jam terbaru, atau
 * "Sudah sinkron" setelah alat mengonfirmasi restart.
 */
function SettingsSavedModal({ result, checking, onCheck, onClose, onDevices }) {
 const devices = result?.devices || [];
 const deviceSync = result?.device_sync;
 const restartRequested = deviceSync === "restart_requested";
 const lastSeen = (value) => value ? ` • terakhir ${String(value).slice(0, 16).replace("T", " ")}` : "";
 const pollStartedAt = useRef(0);
 const restartPending = restartRequested && devices.some((device) => device.restart_pending);

 // Alat offline tidak mungkin sudah menerima jadwal baru, jadi jangan
 // diberi label "Sudah sinkron" hanya karena tidak ada restart tertunda.
 const syncState = (device) => {
  if (device.restart_pending) return { className: "waiting", label: "Menunggu restart" };
  if (String(device.status || "").toLowerCase() === "online") return { className: "online", label: "Sudah sinkron" };
  return { className: "offline", label: "Belum terhubung" };
 };

 useEffect(() => {
  pollStartedAt.current = Date.now();
 }, []);

 useEffect(() => {
  const onKeyDown = (event) => {
   if (event.key === "Escape") onClose();
  };
  document.addEventListener("keydown", onKeyDown);
  return () => document.removeEventListener("keydown", onKeyDown);
 }, [onClose]);

 // Periksa status alat otomatis tiap 3 detik sampai semua "Sudah sinkron"
 // atau batas 60 detik, supaya Admin tidak perlu klik berulang.
 useEffect(() => {
  if (!restartPending) return undefined;

  const timer = setInterval(() => {
   if (Date.now() - pollStartedAt.current > 60000) {
    clearInterval(timer);
    return;
   }
   onCheck?.();
  }, 3000);

  return () => clearInterval(timer);
 }, [restartPending, onCheck]);

 return <div className="modal-backdrop confirm-backdrop" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose(); }}>
  <div className="modal confirm-modal" role="dialog" aria-modal="true" aria-labelledby="settings-saved-title">
   <div className="confirm-icon primary"><Check size={20} /></div>
   <div className="confirm-content">
    <h3 id="settings-saved-title">Pengaturan Tersimpan</h3>
    <p>{result?.message || "Pengaturan berhasil disimpan ke server."}</p>
    <div className="settings-restart-modal">
     <RefreshCw size={16} />
     {restartRequested
      ? <span>Semua alat <b>restart otomatis (±3 detik)</b>, lalu <b>berbunyi sekali</b> sebagai tanda jadwal baru diterima. Layar alat akan menampilkan jam masuk, lalu berganti ke jam pulang saat waktunya tiba.</span>
      : deviceSync === "no_devices"
      ? <span>Jam absensi tersimpan, tetapi <b>belum ada perangkat terdaftar</b>. Tambahkan alat di menu Perangkat IoT agar jadwal ikut terkirim.</span>
      : <span>Tidak ada perubahan jam absensi, jadi alat <b>tidak di-restart</b> dan <b>tidak berbunyi</b>. Jadwal yang sudah tersimpan tetap dipakai.</span>}
    </div>
    <p className="settings-sync-note">Status sinkronisasi alat:</p>
    <div className="settings-sync-list">
     {devices.length ? devices.map((device) => {
      const sync = syncState(device);
      return <div className="settings-sync-row" key={device.id}>
       <div><strong>{device.name}</strong><small>{device.status}{lastSeen(device.last_seen_at)}</small></div>
       <span className={`status ${sync.className}`}>{sync.label}</span>
      </div>;
     }) : <p className="settings-sync-empty">Belum ada perangkat terdaftar. Tambahkan alat di menu Perangkat IoT.</p>}
    </div>
    <p className="settings-sync-note">Status diperiksa otomatis tiap 3 detik. {restartPending ? "Menunggu alat restart dan mengirim heartbeat..." : restartRequested ? "Alat yang online sudah memakai jadwal terbaru; alat offline akan menyusul saat terhubung." : "Alat yang online memakai jadwal yang berlaku saat ini."}</p>
   </div>
   <div className="modal-actions">
    <button type="button" className="text-button" style={{ marginRight: "auto" }} onClick={onDevices}>Buka Perangkat IoT</button>
    <button type="button" className="secondary-button" onClick={onCheck} disabled={checking}>{checking ? "Memeriksa..." : "Periksa Ulang"}</button>
    <button type="button" className="primary-button" onClick={onClose}>Tutup</button>
   </div>
  </div>
 </div>;
}


function Setting({label,type="text",value,onChange,options=[],placeholder=""}) { return <label className="setting"><span>{label}</span>{type==="select"?<select value={value} onChange={e=>onChange(e.target.value)}>{options.map(x=><option key={x}>{x}</option>)}</select>:<input type={type} value={value} placeholder={placeholder} onChange={e=>onChange(e.target.value)}/>}</label>; }
function PageHead({title,description,action}) { return <div className="page-head"><div><span className="eyebrow">PRESENTECH</span><h2>{title}</h2><p>{description}</p></div><div className="head-actions">{action}</div></div>; }

function Avatar({ profile, large = false }) { return profile.photo ? <img className={`profile-avatar-image ${large ? "large" : ""}`} src={profile.photo} alt="Foto profil" /> : <div className={`avatar ${large ? "large" : ""}`}>{profile.name?.charAt(0)?.toUpperCase() || "A"}</div>; }
function ProfileModal({ profile, setProfile, onClose, onSettings }) {
 const [form, setForm] = useState(profile);
 const [photoError, setPhotoError] = useState("");
 const [passwordOpen, setPasswordOpen] = useState(false);
 const [saving, setSaving] = useState(false);

  useEffect(() => {
    const onKeyDown = (event) => {
      if (event.key === "Escape" && !saving && !passwordOpen) onClose();
    };
    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  }, [onClose, saving, passwordOpen]);

 const choosePhoto = async (event) => {
 const file = event.target.files?.[0];
 if (!file) return;
 if (!file.type.startsWith("image/")) { setPhotoError("File harus berupa gambar."); return; }
 event.target.value = "";
 try {
 const dataUrl = await fileToAvatarBase64(file, 150 * 1024);
 setForm((f) => ({ ...f, photo: dataUrl }));
 setPhotoError("");
 } catch (error) {
 setPhotoError(error?.message || "Foto gagal diproses. Coba file gambar lain.");
 }
 };

 const save = async (event) => {
 event.preventDefault();
 if (saving) return;
 const next = {
 ...form,
 name: form.name.trim() || "Administrator",
 username: form.username.trim() || "admin",
 phone: form.phone.trim(),
 };
 setSaving(true);
 try {
 const result = await updateProfile({ name: next.name, phone: next.phone, avatar: next.photo || null });
 const user = result?.user || {};
 const saved = { ...next, name: user.name || next.name, phone: user.phone || next.phone, photo: user.avatar || "" };
 setProfile(saved);
 localStorage.setItem("presentechUser", JSON.stringify(user));
 onClose();
 } catch (error) {
 setPhotoError(error?.response?.data?.message || "Profil gagal disimpan ke database.");
 } finally {
 setSaving(false);
 }
 };

 return <div className="modal-backdrop">
 <form className="modal profile-edit-modal" onSubmit={save}>
 <div className="modal-head">
 <div><span className="eyebrow">AKUN</span><h3>Profil Administrator</h3><p>Informasi profil dan akun administrator.</p></div>
 <button type="button" className="icon-button" onClick={onClose}><X size={18} /></button>
 </div>

 <div className="profile-photo-editor">
 <Avatar profile={form} large />
 <div>
 <label className="secondary-button profile-upload"><input type="file" accept="image/*" onChange={choosePhoto} />Ubah Foto</label>
 {form.photo && <button type="button" className="text-button" onClick={() => setForm({ ...form, photo: "" })}>Hapus foto</button>}
 <span>PNG/JPG/WebP, maksimal 2 MB.</span>
 {photoError && <small className="field-error">{photoError}</small>}
 </div>
 </div>

 <div className="profile-section-title">Identitas Admin</div>
 <label className="setting"><span>Nama Lengkap</span><input value={form.name || ""} onChange={(e) => setForm({ ...form, name: e.target.value })} required /></label>
 <label className="setting"><span>Nomor Telepon</span><input value={form.phone || ""} onChange={(e) => setForm({ ...form, phone: e.target.value })} /></label>

 <div className="profile-section-title">Informasi Akun</div>
 <label className="setting"><span>Username</span><input value={form.username || "admin"} disabled /></label>
 <label className="setting"><span>Role</span><input value="Admin" disabled /></label>

 <div className="profile-section-title">Pengaturan</div>
 <button type="button" className="profile-password-row" onClick={onSettings}>
 <span><strong>Buka Pengaturan Sistem</strong><small>Aturan kehadiran, koneksi IoT, dan backup data.</small></span><span aria-hidden="true"></span>
 </button>

 <div className="profile-section-title">Keamanan</div>
 <button type="button" className="profile-password-row" onClick={() => setPasswordOpen(true)}>
 <span><strong>Ubah Password</strong><small>Perbarui password akun administrator.</small></span><span aria-hidden="true"></span>
 </button>

 <div className="modal-actions">
 <button type="button" className="secondary-button" onClick={onClose}>Batal</button>
 <button className="primary-button" type="submit" disabled={saving}><Check size={16} />{saving ? "Menyimpan..." : "Simpan Profil"}</button>
 </div>
 </form>
 {passwordOpen && <AdminPasswordChangeModal onClose={() => setPasswordOpen(false)} />}
 </div>;
}

function AdminPasswordChangeModal({ onClose }) {
 const [form, setForm] = useState({ current: "", next: "", confirm: "" });
 const [message, setMessage] = useState("");
 const submit = async (e) => {
 e.preventDefault();
 if (form.next.length < 8 || !/[a-zA-Z]/.test(form.next) || !/[0-9]/.test(form.next)) { setMessage("Password baru minimal 8 karakter dan mengandung huruf serta angka."); return; }
 if (form.next !== form.confirm) { setMessage("Konfirmasi password tidak sama."); return; }
 try {
 await changePassword(form.current, form.next);
 setMessage("Password berhasil diperbarui.");
 setTimeout(onClose, 900);
 } catch (error) {
 const errors = error?.response?.data?.errors;
 setMessage(errors?.current_password?.[0] || errors?.password?.[0] || error?.response?.data?.message || "Password gagal diperbarui.");
 }
 };
 return <div className="modal-backdrop nested-modal">
 <form className="modal" onSubmit={submit}>
 <div className="modal-head"><div><span className="eyebrow">SECURITY</span><h3>Ubah Password</h3></div><button type="button" className="icon-button" onClick={onClose}><X size={18} /></button></div>
 <label className="setting"><span>Password Saat Ini</span><input type="password" value={form.current} onChange={(e) => setForm({...form,current:e.target.value})} required /></label>
 <label className="setting"><span>Password Baru</span><input type="password" value={form.next} onChange={(e) => setForm({...form,next:e.target.value})} required /></label>
 <label className="setting"><span>Konfirmasi Password</span><input type="password" value={form.confirm} onChange={(e) => setForm({...form,confirm:e.target.value})} required /></label>
 {message && <small className="field-error">{message}</small>}
 <div className="modal-actions"><button type="button" className="secondary-button" onClick={onClose}>Batal</button><button className="primary-button">Simpan Password</button></div>
 </form>
 </div>;
}

function ConfirmModal({
 title = "Konfirmasi",
 message = "Apakah kamu yakin ingin melanjutkan?",
 confirmLabel,
 confirmText = "Konfirmasi",
 cancelText = "Batal",
 tone = "primary",
 input,
 copyable = false,
 chain = false,
 onConfirm,
 onCancel,
 onClose,
}) {
 const [busy, setBusy] = useState(false);
 const [copied, setCopied] = useState(false);
 const [note, setNote] = useState(typeof input === "string" ? input : "");
 const copyNote = async () => {
 try {
 await navigator.clipboard.writeText(note);
 setCopied(true);
 setTimeout(() => setCopied(false), 1800);
 } catch {
 // Clipboard API butuh HTTPS/localhost; pengguna tetap bisa menyalin manual.
 }
 };
 const handleCancel = async () => {
 if (busy) return;
 try {
 await onCancel?.();
 } finally {
 onClose?.();
 }
 };
 const handleConfirm = async () => {
 if (busy) return;
 setBusy(true);
 try {
 await onConfirm?.(typeof input === "string" ? note : undefined);
 // Dialog berantai (misal: konfirmasi -> dialog sukses) tidak boleh
 // ditutup di sini karena onClose bisa menghapus dialog penerus.
 if (!chain) onClose?.();
 } catch (error) {
 console.error(error);
 } finally {
 setBusy(false);
 }
 };

  useEffect(() => {
    const onKeyDown = (event) => {
      if (event.key === "Escape" && !busy) {
        event.preventDefault();
        handleCancel();
      }
    };
    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  });

 return (
 <div className="modal-backdrop confirm-backdrop" onMouseDown={(e) => {
 if (!busy && e.target === e.currentTarget) handleCancel();
 }}>
 <div className="modal confirm-modal" role="dialog" aria-modal="true" aria-labelledby="confirm-modal-title">
 <div className={`confirm-icon ${tone === "danger" ? "danger" : "primary"}`}>
 {tone === "danger" ? <Trash2 size={20} /> : <Check size={20} />}
 </div>
 <div className="confirm-content">
 <h3 id="confirm-modal-title">{title}</h3>
 <p style={{ whiteSpace: "pre-line" }}>{message}</p>
 {typeof input === "string" && (
 <div className="confirm-note-wrap">
 <textarea
 className={`confirm-note ${copyable ? "is-secret" : ""}`}
 value={note}
 readOnly={copyable}
 onChange={(e) => setNote(e.target.value)}
 placeholder={input}
 rows={3}
 onFocus={(e) => copyable && e.target.select()}
 />
 {copyable && (
 <button type="button" className="confirm-copy" onClick={copyNote}>
 {copied ? <Check size={14} /> : <Copy size={14} />}
 {copied ? "Tersalin" : "Salin"}
 </button>
 )}
 </div>
 )}
 </div>
 <div className="modal-actions">
 <button type="button" className="secondary-button" onClick={handleCancel} disabled={busy}>
 {cancelText}
 </button>
 <button type="button" className="primary-button" onClick={handleConfirm} disabled={busy}>
 {busy ? "Memproses..." : (confirmLabel || confirmText)}
 </button>
 </div>
 </div>
 </div>
 );
}

function NotificationPanel({ notifications, onRead, onReadAll }) {
 const [now, setNow] = useState(() => Date.now());
 // Detik berjalan supaya label "x detik yang lalu" ikut berubah tanpa perlu polling.
 useEffect(() => {
  const timer = setInterval(() => setNow(Date.now()), 1000);
  return () => clearInterval(timer);
 }, []);
 const usable = notifications.filter((item) => item.type !== "attendance");
 const visible = usable.slice(0, 20);
 return <div className="notification-panel"><div className="notification-head"><div><strong>Notifikasi</strong><small>{usable.filter((n) => !n.read).length} belum dibaca</small></div><button onClick={onReadAll}>Tandai semua dibaca</button></div><div className="notification-list">{visible.length ? visible.map((item) => <button key={item.id} className={`notification-item ${item.read ? "read" : "unread"}`} onClick={() => onRead(item.id)}><div className={`notification-icon ${item.type || "system"}`}>{item.type === "device" ? <Cpu size={14} /> : item.type === "rfid" ? <CreditCard size={14} /> : <Bell size={14} />}</div><span><strong>{item.title}</strong><small>{item.detail || item.message}</small><em>{item.createdAt ? relativeTime(item.createdAt, now) : "Baru saja"}</em></span>{!item.read && <i />}</button>) : <div className="notification-empty">Belum ada notifikasi.</div>}</div></div>;
}
function FormModal({type,item,onClose,onSave,classes=[],teachers=[]}) {
 const [form,setForm]=useState(item||getDefault(type));
 const [saving,setSaving]=useState(false);
 const selectedClass=classes.find(c=>String(c.id)===String(form.class_id||""));

  // Escape menutup modal (aksesibilitas keyboard), kecuali sedang menyimpan.
  useEffect(() => {
    const onKeyDown = (event) => {
      if (event.key === "Escape" && !saving) onClose();
    };
    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  }, [onClose, saving]);
 const set=(k,v)=>setForm(f=>({...f,[k]:v}));
 const submit=async e=>{e.preventDefault(); if(saving || !form.name?.trim())return; setSaving(true); try { await onSave(type,form); } finally { setSaving(false); }};
 return <div className="modal-backdrop"><form className="modal" role="dialog" aria-modal="true" aria-label={`${item ? "Edit" : "Tambah"} ${typeLabel(type)}`} onSubmit={submit} onFocusCapture={(event) => { if (event.target.tagName === "SELECT") event.target.scrollIntoView({ block: "center", behavior: "smooth" }); }}>
 <div className="modal-head"><div><h3>{item?"Edit":"Tambah"} {typeLabel(type)}</h3><p>Isi data dengan benar.</p></div><button type="button" className="icon-button" onClick={onClose}><X size={18}/></button></div>
 {type==="student" && <>
 <label className="setting"><span>NIS</span><input value={form.nis??""} onChange={e=>set("nis",e.target.value)} required/></label>
 <label className="setting"><span>No Absen</span><input type="number" min="1" max="999" value={form.no_absen??""} onChange={e=>set("no_absen",e.target.value)} placeholder="Opsional" /></label>
 <label className="setting"><span>Nama</span><input value={form.name??""} onChange={e=>set("name",e.target.value)} required/></label>
 <label className="setting"><span>Kelas</span><select value={form.class_id??""} onChange={e=>setForm(f=>({...f,class_id:e.target.value?Number(e.target.value):null,className:classes.find(c=>String(c.id)===e.target.value)?.name||"Kelas belum ditentukan"}))}><option value="">Kelas belum ditentukan</option>{classes.map(c=><option key={c.id} value={c.id}>{c.name}</option>)}</select></label>
 <label className="setting"><span>Jurusan</span><input value={selectedClass?.major || form.major || "-"} readOnly /></label>
 <label className="setting"><span>Status</span><select value={form.status||"Aktif"} onChange={e=>set("status",e.target.value)}><option>Aktif</option><option>Nonaktif</option><option>Lulus</option><option>Pindah</option></select></label>
 </>}
 {type==="teacher" && <>
 <label className="setting"><span>NIP</span><input value={form.nip??""} onChange={e=>set("nip",e.target.value)} required/></label>
 <label className="setting"><span>Nama</span><input value={form.name??""} onChange={e=>set("name",e.target.value)} required/></label>
 <label className="setting"><span>Mata Pelajaran</span><input value={form.subject??""} onChange={e=>set("subject",e.target.value)}/></label>
 <label className="setting"><span>Status</span><select value={form.status||"Aktif"} onChange={e=>set("status",e.target.value)}><option>Aktif</option><option>Nonaktif</option></select></label>
 </>}
 {type==="class" && <>
 <label className="setting"><span>Kelas</span><input value={form.name??""} onChange={e=>set("name",e.target.value)} placeholder="Contoh: XI RPL 1" required/></label>
 <label className="setting"><span>Jurusan</span><input value={form.major??""} onChange={e=>set("major",e.target.value)} placeholder="Contoh: RPL"/></label>
 <label className="setting"><span>Jumlah Siswa</span><input type="number" min="0" value={form.students??0} onChange={e=>set("students",Number(e.target.value))}/></label>
 <label className="setting"><span>Wali Kelas</span><select value={form.homeroom_teacher_id??""} onChange={e=>setForm(f=>({...f,homeroom_teacher_id:e.target.value?Number(e.target.value):null,teacher:teachers.find(t=>String(t.id)===e.target.value)?.name||""}))}><option value="">Belum ditentukan</option>{teachers.map(t=><option key={t.id} value={t.id}>{t.name}</option>)}</select></label>
 </>}
 {type==="device" && <>
 <label className="setting"><span>Device ID</span><input value={form.device_id??""} onChange={e=>set("device_id",e.target.value)} placeholder="Kosongkan untuk dibuat otomatis"/></label>
 <label className="setting"><span>Nama Perangkat</span><input value={form.name??""} onChange={e=>set("name",e.target.value)} required/></label>
 <label className="setting"><span>Lokasi</span><input value={form.location??""} onChange={e=>set("location",e.target.value)}/></label>
 <label className="setting"><span>Koneksi</span><select value={form.connection||"WiFi"} onChange={e=>set("connection",e.target.value)}><option>WiFi</option><option>Ethernet</option><option>Unknown</option></select></label>
 <label className="setting"><span>Status</span><select value={form.status||"online"} onChange={e=>set("status",e.target.value)}><option value="online">Online</option><option value="offline">Offline</option><option value="maintenance">Maintenance</option></select></label>
 <label className="setting"><span>Nama di Layar</span><input value={form.display_name??""} onChange={e=>set("display_name",e.target.value)} maxLength={32} placeholder="Kosongkan = nama perangkat" /></label>
 <label className="setting"><span>Label Sekolah</span><input value={form.school_label??""} onChange={e=>set("school_label",e.target.value)} maxLength={32} placeholder="Baris atas layar, mis. SMK PresenTech" /></label>
 <label className="setting"><span>Warna Tema Layar</span><input type="color" value={form.theme_color||"#1f6feb"} onChange={e=>set("theme_color",e.target.value)} /></label>
 </>}
 <div className="modal-actions"><button type="button" className="secondary-button" onClick={onClose} disabled={saving}>Batal</button><button className="primary-button" type="submit" disabled={saving}>{saving ? "Menyimpan..." : <><Check size={16}/>Simpan</>}</button></div>
 </form></div>;
}
function getDefault(type) { const id=Date.now(); if(type==="student") return {id,nis:"",name:"",no_absen:"",className:"Kelas belum ditentukan",class_id:null,uid:"",status:"Aktif"}; if(type==="teacher") return {id,nip:"",name:"",subject:""}; if(type==="class") return {id,name:"",major:"",students:0,teacher:"",homeroom_teacher_id:null}; return {id,name:"",location:"",connection:"WiFi",status:"online",display_name:"",school_label:"",theme_color:"#1f6feb"}; }
function typeLabel(type) { return ({student:"Siswa",teacher:"Guru",class:"Kelas",device:"Perangkat"})[type]; }
// Parser CSV RFC-4180: koma/newline di dalam kutip, escape "" dan BOM.
function parseCsvToRows(text) {
 const rows = [];
 let row = [];
 let field = "";
 let inQuotes = false;
 const source = String(text).replace(/^\ufeff/, "");
 for (let i = 0; i < source.length; i++) {
 const ch = source[i];
 if (inQuotes) {
 if (ch === '"') {
 if (source[i + 1] === '"') { field += '"'; i++; }
 else { inQuotes = false; }
 } else {
 field += ch;
 }
 continue;
 }
 if (ch === '"') {
 inQuotes = true;
 } else if (ch === ",") {
 row.push(field);
 field = "";
 } else if (ch === "\n") {
 row.push(field);
 field = "";
 rows.push(row);
 row = [];
 } else if (ch !== "\r") {
 field += ch;
 }
 }
 if (field !== "" || row.length) {
 row.push(field);
 rows.push(row);
 }
 return rows;
}

export default AdminDashboard;
