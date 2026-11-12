import { useEffect, useState } from "react";
import { login as loginApi } from "../api/authApi";
import { ArrowRight, Cpu, CreditCard, Eye, EyeOff, LayoutDashboard, Moon, Sun } from "lucide-react";

function LoginPage({ onNavigate, darkMode, setDarkMode }) {
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  // Panaskan koneksi ke API saat halaman login dibuka agar TCP handshake
  // sudah siap ketika tombol Masuk ditekan.
  useEffect(() => {
    import("../api/healthApi").then((m) => m.checkApiHealth()).catch(() => {});
  }, []);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError("");

    const user = username.trim();
    const pass = password;

    if (!user) {
      setError("NIS / NIP wajib diisi.");
      return;
    }

    if (!pass) {
      setError("Password wajib diisi.");
      return;
    }

    try {
      setLoading(true);

      const result = await loginApi(user, pass);

      if (!result?.success || !result?.token || !result?.user?.role) {
        throw new Error("Respons login dari server tidak valid.");
      }

      localStorage.setItem("presentechToken", result.token);
      localStorage.setItem("presentechRole", result.user.role);
      localStorage.setItem("presentechUser", JSON.stringify(result.user));

      if (result.user.role === "siswa") {
        const student = result.user.student;
        const schoolClass = student?.school_class || student?.schoolClass;

        localStorage.setItem(
          "pt_siswa_profile",
          JSON.stringify({
            name: student?.name || result.user.name || "",
            nis: student?.nis || user,
            className: schoolClass?.name || "Kelas belum ditentukan",
            jurusan: student?.major || schoolClass?.major || "Rekayasa Perangkat Lunak",
            username: result.user.username || student?.nis || user,
            email: "",
            photo: result.user.avatar || "",
          })
        );
      }

      if (onNavigate) {
        if (result.user.role === "admin") {
          onNavigate("admin");
        } else if (result.user.role === "guru") {
          onNavigate("guru");
        } else if (result.user.role === "siswa") {
          onNavigate("siswa");
        }
      }
    } catch (err) {
      console.error("Login error:", err);

      const validationMessage = err.response?.data?.errors?.identifier?.[0];

      setError(
        !err.response
          ? "Tidak dapat terhubung ke server PresenTech. Periksa koneksi lalu coba lagi."
          : err.response.status === 429
            ? "Terlalu banyak percobaan login. Tunggu beberapa menit lalu coba lagi."
            : err.response.status === 428
              ? err.response.data?.message || "Password sementara wajib diganti."
              : validationMessage ||
                err.response.data?.message ||
                "NIS / NIP atau password salah."
      );
    } finally {
      setLoading(false);
    }
  };

  const handleBack = () => {
    if (onNavigate) {
      onNavigate("home");
    }
  };

  return (
    <div className="auth-page">
      <header className="auth-header">
        <button type="button" className="auth-brand" onClick={handleBack}>
          <div className="auth-brand-logo">
            <img
              src={
                darkMode
                  ? `${import.meta.env.BASE_URL}logo-dark.png`
                  : `${import.meta.env.BASE_URL}logo-light.png`
              }
              alt="PresenTech"
            />
          </div>
          <div className="auth-brand-text">
            <strong>PresenTech</strong>
            <span>SMART ATTENDANCE SYSTEM</span>
          </div>
        </button>

        <button
          type="button"
          className="auth-theme"
          onClick={() => setDarkMode(!darkMode)}
          aria-label="Ganti tema"
        >
          {darkMode ? <Sun size={18} /> : <Moon size={18} />}
        </button>
      </header>

      <main className="auth-main">
        <section className="auth-stage">
          <aside className="auth-aside" aria-hidden="true">
            <span className="auth-kicker">RFID · ESP32 · Dashboard</span>

            <h1>
              Satu kartu, satu catatan kehadiran.
            </h1>

            <p className="auth-aside-copy">
              Kartu ditempel di gerbang, perangkat mencatat, dan dashboard
              menampilkan hasilnya untuk admin, guru, serta siswa.
            </p>

            <ol className="auth-flow">
              <li>
                <span className="auth-flow-icon"><CreditCard size={15} /></span>
                <span>
                  <strong>Pembaca RFID</strong>
                  <small>Kartu siswa terbaca kurang dari satu detik.</small>
                </span>
              </li>
              <li>
                <span className="auth-flow-icon"><Cpu size={15} /></span>
                <span>
                  <strong>Perangkat ESP32</strong>
                  <small>Mengirim catatan kehadiran ke server sekolah.</small>
                </span>
              </li>
              <li>
                <span className="auth-flow-icon"><LayoutDashboard size={15} /></span>
                <span>
                  <strong>Dashboard</strong>
                  <small>Satu catatan dibaca sesuai peran pengguna.</small>
                </span>
              </li>
            </ol>

            <div className="auth-aside-foot">
              <span className="auth-live-dot" />
              Perangkat terhubung ke server sekolah
            </div>
          </aside>

          <section className="auth-card">
            <div className="auth-title">
              <span className="auth-kicker">Masuk</span>
              <h2>Masuk ke sistem</h2>
              <p>
                Gunakan NIS atau NIP yang terdaftar di sekolah.
              </p>
            </div>

            {error && (
              <div className="auth-error">
                <span className="auth-error-icon">!</span>
                <span>{error}</span>
              </div>
            )}

            <form className="auth-form" onSubmit={handleSubmit}>
              <div className="auth-field">
                <label htmlFor="login-identity">NIS / NIP</label>
                <div className="auth-input-wrap">
                  <input
                    id="login-identity"
                    type="text"
                    value={username}
                    placeholder="Contoh: 10231"
                    autoComplete="username"
                    disabled={loading}
                    onChange={(e) => {
                      setUsername(e.target.value);
                      setError("");
                    }}
                  />
                </div>
              </div>

              <div className="auth-field">
                <label htmlFor="login-password">Password</label>
                <div className="auth-input-wrap auth-password">
                  <input
                    id="login-password"
                    type={showPassword ? "text" : "password"}
                    value={password}
                    placeholder="Masukkan password"
                    autoComplete="current-password"
                    disabled={loading}
                    onChange={(e) => {
                      setPassword(e.target.value);
                      setError("");
                    }}
                  />
                  <button
                    type="button"
                    className="auth-password-button"
                    onClick={() => setShowPassword(!showPassword)}
                    aria-label={showPassword ? "Sembunyikan password" : "Tampilkan password"}
                  >
                    {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
                  </button>
                </div>
              </div>

              <div className="auth-switch">
                <span>Lupa password?</span>
                <button type="button" onClick={() => onNavigate("forgot-password")}>
                  Ajukan reset
                </button>
              </div>

              <button type="submit" className="auth-submit" disabled={loading}>
                {loading ? (
                  <>
                    <span className="auth-spinner" />
                    Memproses...
                  </>
                ) : (
                  <>
                    Masuk
                    <ArrowRight size={17} />
                  </>
                )}
              </button>
            </form>

            <div className="auth-switch auth-switch-note">
              <span>
                Akun dibuat oleh Admin. Password sementara harus diganti saat
                login pertama.
              </span>
            </div>

            <button type="button" className="auth-back" onClick={handleBack}>
              ← Kembali ke beranda
            </button>
          </section>
        </section>
      </main>
    </div>
  );
}

export default LoginPage;
