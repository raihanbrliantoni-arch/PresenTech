import { useEffect, useRef, useState } from "react";
import { logout as apiLogout, changePassword, updateProfile, getCurrentUser } from "../api/authApi";
import { clearPresenTechSession } from "../api/session";
import DonutGauge from "../dashboard-admin/ui/DonutGauge";
import {
  Bell,
  CalendarDays,
  CheckCircle2,
  Clock3,
  ClipboardCheck,
  LayoutDashboard,
  LogOut,
  Menu,
  Moon,
  Search,
  Sun,
  UserRound,
  X,
  BookOpen,
  FileWarning,
  Send,
  CalendarRange,
} from "lucide-react";
import "./siswa-dashboard.css";
import {
  attendancePercentage,
  fileToAvatarBase64,
  localDateKey,
  localDayName,
  MAJOR_PLACEHOLDER,
  relativeTime,
  statusClass,
  weekCalendar,
  WORK_DAYS,
} from "../lib/helpers";

function read(key, fallback) {
  try {
    const value = localStorage.getItem(key);
    if (!value) return fallback;
    const parsed = JSON.parse(value);
    return parsed == null ? fallback : parsed;
  } catch {
    return fallback;
  }
}

function readObject(key, fallback) {
  const value = read(key, fallback);
  return value && typeof value === "object" && !Array.isArray(value) ? value : fallback;
}

function normalizeProfile(value) {
  const loggedUser = readObject("presentechUser", {});
  const student = loggedUser?.student || {};
  const schoolClass = student?.school_class || student?.schoolClass || {};

  return {
    id: student?.id || loggedUser?.student?.id || "",
    name:
      typeof value?.name === "string" && value.name.trim()
        ? value.name
        : student?.name || loggedUser?.name || "",
    nis:
      typeof value?.nis === "string" && value.nis.trim()
        ? value.nis
        : student?.nis || loggedUser?.username || "",
    className:
      typeof value?.className === "string" && value.className.trim()
        ? value.className
        : schoolClass?.name || "Kelas belum ditentukan",
    jurusan:
      typeof value?.jurusan === "string" && value.jurusan.trim()
        ? value.jurusan
        : student?.major || schoolClass?.major || MAJOR_PLACEHOLDER,
    username:
      typeof value?.username === "string" && value.username.trim()
        ? value.username
        : loggedUser?.username || student?.nis || "",
    email: "",
    photo:
      typeof value?.photo === "string"
        ? value.photo
        : loggedUser?.avatar || "",
  };
}

function SiswaDashboard({ onNavigate, darkMode, setDarkMode }) {
  const [page, setPage] = useState("dashboard");
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [profile, setProfile] = useState(() =>
    normalizeProfile(readObject("pt_siswa_profile", {}))
  );
  const [profileOpen, setProfileOpen] = useState(false);
  const [notificationsOpen, setNotificationsOpen] = useState(false);
  const notificationWrapRef = useRef(null);

  // Klik di luar dropdown atau tekan Escape langsung menutup panel notifikasi.
  useEffect(() => {
    if (!notificationsOpen) return undefined;
    const onPointerDown = (event) => {
      if (!notificationWrapRef.current?.contains(event.target)) setNotificationsOpen(false);
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
  const [notifications, setNotifications] = useState([]);
  const [attendance, setAttendance] = useState([]);
  const [schedule, setSchedule] = useState([]);
  const [corrections, setCorrections] = useState([]);
  const [loadError, setLoadError] = useState("");

  useEffect(() => {
    let cancelled=false;
    const load=async()=>{try{
      setLoadError("");
      const storedUser=JSON.parse(localStorage.getItem("presentechUser")||"{}");
      const me=await getCurrentUser();
      const user=me?.user || storedUser;
      localStorage.setItem("presentechUser",JSON.stringify(user));
      const student=user?.student; if(!student?.id)return;
      const [att,sch,corr,notifs]=await Promise.all([
        import("../api/attendanceApi").then(m=>m.getAttendances({student_id:student.id,per_page:1000})),
        student?.class_id ? import("../api/scheduleApi").then(m=>m.getSchedules({class_id:student.class_id,per_page:1000})) : Promise.resolve({data:[]}),
        import("../api/correctionApi").then(m=>m.getCorrections({student_id:student.id,per_page:100})),
        import("../api/notificationApi").then(m=>m.getNotifications({per_page:50}))
      ]);
      if(cancelled)return; const arr=v=>Array.isArray(v)?v:(Array.isArray(v?.data)?v.data:[]); const u=user;
      localStorage.setItem("presentechUser",JSON.stringify(u)); setProfile(normalizeProfile({name:u?.student?.name||u.name,nis:u?.student?.nis||u.username,className:u?.student?.school_class?.name||"Kelas belum ditentukan",jurusan:u?.student?.school_class?.major||MAJOR_PLACEHOLDER,username:u.username,photo:u.avatar||""}));
      setAttendance(arr(att).map(x=>({id:x.id,nis:x.student?.nis||student.nis,name:x.student?.name||student.name,className:x.student?.school_class?.name||"Kelas belum ditentukan",date:String(x.attendance_date).slice(0,10),time:String(x.attendance_time).slice(0,5),checkOut:x.check_out_time?String(x.check_out_time).slice(0,5):"-",earlyLeave:Boolean(x.early_leave),status:x.status,subject:x.schedule?.subject||""})));
      setSchedule(arr(sch).map(x=>({id:x.id,day:x.day,time:String(x.start_time).slice(0,5),subject:x.subject,room:x.room||"",className:x.school_class?.name||""})));
      setCorrections(arr(corr).map(x=>({id:x.id,date:String(x.attendance_date).slice(0,10),time:String(x.expected_time||x.actual_time||"").slice(0,5),reason:x.reason,status:x.status==="PENDING"?"Menunggu":x.status==="APPROVED"?"Disetujui":"Ditolak"})));
      setNotifications(arr(notifs).map(x=>({id:x.id,title:x.title,detail:x.message,read:x.is_read,type:x.type||"system",createdAt:new Date(x.created_at||Date.now()).getTime(),time:"Baru saja"})));
    }catch(e){
      console.error("API Siswa gagal dimuat",e);
      if(!cancelled) setLoadError(e?.response?.data?.message || "Data dashboard gagal dimuat. Pastikan API aktif lalu muat ulang halaman.");
    }};
    if(localStorage.getItem("presentechToken"))load(); return()=>{cancelled=true};
  }, []);
  useEffect(() => localStorage.setItem("pt_siswa_profile", JSON.stringify(profile)), [profile]);
  useEffect(() => {
    let cancelled=false;
    const load=async()=>{try{const r=await import("../api/notificationApi").then(m=>m.getNotifications({per_page:50}));const arr=Array.isArray(r)?r:(r?.data||[]);if(!cancelled)setNotifications(arr.map(x=>({id:x.id,title:x.title,detail:x.message,type:x.type||"system",read:x.is_read,createdAt:new Date(x.created_at||Date.now()).getTime(),time:"Baru saja"})));}catch{/* polling notifikasi gagal; dicoba lagi otomatis */}}
    if(localStorage.getItem("presentechToken"))load();const timer=setInterval(load,10000);return()=>{cancelled=true;clearInterval(timer)};
  }, []);

  useEffect(() => { const timer = setTimeout(() => setWelcomeToast(false), 4200); return () => clearTimeout(timer); }, []);

  const currentDate = localDateKey();
  const todayDate = currentDate;
  const today = attendance.find((x) => x.date === todayDate);
  const currentDay = localDayName();
  const present = attendance.filter((x) => x.status === "Hadir").length;
  const late = attendance.filter((x) => x.status === "Terlambat").length;
  // Persentase kehadiran hanya menghitung Hadir + Terlambat;
  // Izin/Sakit/Alpa tidak dihitung sebagai hadir.
  const total = attendance.length;
  const rate = attendancePercentage(present, late, total);

  const navigate = (target) => {
    setPage(target);
    setSidebarOpen(false);
  };

  const logout = async () => {
    try { await apiLogout(); } catch { /* sesi sudah tidak valid; tetap bersihkan lokal */ }
    clearPresenTechSession();
    onNavigate?.("login");
  };

  const menu = [
    ["dashboard", "Dashboard", LayoutDashboard],
    ["attendance", "Kehadiran", ClipboardCheck],
    ["corrections", "Koreksi Absensi", FileWarning],
    ["schedule", "Jadwal", CalendarDays],
  ];

  return (
    <div className={`role-dashboard ${darkMode ? "dark" : "light"}`}>
      {sidebarOpen && <button className="role-overlay" onClick={() => setSidebarOpen(false)} />}
      <aside className={`role-sidebar ${sidebarOpen ? "open" : ""}`}>
        <div className="role-brand">
          <button onClick={() => navigate("dashboard")} className="role-brand-button">
            <img src={darkMode ? `${import.meta.env.BASE_URL}logo-dark.png` : `${import.meta.env.BASE_URL}logo-light.png`} alt="PresenTech" />
            <span><strong>PresenTech</strong><small>SISWA PANEL</small></span>
          </button>
          <button className="role-icon mobile-only" onClick={() => setSidebarOpen(false)}><X size={18} /></button>
        </div>

        <div className="role-section-title">MENU UTAMA</div>
        <nav>
          {menu.map(([id, label, Icon]) => (
            <button key={id} className={`role-menu-item ${page === id ? "active" : ""}`} onClick={() => navigate(id)}>
              <Icon size={18} /><span>{label}</span>
            </button>
          ))}
        </nav>

        <div className="role-sidebar-account"><div className="role-section-title">Akun</div>
          <button className="role-menu-item" onClick={() => setProfileOpen(true)}><UserRound size={18} /><span>Profil</span></button>
          <button className="role-logout" onClick={logout}><LogOut size={18} /><span>Keluar</span></button>
        </div>
      </aside>

      <section className="role-main">
        <header className="role-topbar">
          <div className="role-top-left">
            <button className="role-icon mobile-menu" onClick={() => setSidebarOpen(true)}><Menu size={20} /></button>
            <div><h1>{page === "dashboard" ? "Dashboard" : page === "attendance" ? "Kehadiran" : page === "corrections" ? "Koreksi Absensi" : "Jadwal"}</h1><p>Lihat informasi kehadiran dan jadwal kamu.</p></div>
          </div>
          <div className="role-top-actions">
            <button className="role-icon" onClick={() => setDarkMode(!darkMode)}>{darkMode ? <Sun size={18} /> : <Moon size={18} />}</button>
            <div className="role-notification-wrap" ref={notificationWrapRef}>
              <button className="role-icon" onClick={() => setNotificationsOpen((v) => !v)} aria-label="Notifikasi"><Bell size={18} />{notifications.filter((n) => !n.read && n.type !== "attendance").length > 0 && <i>{notifications.filter((n) => !n.read && n.type !== "attendance").length}</i>}</button>
              {notificationsOpen && <RoleNotificationPanel notifications={notifications} onRead={async(id)=>{try{await import("../api/notificationApi").then(m=>m.markNotificationRead(id));setNotifications(old=>old.map(n=>n.id===id?{...n,read:true}:n));}catch{/* gagal di server: status baca tidak diubah */} setNotificationsOpen(false);}} onReadAll={async()=>{try{await import("../api/notificationApi").then(m=>m.markAllNotificationsRead());setNotifications(old=>old.map(n=>({...n,read:true})));}catch{/* gagal di server: status baca tidak diubah */}}} />}
            </div>
            <div className="role-profile-button" title="Profil">
              <span className="role-avatar-wrap">{profile.photo ? <img src={profile.photo} alt="Foto profil" /> : <span className="role-avatar">{profile.name.charAt(0).toUpperCase()}</span>}</span>
              <span className="role-profile-text"><strong>{profile.name}</strong><small>{profile.className}</small></span>
            </div>
          </div>
        </header>

        <main className="role-content">
          {loadError && (
            <div className="role-error-banner">
              <strong>Data gagal dimuat</strong>
              <span>{loadError}</span>
            </div>
          )}
          {page === "dashboard" && <SiswaOverview profile={profile} today={today} rate={rate} present={present} late={late} attendance={attendance} schedule={schedule.filter((item) => item.day === currentDay)} onNavigate={navigate} />}
          {page === "attendance" && <SiswaAttendance data={attendance} />}
          {page === "corrections" && <SiswaCorrections profile={profile} attendance={attendance} corrections={corrections} setCorrections={setCorrections} />}
          {page === "schedule" && <SiswaSchedule schedule={schedule} className={profile.className} />}
          {profileOpen && <SiswaProfile profile={profile} setProfile={setProfile} onClose={() => setProfileOpen(false)} />}
        </main>
        {welcomeToast && page === "dashboard" && <div className="role-welcome-toast"><CheckCircle2 size={16} /><div><strong>Selamat datang kembali</strong><span>Dashboard Siswa PresenTech siap digunakan.</span></div><button onClick={() => setWelcomeToast(false)}><X size={14} /></button></div>}
      </section>
    </div>
  );
}

function SiswaOverview({ profile, today, rate, present, late, attendance, schedule, onNavigate }) {
  return (
    <div className="role-stack">
      <section className="role-welcome">
        <div><span className="role-eyebrow">SISWA PRESENTECH</span><h2>Halo, {profile.name}</h2><p>{profile.className}</p></div>
        <BookOpen size={32} />
      </section>

      <section className={`today-card ${today?.status === "Hadir" ? "present" : today?.status === "Terlambat" ? "late" : "waiting"}`}>
        <div><span>STATUS KEHADIRAN HARI INI</span><strong>{today?.status || "Belum Absen"}</strong><p>{today?.time && today.time !== "-" ? `${today.time} WIB` : "Silakan lakukan absensi RFID."}</p></div>
        <CheckCircle2 size={42} />
      </section>

      <div className="role-stat-grid">
        <RoleStat icon={ClipboardCheck} value={`${rate}%`} label="Kehadiran" />
        <RoleStat icon={CheckCircle2} value={present} label="Hadir" />
        <RoleStat icon={Clock3} value={late} label="Terlambat" />
        <RoleStat icon={CalendarDays} value={attendance.length} label="Total Data" />
      </div>

      <div className="role-two-col">
        <section className="role-panel">
          <RoleHeader title="Riwayat Terbaru" action="Lihat semua" onClick={() => onNavigate("attendance")} />
          <SiswaHistory data={attendance.slice(0, 5)} />
        </section>

        <section className="role-panel">
          <RoleHeader title="Jadwal Hari Ini" action="Jadwal" onClick={() => onNavigate("schedule")} />
          {schedule.map((item) => <div className="schedule-row" key={item.time}><span className="schedule-time">{item.time}</span><div><strong>{item.subject}</strong><small>{item.room}</small></div></div>)}
        </section>
      </div>
      <div className="summary-row">
        <section className="role-panel gauge-card">
          <DonutGauge value={rate} title="Persentase Kehadiran" caption="Keseluruhan" size={136} stroke={12} />
          <div className="gauge-copy">
            <h3>Persentase Kehadiran</h3>
            <p>Rekap kehadiran Anda dari seluruh data absensi yang tercatat.</p>
            <div className="gauge-legend">
              <span><i />Hadir: {present}</span>
              <span><i className="muted" />Terlambat: {late}</span>
            </div>
          </div>
        </section>

        <section className="role-panel quick-card">
          <div className="quick-card-head">
            <div>
              <h3>Pintasan Cepat</h3>
              <p>Ajukan koreksi jika data kehadiran tidak sesuai.</p>
            </div>
            <FileWarning size={20} />
          </div>
          <div className="quick-actions-grid">
            <button type="button" className="quick-action primary" onClick={() => onNavigate("corrections")}>
              <span className="quick-icon"><FileWarning size={18} /></span>
              <span>Koreksi Absensi<small>Ajukan perubahan data kehadiran</small></span>
            </button>
            <button type="button" className="quick-action" onClick={() => onNavigate("attendance")}>
              <span className="quick-icon"><ClipboardCheck size={18} /></span>
              <span>Riwayat Kehadiran<small>Lihat semua catatan absensi</small></span>
            </button>
          </div>
        </section>
      </div>

      <section className="role-panel"><RoleHeader title="Kalender Kehadiran" action="Koreksi" onClick={() => onNavigate("corrections")} /><SiswaCalendar data={attendance}/></section>
    </div>
  );
}

function RolePageHead({ title, description, action }) {
  return (
    <div className="role-page-head">
      <div>
        <span className="role-eyebrow">SISWA</span>
        <h2>{title}</h2>
        <p>{description}</p>
      </div>
      {action}
    </div>
  );
}

function RoleStat({ icon: Icon, value, label }) {
  return <div className="role-stat"><div className="role-stat-icon"><Icon size={19} /></div><strong>{value}</strong><span>{label}</span></div>;
}

function RoleHeader({ title, action, onClick }) {
  return <div className="role-panel-header"><h3>{title}</h3>{action && <button onClick={onClick}>{action}</button>}</div>;
}

function SiswaHistory({ data }) {
  if (!data.length) return <div className="empty">Belum ada data kehadiran.</div>;
  return <div className="student-history">{data.map((item) => <div className="history-row" key={item.id}><div><strong>{item.date}</strong><small>{item.subject || "Absensi"}</small></div><span>{item.time}{item.checkOut && item.checkOut !== "-" ? ` → ${item.checkOut}` : ""}</span><b className={`role-status ${statusClass(item.status)}`}>{item.status}</b></div>)}</div>;
}

function SiswaCalendar({ data }) { return <div className="advanced-calendar">{weekCalendar(data).map((day)=><div key={day.date} className={`calendar-cell ${day.status === "Hadir" ? "present" : day.status === "Terlambat" ? "late" : ""}`}><strong>{day.label}</strong><span>{day.status}</span></div>)}</div>; }

function SiswaCorrections({ profile, attendance, corrections, setCorrections }) {
  const [form,setForm]=useState({date:localDateKey(),time:"07:00",attendanceId:"",reason:""});
  const [saving,setSaving]=useState(false);
  const [deleting,setDeleting]=useState(null);
  const [submitError,setSubmitError]=useState("");
  const reload=async()=>{
    const r=await import("../api/correctionApi").then(m=>m.getCorrections({student_id:profile.id,per_page:100}));
    const arr=Array.isArray(r)?r:(r?.data||[]);
    setCorrections(arr.map(x=>({
      id:x.id,date:String(x.attendance_date).slice(0,10),time:String(x.expected_time||x.actual_time||"").slice(0,5),
      reason:x.reason,status:x.status==="PENDING"?"Menunggu":x.status==="APPROVED"?"Disetujui":"Ditolak"
    })));
  };
  const submit=async e=>{
    e.preventDefault();
    if(saving || !form.reason) return;
    if(!profile.id) { setSubmitError("Profil siswa belum termuat. Muat ulang halaman lalu coba lagi."); return; }
    setSaving(true);
    setSubmitError("");
    try {
      await import("../api/correctionApi").then(m=>m.createCorrection({
        student_id:Number(profile.id),
        attendance_id:form.attendanceId?Number(form.attendanceId):null,
        attendance_date:form.date,
        expected_time:`${form.time}:00`,
        reason:form.reason
      }));
      await reload();
      setForm({date:localDateKey(),time:"07:00",attendanceId:"",reason:""});
    } catch(error) {
      console.error(error);
      setSubmitError(error?.response?.data?.message || "Pengajuan koreksi gagal dikirim.");
    }
    finally { setSaving(false); }
  };
  const cancel=async id=>{
    if(deleting) return;
    setDeleting(id);
    setSubmitError("");
    try {
      await import("../api/correctionApi").then(m=>m.deleteCorrection(id));
      await reload();
    } catch(error) {
      setSubmitError(error?.response?.data?.message || "Pengajuan gagal dibatalkan.");
    } finally { setDeleting(null); }
  };
  return <div className="role-stack"><RolePageHead title="Koreksi Absensi" description="Ajukan perubahan jika data kehadiran tidak sesuai." action={<span className="role-eyebrow"><CalendarRange size={14}/> PENGAJUAN</span>} /><section className="role-panel"><div className="role-panel-header"><div><h3>Ajukan Koreksi</h3><p>NIS dan nama mengikuti profil akun.</p></div><Send size={19}/></div><form className="correction-form" onSubmit={submit}><label className="correction-full">Data Absensi<select value={form.attendanceId} onChange={e=>{const picked=attendance.find(x=>String(x.id)===e.target.value);setForm({...form,attendanceId:e.target.value,date:picked?.date||form.date,time:picked?.time||form.time});}}><option value="">Belum ada / buat data baru</option>{attendance.map(x=><option key={x.id} value={x.id}>{x.date} · {x.time} · {x.status}</option>)}</select></label><label>Tanggal<input type="date" value={form.date} onChange={e=>setForm({...form,date:e.target.value})} required/></label><label>Waktu Seharusnya<input type="time" value={form.time} onChange={e=>setForm({...form,time:e.target.value})} required/></label><label className="correction-full">Alasan<textarea value={form.reason} onChange={e=>setForm({...form,reason:e.target.value})} placeholder="Jelaskan kesalahan data absensi..." required/></label>{submitError && <div className="correction-full role-error-banner"><strong>Pengajuan gagal</strong><span>{submitError}</span></div>}<div className="correction-full"><button className="role-primary" type="submit" disabled={saving}><Send size={15}/> {saving ? "Mengirim..." : "Kirim Pengajuan"}</button></div></form></section><section className="role-panel"><RoleHeader title="Riwayat Pengajuan"/><div className="live-feed">{corrections.length?corrections.map(x=><div className="live-item" key={x.id}><div><strong>{x.date} · {x.time}</strong><small>{x.reason}</small></div><div className="correction-status-group"><b className={`role-status ${x.status === "Disetujui" ? "hadir" : x.status === "Ditolak" ? "alpa" : "late"}`}>{x.status}</b>{x.status === "Menunggu" && <button type="button" className="role-link" onClick={()=>cancel(x.id)} disabled={deleting===x.id}>{deleting===x.id ? "Membatalkan..." : "Batalkan"}</button>}</div></div>):<div className="empty">Belum ada pengajuan.</div>}</div></section></div>;
}


function SiswaAttendance({ data }) {
  const [query, setQuery] = useState("");
  const filtered = data.filter((x) => `${x.date} ${x.subject} ${x.status}`.toLowerCase().includes(query.toLowerCase()));

  return (
    <div className="role-stack">
      <RolePageHead title="Kehadiran Saya" description="Riwayat absensi pribadi." />
      <div className="role-toolbar"><div className="role-search"><Search size={16} /><input placeholder="Cari tanggal, pelajaran, atau status..." value={query} onChange={(e) => setQuery(e.target.value)} /></div></div>
      <section className="role-panel"><SiswaHistory data={filtered} /></section>
    </div>
  );
}

function SiswaSchedule({ schedule, className }) {
  const days = WORK_DAYS;
  const todayName = localDayName();
  return (
    <div className="role-stack">
      <RolePageHead title="Jadwal" description="Jadwal pelajaran yang dikelola Admin." />
      <section className="role-panel">
        <div className="weekly-schedule">
          {days.map((day) => {
            const items = schedule.filter((item) => item.day === day && (!item.className || item.className === className));
            return <div className={`day-card ${day === todayName ? "today" : ""}`} key={day}>
              <strong>{day}</strong><small>{day === todayName ? "Hari ini" : "Jadwal"}</small>
              {items.length ? items.map((item) => <div className="day-item" key={item.id || item.time}><b>{item.time}</b><span>{item.subject}</span><small>{item.room}</small></div>) : <span className="no-schedule">Tidak ada jadwal</span>}
            </div>;
          })}
        </div>
      </section>
    </div>
  );
}

function SiswaProfile({ profile, setProfile, onClose }) {
  const [form, setForm] = useState(profile);
  const [error, setError] = useState("");
  const [passwordOpen, setPasswordOpen] = useState(false);
  const choosePhoto = async (event) => {
    const file = event.target.files?.[0];
    if (!file) return;
    if (!file.type.startsWith("image/")) { setError("File harus berupa gambar."); return; }
    event.target.value = "";
    try {
      const dataUrl = await fileToAvatarBase64(file, 150 * 1024);
      setForm({ ...form, photo: dataUrl });
      setError("");
    } catch (error) {
      setError(error?.message || "Foto gagal diproses. Coba file gambar lain.");
    }
  };
  const [saving, setSaving] = useState(false);
  const save = async (e) => {
    e.preventDefault();
    if (saving) return;
    setSaving(true);
    try {
      const result = await updateProfile({ name: form.name, avatar: form.photo || null });
      const user = result?.user || {};
      const next = normalizeProfile({name:user?.student?.name || user.name, nis:user?.student?.nis || user.username, className:user?.student?.school_class?.name || "Kelas belum ditentukan", jurusan:user?.student?.school_class?.major || MAJOR_PLACEHOLDER, username:user.username, photo:user.avatar || ""});
      setProfile(next);
      localStorage.setItem("presentechUser", JSON.stringify(user));
      onClose();
    } catch (error) {
      setError(error?.response?.data?.message || "Profil gagal disimpan ke database.");
    } finally { setSaving(false); }
  };
  return <div className="role-modal-backdrop" onClick={onClose}>
    <form className="role-modal role-profile-modal" onSubmit={save} onClick={(e) => e.stopPropagation()}>
      <div className="role-modal-head"><div><span className="role-eyebrow">AKUN</span><h2>Profil Siswa</h2></div><button type="button" className="role-icon" onClick={onClose}><X size={18} /></button></div>
      <div className="role-photo-editor"><div className="role-avatar-wrap large">{form.photo ? <img src={form.photo} alt="Foto profil" /> : <span className="role-avatar">{form.name.charAt(0).toUpperCase()}</span>}</div><div><label className="role-secondary role-upload"><input type="file" accept="image/*" onChange={choosePhoto} />Ubah Foto</label>{form.photo && <button type="button" className="role-link" onClick={() => setForm({ ...form, photo: "" })}>Hapus foto</button>}<small>PNG/JPG/WebP — otomatis dikompres maksimal 2 MB.</small>{error && <small className="role-error">{error}</small>}</div></div>
      <div className="role-profile-section-title">Identitas Siswa</div>
      <label>Nama Lengkap<input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required /></label>
      <label>NIS<input value={form.nis} disabled /></label>
      <label>Kelas<input value={form.className} disabled /></label>
      <label>Jurusan<input value={form.jurusan} disabled /></label>
      <div className="role-profile-section-title">Informasi Akun</div>
      <label>Username / NIS<input value={form.username} disabled /></label>
      <div className="role-profile-section-title">Keamanan</div>
      <button type="button" className="role-password-row" onClick={() => setPasswordOpen(true)}><span><strong>Ubah Password</strong><small>Perbarui password akun kamu.</small></span><span aria-hidden="true">→</span></button>
      <div className="role-modal-actions"><button type="button" className="role-secondary" onClick={onClose}>Batal</button><button className="role-primary" disabled={saving}>{saving ? "Menyimpan..." : "Simpan Profil"}</button></div>
    </form>
    {passwordOpen && <PasswordChangeModal onClose={() => setPasswordOpen(false)} />}
  </div>;
}

function PasswordChangeModal({ onClose }) {
  const [form, setForm] = useState({ current: "", next: "", confirm: "" });
  const [message, setMessage] = useState("");
  const submit = async (e) => { e.preventDefault(); if (form.next.length < 8 || !/[a-zA-Z]/.test(form.next) || !/[0-9]/.test(form.next)) { setMessage("Password baru minimal 8 karakter dan mengandung huruf serta angka."); return; } if (form.next !== form.confirm) { setMessage("Konfirmasi password tidak sama."); return; } try {
      await changePassword(form.current, form.next);
      setMessage("Password berhasil diperbarui.");
      setTimeout(onClose, 900);
    } catch (error) {
      const errors = error?.response?.data?.errors;
      setMessage(errors?.current_password?.[0] || errors?.password?.[0] || error?.response?.data?.message || "Password gagal diperbarui.");
    } };
  return <div className="role-modal-backdrop role-modal-nested" onClick={onClose}><form className="role-modal" onSubmit={submit} onClick={(e) => e.stopPropagation()}>      <div className="role-modal-head"><div><span className="role-eyebrow">KEAMANAN</span><h2>Ubah Password</h2></div><button type="button" className="role-icon" onClick={onClose}><X size={18} /></button></div><label>Password Saat Ini<input type="password" value={form.current} onChange={(e) => setForm({ ...form, current: e.target.value })} required /></label><label>Password Baru<input type="password" value={form.next} onChange={(e) => setForm({ ...form, next: e.target.value })} required /></label><label>Konfirmasi Password<input type="password" value={form.confirm} onChange={(e) => setForm({ ...form, confirm: e.target.value })} required /></label>{message && <div className="role-password-message">{message}</div>}<div className="role-modal-actions"><button type="button" className="role-secondary" onClick={onClose}>Batal</button><button className="role-primary">Simpan Password</button></div></form></div>;
}

function RoleNotificationPanel({ notifications, onRead, onReadAll }) {
  const [now, setNow] = useState(() => Date.now());
  // Detik berjalan supaya label "x detik yang lalu" ikut berubah tanpa polling.
  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(timer);
  }, []);
  const visible = notifications.filter((n) => n.type !== "attendance").slice(0, 20);
  return <div className="role-notification-panel"><div className="role-notification-head"><div><strong>Notifikasi</strong><small>{notifications.filter((n) => !n.read && n.type !== "attendance").length} belum dibaca</small></div><button onClick={onReadAll}>Tandai semua dibaca</button></div><div className="role-notification-list">{visible.length ? visible.map((n) => <button key={n.id} className={`role-notification-item ${n.read ? "read" : "unread"}`} onClick={() => onRead(n.id)}><div className="role-notification-icon"><Bell size={14} /></div><span><strong>{n.title}</strong><small>{n.detail || n.message}</small><em>{n.createdAt ? relativeTime(n.createdAt, now) : "Baru saja"}</em></span>{!n.read && <i />}</button>) : <div className="notification-empty">Belum ada notifikasi.</div>}</div></div>;
}

export default SiswaDashboard;
