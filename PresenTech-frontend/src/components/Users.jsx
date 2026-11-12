import { ShieldCheck, GraduationCap, User } from "lucide-react";

const users = [
  {
    role: "Admin",
    icon: ShieldCheck,
    className: "user-admin",
    text: "Mengelola seluruh sistem, data siswa, kelas, pengguna, dan perangkat IoT.",
  },
  {
    role: "Guru",
    icon: GraduationCap,
    className: "user-guru",
    text: "Melihat kehadiran seluruh kelas yang menjadi tanggung jawabnya dan mengelola laporan.",
  },
  {
    role: "Siswa",
    icon: User,
    className: "user-siswa",
    text: "Melihat data dan statistik kehadiran dirinya sendiri.",
  },
];

function Users() {
  return (
    <section id="pengguna" className="section users-section">
      <div className="section-container">
        <div className="section-title" data-reveal>
          <p className="section-label">AKSES PENGGUNA</p>

          <h2>
            Setiap pengguna memiliki
            <br />
            akses yang berbeda.
          </h2>
        </div>

        <div className="users-grid">
          {users.map((user) => {
            const Icon = user.icon;

            return (
              <div className="user-card" key={user.role} data-reveal>
                <div className={`user-icon ${user.className}`}>
                  <Icon size={22} />
                </div>

                <span className="user-role">ROLE</span>

                <h3>{user.role}</h3>

                <p>{user.text}</p>
              </div>
            );
          })}
        </div>
      </div>
    </section>
  );
}

export default Users;
