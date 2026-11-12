import { useEffect, useRef, useState } from "react";
import { logout as apiLogout, changePassword, updateProfile, getCurrentUser } from "../api/authApi";
import { clearPresenTechSession } from "../api/session";
import DonutGauge from "../dashboard-admin/ui/DonutGauge";
import {
  Activity,
  Bell,
  CalendarDays,
  CalendarOff,
  CheckCircle2,
  ClipboardCheck,
  Download,
  GraduationCap,
  LayoutDashboard,
  LogOut,
  Menu,
  Moon,
  Search,
  Sun,
  UserRound,
  Users,
  X,
  Clock3,
  FileText,
  FileWarning,
  Send,
  CalendarRange,
} from "lucide-react";
import "./guru-dashboard.css";
import {
  attendancePercentage,
  fileToAvatarBase64,
  localDateKey,
  localDayName,
  relativeTime,
  statusClass,
  weekCalendar,
  WORK_DAYS,
} from "../lib/helpers";
import { exportAttendancePdf } from "../lib/pdf";
import { toast } from "../lib/toast";

function read(key, fallback) {
  try {
    const value = localStorage.getItem(key);
    return value ? JSON.parse(value) : fallback;
  } catch {
    return fallback;
  }
}

function GuruDashboard({ onNavigate, darkMode, setDarkMode }) {
  const [page, setPage] = useState("dashboard");
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [students, setStudents] = useState([]);
  const [attendance, setAttendance] = useState([]);
  const [schedule, setSchedule] = useState([]);
  const [corrections, setCorrections] = useState([]);
  const [loadError, setLoadError] = useState("");
  const [profile, setProfile] = useState(() => {
    const user = read("presentechUser", {});
    const teacher = user?.teacher || {};
    return {
      name: teacher.name || user.name || "",
      username: user.username || teacher.nip || "",
      nip: teacher.nip || user.username || "",
      subject: teacher.subject || "",
      phone: teacher.phone || user.phone || "",
      className: "",
      photo: user.avatar || "",
    };
  });
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
  const [todayHoliday, setTodayHoliday] = useState(null);

  const currentDate = localDateKey();
  const today = currentDate;
  const todayAttendance = attendance.filter((x) => x.date === today);
  const currentDay = localDayName();
  const hadir = todayAttendance.filter((x) => x.status === "Hadir").length;
  const terlambat = todayAttendance.filter((x) => x.status === "Terlambat").length;
  const total = students.length;
  const rate = attendancePercentage(hadir, terlambat, total);

  useEffect(() => {
    let cancelled=false;
    const load=async()=>{try{
      setLoadError("");
      const storedUser=JSON.parse(localStorage.getItem("presentechUser")||"{}");
      const me=await getCurrentUser();
      const user=me?.user || storedUser;
      localStorage.setItem("presentechUser", JSON.stringify(user));
      if (user?.teacher) setProfile(p=>({...p,name:user.teacher.name||user.name||p.name,username:user.username||user.teacher.nip||p.username,nip:user.teacher.nip||user.username||p.nip,subject:user.teacher.subject||"",phone:user.teacher.phone||user.phone||"",photo:user.avatar||p.photo}));
      const [stu,att,sch,hol]=await Promise.all([
        import("../api/studentApi").then(m=>m.getStudents({per_page:1000})),
        import("../api/attendanceApi").then(m=>m.getAttendances({per_page:1000})),
        import("../api/scheduleApi").then(m=>m.getSchedules({per_page:1000, teacher_id:user?.teacher?.id})),
        import("../api/holidayApi").then(m=>m.getTodayHoliday()).catch(()=>null)
      ]);
      if(cancelled)return; const arr=v=>Array.isArray(v)?v:(Array.isArray(v?.data)?v.data:[]);
      setTodayHoliday(hol?.is_holiday ? hol.holiday : null);
      const scheduleRows = arr(sch).map(x=>({id:x.id,day:x.day,time:String(x.start_time).slice(0,5),subject:x.subject,room:x.room||"",teacher:x.teacher?.name||"",className:x.school_class?.name||"",teacher_id:x.teacher_id,class_id:x.class_id}));
      const taughtClassIds = new Set(scheduleRows.map(x => String(x.class_id)).filter(Boolean));
      setSchedule(scheduleRows);
      setStudents(arr(stu).map(x=>({id:x.id,nis:x.nis,name:x.name,className:x.school_class?.name||"Kelas belum ditentukan",uid:x.rfid_cards?.[0]?.uid||"",class_id:x.class_id})).filter(x => taughtClassIds.has(String(x.class_id || ""))));
      setAttendance(arr(att).map(x=>({id:x.id,nis:x.student?.nis||"",name:x.student?.name||"",className:x.student?.school_class?.name||"Kelas belum ditentukan",date:String(x.attendance_date).slice(0,10),time:String(x.attendance_time).slice(0,5),checkOut:x.check_out_time?String(x.check_out_time).slice(0,5):"-",earlyLeave:Boolean(x.early_leave),notes:x.notes||"",status:x.status,class_id:x.student?.class_id})).filter(x => taughtClassIds.has(String(x.class_id || ""))));
      // Riwayat koreksi ikut dimuat saat awal agar panel tidak selalu kosong.
      try {
        const c=await import("../api/correctionApi").then(m=>m.getCorrections({per_page:100}));
        if(!cancelled) setCorrections(arr(c).map(x=>({id:x.id,nis:x.student?.nis||"",name:x.student?.name||"",date:x.attendance_date,time:String(x.expected_time||x.actual_time||"").slice(0,5),reason:x.reason,status:x.status==="PENDING"?"Menunggu":x.status==="APPROVED"?"Disetujui":"Ditolak"})));
      } catch { /* koreksi opsional; data utama tetap tampil */ }
    }catch(e){
      console.error("API Guru gagal dimuat",e);
      if(!cancelled) setLoadError(e?.response?.data?.message || "Data dashboard gagal dimuat. Pastikan API aktif lalu muat ulang halaman.");
    }};
    if(localStorage.getItem("presentechToken"))load(); return()=>{cancelled=true};
  }, []);
  useEffect(() => {
    localStorage.setItem("pt_guru_profile", JSON.stringify(profile));
  }, [profile]);
  useEffect(() => {
    const timer = setTimeout(() => setWelcomeToast(false), 4200);
    return () => clearTimeout(timer);
  }, []);
  useEffect(() => {
    let cancelled=false;
    const load=async()=>{try{const r=await import("../api/notificationApi").then(m=>m.getNotifications({per_page:50}));const arr=Array.isArray(r)?r:(r?.data||[]);if(!cancelled)setNotifications(arr.map(x=>({id:x.id,title:x.title,detail:x.message,type:x.type||"system",read:x.is_read,createdAt:new Date(x.created_at||Date.now()).getTime(),time:"Baru saja"})));}catch{/* polling notifikasi gagal; dicoba lagi otomatis */}}
    if(localStorage.getItem("presentechToken"))load();const timer=setInterval(load,10000);return()=>{cancelled=true;clearInterval(timer)};
  }, []);


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
    ["attendance", "Absensi", ClipboardCheck],
    ["corrections", "Koreksi Absensi", FileWarning],
    ["students", "Siswa", Users],
    ["schedule", "Jadwal", CalendarDays],
    ["reports", "Laporan", FileText],
  ];

  return (
    <div className={`role-dashboard ${darkMode ? "dark" : "light"}`}>
      {sidebarOpen && <button className="role-overlay" onClick={() => setSidebarOpen(false)} />}

      <aside className={`role-sidebar ${sidebarOpen ? "open" : ""}`}>
        <div className="role-brand">
          <button onClick={() => navigate("dashboard")} className="role-brand-button">
            <img src={darkMode ? `${import.meta.env.BASE_URL}logo-dark.png` : `${import.meta.env.BASE_URL}logo-light.png`} alt="PresenTech" />
            <span><strong>PresenTech</strong><small>GURU PANEL</small></span>
          </button>
          <button className="role-icon mobile-only" onClick={() => setSidebarOpen(false)}><X size={18} /></button>
        </div>

        <div className="role-section-title">MENU UTAMA</div>
        <nav>
          {menu.map(([id, label, Icon]) => (
            <button key={id} className={`role-menu-item ${page === id ? "active" : ""}`} onClick={() => navigate(id)}>
              <Icon size={18} />
              <span>{label}</span>
            </button>
          ))}
        </nav>

        <div className="role-sidebar-account"><div className="role-section-title">Akun</div>
          <button className="role-menu-item" onClick={() => setProfileOpen(true)}>
            <UserRound size={18} />
            <span>Profil</span>
          </button>
          <button className="role-logout" onClick={logout}>
            <LogOut size={18} />
            <span>Keluar</span>
          </button>
        </div>
      </aside>

      <section className="role-main">
        <header className="role-topbar">
          <div className="role-top-left">
            <button className="role-icon mobile-menu" onClick={() => setSidebarOpen(true)}><Menu size={20} /></button>
            <div>
              <h1>{page === "dashboard" ? "Dashboard" : page === "attendance" ? "Absensi" : page === "corrections" ? "Koreksi Absensi" : page === "students" ? "Siswa" : page === "schedule" ? "Jadwal" : "Laporan"}</h1>
              <p>Pantau kelas dan kehadiran siswa.</p>
            </div>
          </div>

          <div className="role-top-actions">
            <button className="role-icon" onClick={() => setDarkMode(!darkMode)}>
              {darkMode ? <Sun size={18} /> : <Moon size={18} />}
            </button>
            <div className="role-notification-wrap" ref={notificationWrapRef}>
              <button className="role-icon" onClick={() => setNotificationsOpen((v) => !v)} aria-label="Notifikasi"><Bell size={18} />{notifications.filter((n) => !n.read && n.type !== "attendance").length > 0 && <i>{notifications.filter((n) => !n.read && n.type !== "attendance").length}</i>}</button>
              {notificationsOpen && <RoleNotificationPanel notifications={notifications} onRead={async (id)=>{try{await import("../api/notificationApi").then(m=>m.markNotificationRead(id));setNotifications(old=>old.map(n=>n.id===id?{...n,read:true}:n));}catch{/* gagal di server: status baca tidak diubah */} setNotificationsOpen(false);}} onReadAll={async()=>{try{await import("../api/notificationApi").then(m=>m.markAllNotificationsRead());setNotifications(old=>old.map(n=>({...n,read:true})));}catch{/* gagal di server: status baca tidak diubah */}}} />}
            </div>
            <div className="role-profile-button" title="Profil">
              <span className="role-avatar-wrap">{profile.photo ? <img src={profile.photo} alt="Foto profil" /> : <span className="role-avatar">{profile.name.charAt(0).toUpperCase()}</span>}</span>
              <span className="role-profile-text"><strong>{profile.name}</strong><small>Guru</small></span>
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
          {page === "dashboard" && (
            <GuruOverview
              profileName={profile.name}
              students={students}
              attendance={attendance}
              today={todayAttendance}
              total={total}
              hadir={hadir}
              terlambat={terlambat}
              rate={rate}
              todayHoliday={todayHoliday}
              schedule={schedule.filter((item) => item.day === currentDay)}
              onNavigate={navigate}
            />
          )}

          {page === "attendance" && <GuruAttendance data={attendance} />}
          {page === "corrections" && <GuruCorrections students={students} attendance={attendance} corrections={corrections} setCorrections={setCorrections} />}
          {page === "students" && <GuruStudents students={students} attendance={attendance} />}
          {page === "schedule" && <GuruSchedule schedule={schedule} />}
          {page === "reports" && <GuruReports attendance={attendance} />}

          {profileOpen && <GuruProfile profile={profile} setProfile={setProfile} onClose={() => setProfileOpen(false)} />}
        </main>
        {welcomeToast && page === "dashboard" && <div className="role-welcome-toast"><CheckCircle2 size={16} /><div><strong>Selamat datang kembali</strong><span>Dashboard Guru PresenTech siap digunakan.</span></div><button onClick={() => setWelcomeToast(false)}><X size={14} /></button></div>}
      </section>
    </div>
  );
}

function GuruOverview({ profileName, attendance, today, total, hadir, terlambat, rate, todayHoliday, schedule, onNavigate }) {
  return (
    <div className="role-stack">
      <section className="role-welcome">
        <div>
          <span className="role-eyebrow">GURU PRESENTECH</span>
          <h2>Selamat datang, {profileName || "Guru"}</h2>
          <p>Pantau kehadiran kelas yang Anda ajar hari ini.</p>
        </div>
        <GraduationCap size={32} />
      </section>

      {todayHoliday && (
        <div className="role-holiday-banner">
          <CalendarOff size={18} />
          <div>
            <strong>Hari ini libur: {todayHoliday.name}</strong>
            <span>Alpa otomatis tidak dijalankan. Tap kartu tetap dapat dicatat.</span>
          </div>
        </div>
      )}

      <div className="role-stat-grid">
        <RoleStat icon={Users} value={total} label="Total Siswa" />
        <RoleStat icon={CheckCircle2} value={hadir} label="Hadir" />
        <RoleStat icon={Clock3} value={terlambat} label="Terlambat" />
        <RoleStat icon={Activity} value={todayHoliday ? "Libur" : `${rate}%`} label="Kehadiran" />
      </div>

      <div className="role-two-col">
        <section className="role-panel">
          <RoleHeader title="Kehadiran Hari Ini" action="Lihat semua" onClick={() => onNavigate("attendance")} />
          <div className="attendance-progress">
            <DonutGauge value={todayHoliday ? null : rate} title="Persentase Kehadiran Hari Ini" caption={todayHoliday ? "Hari libur" : "Hari ini"} size={136} stroke={12} />
            {!todayHoliday && (
              <div className="attendance-summary">
                <div><span>Hadir</span><strong>{hadir}</strong></div>
                <div><span>Terlambat</span><strong>{terlambat}</strong></div>
                <div><span>Belum Absen</span><strong>{Math.max(0, total - today.length)}</strong></div>
              </div>
            )}
          </div>
        </section>

        <section className="role-panel">
          <RoleHeader title="Jadwal Hari Ini" action="Lihat jadwal" onClick={() => onNavigate("schedule")} />
          {schedule.map((item) => (
            <div className="schedule-row" key={`${item.time}-${item.subject}`}>
              <span className="schedule-time">{item.time}</span>
              <div><strong>{item.subject}</strong><small>{item.room}</small></div>
            </div>
          ))}
        </section>
      </div>

      <section className="role-panel quick-card">
        <div className="quick-card-head">
          <div>
            <h3>Pintasan Cepat</h3>
            <p>Ajukan koreksi absensi atau lihat data kehadiran kelas.</p>
          </div>
          <ClipboardCheck size={20} />
        </div>
        <div className="quick-actions-grid">
          <button type="button" className="quick-action primary" onClick={() => onNavigate("corrections")}>
            <span className="quick-icon"><CheckCircle2 size={18} /></span>
            <span>Koreksi Absensi<small>Ajukan perubahan data kehadiran</small></span>
          </button>
          <button type="button" className="quick-action" onClick={() => onNavigate("attendance")}>
            <span className="quick-icon"><Activity size={18} /></span>
            <span>Data Absensi<small>Pantau kehadiran siswa hari ini</small></span>
          </button>
        </div>
      </section>

      <section className="role-panel">
        <RoleHeader title="Absensi Terbaru" action="Buka absensi" onClick={() => onNavigate("attendance")} />
        <AttendanceTable data={today.slice(0, 6)} />
      </section>
      <div className="advanced-grid">
        <section className="role-panel"><RoleHeader title="Aktivitas Absensi" action="Monitoring" onClick={() => onNavigate("attendance")} /><div className="live-feed">{today.slice(0,4).map((item)=><div className="live-item" key={item.id}><i className="live-dot"/><div><strong>{item.name}</strong><small>{item.className} · {item.time} · {item.status}</small></div></div>)}</div></section>
        <section className="role-panel"><RoleHeader title="Kalender Kehadiran"/><GuruCalendar data={attendance}/></section>
      </div>
    </div>
  );
}

function RoleStat({ icon: Icon, value, label }) {
  return <div className="role-stat"><div className="role-stat-icon"><Icon size={19} /></div><strong>{value}</strong><span>{label}</span></div>;
}

function RoleHeader({ title, action, onClick }) {
  return <div className="role-panel-header"><h3>{title}</h3>{action && <button onClick={onClick}>{action}</button>}</div>;
}

function AttendanceTable({ data }) {
  return (
    <div className="role-table-wrap">
      <table>
        <thead><tr><th>NIS</th><th>Nama</th><th>Kelas</th><th>Jam Masuk</th><th>Jam Pulang</th><th>Status</th><th>Keterangan</th></tr></thead>
        <tbody>
          {data.map((item) => (
            <tr key={item.id}>
              <td>{item.nis}</td>
              <td><strong>{item.name}</strong></td>
              <td>{item.className}</td>
              <td>{item.time}</td>
              <td>{item.checkOut || "-"}{item.earlyLeave ? " (izin)" : ""}</td>
              <td><span className={`role-status ${statusClass(item.status)}`}>{item.status}</span></td>
              <td>{item.notes || "-"}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function GuruAttendance({ data }) {
  const [query, setQuery] = useState("");
  const [status, setStatus] = useState("Semua");
  const filtered = data.filter((x) => `${x.nis} ${x.name}`.toLowerCase().includes(query.toLowerCase()) && (status === "Semua" || x.status === status));

  return (
    <div className="role-stack">
      <RolePageHead title="Absensi Kelas" description="Pantau kehadiran siswa di kelas yang Anda ajar." />
      <div className="role-toolbar">
        <div className="role-search"><Search size={16} /><input placeholder="Cari nama atau NIS..." value={query} onChange={(e) => setQuery(e.target.value)} /></div>
        <select value={status} onChange={(e) => setStatus(e.target.value)}><option>Semua</option><option>Hadir</option><option>Terlambat</option><option>Izin</option><option>Sakit</option><option>Alpa</option></select>
      </div>
      <section className="role-panel"><AttendanceTable data={filtered} /></section>
    </div>
  );
}

function GuruCorrections({ students, attendance, corrections, setCorrections }) {
  const [form, setForm] = useState({ nis: "", name: "", date: localDateKey(), time: "07:00", attendanceId: "", reason: "" });
  const [savingCorrection, setSavingCorrection] = useState(false);
  const [submitError, setSubmitError] = useState("");
  const studentAttendance = attendance.filter((x) => String(x.nis) === String(form.nis));
  const resetForm = () => setForm({ nis: "", name: "", date: localDateKey(), time: "07:00", attendanceId: "", reason: "" });
  const submit = async (e) => {
    e.preventDefault();
    if (savingCorrection || !form.nis || !form.reason) return;
    const student = students.find((x) => String(x.nis) === String(form.nis));
    if (!student?.id) {
      setSubmitError("NIS tidak ditemukan di daftar siswa yang Anda ajar.");
      return;
    }
    setSavingCorrection(true);
    setSubmitError("");
    try {
      await import("../api/correctionApi").then(m => m.createCorrection({
        student_id: student.id,
        attendance_id: form.attendanceId ? Number(form.attendanceId) : null,
        attendance_date: form.date,
        expected_time: `${form.time}:00`,
        reason: form.reason,
      }));
      resetForm();
      // Muat ulang SELURUH riwayat, bukan hanya milik siswa yang baru
      // diajukan — kalau tidak, daftar koreksi siswa lain hilang dari panel.
      const r = await import("../api/correctionApi").then(m => m.getCorrections({ per_page: 100 }));
      const arr = Array.isArray(r) ? r : (r?.data || []);
      setCorrections(arr.map(x => ({ id:x.id, nis:x.student?.nis || "", name:x.student?.name || "", date:x.attendance_date, time:String(x.expected_time||x.actual_time||"").slice(0,5), reason:x.reason, status:x.status==="PENDING"?"Menunggu":x.status==="APPROVED"?"Disetujui":"Ditolak" })));
    } catch (error) {
      console.error(error);
      setSubmitError(error?.response?.data?.message || "Pengajuan koreksi gagal dikirim.");
    } finally {
      setSavingCorrection(false);
    }
  };
  return <div className="role-stack"><RolePageHead title="Koreksi Absensi" description="Ajukan dan pantau koreksi data kehadiran siswa." action={<span className="role-eyebrow"><CalendarRange size={14}/> AUDIT TERJAGA</span>} /><section className="role-panel"><div className="role-panel-header"><div><h3>Ajukan Koreksi</h3><p>Perubahan tidak langsung menimpa data absensi.</p></div><Send size={19}/></div><form className="correction-form" onSubmit={submit}><label>NIS<input value={form.nis} onChange={e=>{const nis=e.target.value;const student=students.find(x=>String(x.nis)===nis);const latest=attendance.filter(x=>String(x.nis)===nis).sort((a,b)=>`${b.date} ${b.time}`.localeCompare(`${a.date} ${a.time}`))[0];setForm({...form,nis,name:student?.name||"",attendanceId:latest?.id?String(latest.id):"",date:latest?.date||form.date,time:latest?.time||form.time});}} required /></label><label>Nama Siswa<input value={form.name} onChange={e=>setForm({...form,name:e.target.value})} required /></label><label className="correction-full">Data Absensi<select value={form.attendanceId} onChange={e=>{const picked=studentAttendance.find(x=>String(x.id)===e.target.value);setForm({...form,attendanceId:e.target.value,date:picked?.date||form.date,time:picked?.time||form.time});}}><option value="">Belum ada / buat data baru</option>{studentAttendance.map(x=><option key={x.id} value={x.id}>{x.date} · {x.time} · {x.status}</option>)}</select></label><label>Tanggal<input type="date" value={form.date} onChange={e=>setForm({...form,date:e.target.value})} required /></label><label>Waktu Seharusnya<input type="time" value={form.time} onChange={e=>setForm({...form,time:e.target.value})} required /></label><label className="correction-full">Alasan<textarea value={form.reason} onChange={e=>setForm({...form,reason:e.target.value})} placeholder="Jelaskan alasan koreksi..." required /></label>{submitError && <div className="correction-full role-error-banner"><strong>Pengajuan gagal</strong><span>{submitError}</span></div>}<div className="correction-full"><button className="role-primary" type="submit" disabled={savingCorrection}><Send size={15}/> {savingCorrection ? "Mengirim..." : "Kirim Pengajuan"}</button></div></form></section><section className="role-panel"><RoleHeader title="Riwayat Pengajuan"/><div className="live-feed">{corrections.length ? corrections.map(x=><div className="live-item" key={x.id}><div><strong>{x.name} · {x.nis}</strong><small>{x.date} · {x.time} · {x.reason}</small></div><b className={`role-status ${x.status === "Disetujui" ? "hadir" : x.status === "Ditolak" ? "alpa" : "late"}`}>{x.status}</b></div>) : <div className="empty">Belum ada pengajuan koreksi.</div>}</div></section></div>;
}

function GuruCalendar({ data }) { return <div className="advanced-calendar">{weekCalendar(data).map((day)=><div key={day.date} className={`calendar-cell ${day.status === "Hadir" ? "present" : day.status === "Terlambat" ? "late" : ""}`}><strong>{day.label}</strong><span>{day.status}</span></div>)}</div>; }

function GuruStudents({ students, attendance }) {
  const [query, setQuery] = useState("");
  const filtered = students.filter((x) => `${x.name} ${x.nis}`.toLowerCase().includes(query.toLowerCase()));

  return (
    <div className="role-stack">
      <RolePageHead title="Siswa" description="Daftar siswa kelas yang Anda ajar." />
      <div className="role-toolbar"><div className="role-search"><Search size={16} /><input placeholder="Cari siswa..." value={query} onChange={(e) => setQuery(e.target.value)} /></div></div>
      <section className="role-panel">
        <div className="role-table-wrap">
          <table><thead><tr><th>NIS</th><th>Nama</th><th>Kelas</th><th>RFID UID</th><th>Kehadiran</th></tr></thead>
          <tbody>
            {filtered.length ? filtered.map((student) => {
              const todayRecord = attendance.find((x) => x.nis === student.nis && x.date === localDateKey());
              return <tr key={student.id}><td>{student.nis}</td><td><strong>{student.name}</strong></td><td>{student.className}</td><td>{student.uid || "-"}</td><td>{todayRecord ? <span className={`role-status ${statusClass(todayRecord.status)}`}>{todayRecord.status}</span> : <span className="role-status">Belum absen</span>}</td></tr>;
            }) : <tr><td colSpan="5" className="empty">Data siswa tidak ditemukan.</td></tr>}
          </tbody></table>
        </div>
      </section>
    </div>
  );
}

function GuruSchedule({ schedule }) {
  const todayName = localDayName();
  const days = WORK_DAYS;
  return (
    <div className="role-stack">
      <RolePageHead title="Jadwal" description="Jadwal mengajar yang dikelola Admin." />
      <section className="role-panel">
        <div className="weekly-schedule">
          {days.map((day) => {
            const items = schedule.filter((item) => item.day === day);
            return <div className={`day-card ${day === todayName ? "today" : ""}`} key={day}>
              <strong>{day}</strong><small>{day === todayName ? "Hari ini" : "Jadwal"}</small>
              {items.length ? items.map((item) => <div className="day-item" key={item.id || item.time}><b>{item.time}</b><span>{item.subject}</span><small>{item.room}{item.className ? ` · ${item.className}` : ""}</small></div>) : <span className="no-schedule">Tidak ada jadwal</span>}
            </div>;
          })}
        </div>
      </section>
    </div>
  );
}

function GuruReports({ attendance }) {
  const exportCsv = () => {
    const rows = attendance.map((x) => [x.nis, x.name, x.className, x.date, x.time, x.status]);
    const csv = [["NIS", "Nama", "Kelas", "Tanggal", "Waktu", "Status"], ...rows].map((r) => r.map((v) => `"${String(v).replaceAll('"', '""')}"`).join(",")).join("\n");
    const blob = new Blob(["\ufeff" + csv], { type: "text/csv;charset=utf-8" });
    const link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = "laporan-kelas-presentech.csv";
    link.click();
    URL.revokeObjectURL(link.href);
  };

  const exportPdf = async () => {
    try {
      await exportAttendancePdf({
        filename: "laporan-kelas-presentech.pdf",
        title: "Laporan Kehadiran Kelas",
        meta: ["Total data: " + attendance.length],
        rows: attendance,
      });
    } catch {
      toast.error("Export PDF gagal dibuat. Coba lagi.");
    }
  };

  return (
    <div className="role-stack">
      <RolePageHead title="Laporan" description="Rekap kehadiran kelas." action={<div className="head-actions-inline"><button className="role-secondary" onClick={exportCsv}><Download size={16} /> Export CSV</button><button className="role-primary" onClick={exportPdf}><Download size={16} /> Export PDF</button></div>} />
      <div className="role-stat-grid"><RoleStat icon={ClipboardCheck} value={attendance.length} label="Data Absensi" /><RoleStat icon={CheckCircle2} value={attendance.filter(x => x.status === "Hadir").length} label="Hadir" /></div>
      <section className="role-panel"><RoleHeader title="Preview Laporan" /><AttendanceTable data={attendance} /></section>
    </div>
  );
}

function RolePageHead({ title, description, action }) {
  return <div className="role-page-head"><div><span className="role-eyebrow">GURU</span><h2>{title}</h2><p>{description}</p></div>{action}</div>;
}

function GuruProfile({ profile, setProfile, onClose }) {
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
  const save = async (event) => {
    event.preventDefault();
    if (saving) return;
    setSaving(true);
    try {
      const result = await updateProfile({ name: form.name, phone: form.phone, avatar: form.photo || null });
      const user = result?.user || {};
      const next = { ...form, name:user.name || form.name, phone:user.phone || form.phone, photo:user.avatar || "" };
      setProfile(next);
      localStorage.setItem("presentechUser", JSON.stringify(user));
      onClose();
    } catch (error) {
      setError(error?.response?.data?.message || "Profil gagal disimpan ke database.");
    } finally { setSaving(false); }
  };

  return <div className="role-modal-backdrop" onClick={onClose}>
    <form className="role-modal role-profile-modal" onSubmit={save} onClick={(e) => e.stopPropagation()}>
      <div className="role-modal-head">
        <div><span className="role-eyebrow">AKUN</span><h2>Profil Guru</h2></div>
        <button type="button" className="role-icon" onClick={onClose}><X size={18} /></button>
      </div>

      <div className="role-photo-editor">
        <div className="role-avatar-wrap large">{form.photo ? <img src={form.photo} alt="Foto profil" /> : <span className="role-avatar">{(form.name || "G").charAt(0).toUpperCase()}</span>}</div>
        <div>
          <label className="role-secondary role-upload"><input type="file" accept="image/*" onChange={choosePhoto} />Ubah Foto</label>
          {form.photo && <button type="button" className="role-link" onClick={() => setForm({ ...form, photo: "" })}>Hapus foto</button>}
          <small>PNG/JPG/WebP — otomatis dikompres maksimal 2 MB.</small>
          {error && <small className="role-error">{error}</small>}
        </div>
      </div>

      <div className="role-profile-section-title">Identitas Guru</div>
      <label>Nama Lengkap<input value={form.name || ""} disabled /></label>
      <label>NIP<input value={form.nip || form.username || ""} disabled /></label>
      <label>Mata Pelajaran<input value={form.subject || ""} disabled /></label>
      <label>Nomor Telepon<input value={form.phone || ""} onChange={(e) => setForm({ ...form, phone: e.target.value })} /></label>

      <div className="role-profile-section-title">Informasi Akun</div>
      <label>Username / NIP<input value={form.username || form.nip || ""} disabled /></label>
      <label>Role<input value="Guru" disabled /></label>

      <div className="role-profile-section-title">Keamanan</div>
      <button type="button" className="role-password-row" onClick={() => setPasswordOpen(true)}>
        <span><strong>Ubah Password</strong><small>Perbarui password akun kamu.</small></span><span aria-hidden="true">→</span>
      </button>

      <div className="role-modal-actions">
        <button type="button" className="role-secondary" onClick={onClose}>Batal</button>
        <button className="role-primary" disabled={saving}>{saving ? "Menyimpan..." : "Simpan Profil"}</button>
      </div>
    </form>
    {passwordOpen && <PasswordChangeModal onClose={() => setPasswordOpen(false)} />}
  </div>;
}

function PasswordChangeModal({ onClose }) {
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
  return <div className="role-modal-backdrop role-modal-nested" onClick={onClose}>
    <form className="role-modal" onSubmit={submit} onClick={(e) => e.stopPropagation()}>
      <div className="role-modal-head"><div><span className="role-eyebrow">KEAMANAN</span><h2>Ubah Password</h2></div><button type="button" className="role-icon" onClick={onClose}><X size={18} /></button></div>
      <label>Password Saat Ini<input type="password" value={form.current} onChange={(e) => setForm({...form,current:e.target.value})} required /></label>
      <label>Password Baru<input type="password" value={form.next} onChange={(e) => setForm({...form,next:e.target.value})} required /></label>
      <label>Konfirmasi Password<input type="password" value={form.confirm} onChange={(e) => setForm({...form,confirm:e.target.value})} required /></label>
      {message && <small className="role-error">{message}</small>}
      <div className="role-modal-actions"><button type="button" className="role-secondary" onClick={onClose}>Batal</button><button className="role-primary">Simpan Password</button></div>
    </form>
  </div>;
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

export default GuruDashboard;
