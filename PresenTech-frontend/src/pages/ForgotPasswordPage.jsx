import { useCallback, useEffect, useState } from "react";
import { Eye, EyeOff, Moon, Sun } from "lucide-react";
import {
  getPasswordResetRequest,
  requestPasswordReset,
} from "../api/passwordResetApi";

// Alur reset password: cukup NIS/NIP. Permintaan berstatus PENDING dan
// baru diproses setelah Admin memverifikasi identitas pemohon. Token klaim
// dipakai untuk memantau status tanpa membocorkan id permintaan.
function ForgotPasswordPage({
  onNavigate,
  darkMode,
  setDarkMode,
}) {
  const [identifier, setIdentifier] = useState("");
  const [resetRole, setResetRole] = useState("siswa");
  const [requestId, setRequestId] = useState(() => {
    const saved = Number(sessionStorage.getItem("presentechResetRequestId"));
    return saved || null;
  });
  const [requestToken, setRequestToken] = useState(
    () => sessionStorage.getItem("presentechResetToken") || ""
  );
  const [status, setStatus] = useState("form");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");

  const identityLabel = resetRole === "siswa" ? "NIS" : "NIP";

  const loadStatus = useCallback(async (savedRequestId, savedToken) => {
    if (!savedRequestId || !savedToken) return;

    try {
      const result = await getPasswordResetRequest(
        savedRequestId,
        savedToken
      );
      const request = result?.request;

      if (!request) return;

      setRequestId(request.id);
      setRequestToken(savedToken);
      setIdentifier(request.identifier || "");
      setPassword(request.identifier || "");

      if (request.status === "PENDING") {
        setStatus("pending");
        setMessage(
          "Permintaan reset password masih ditinjau Admin. Setelah disetujui, password sementara menjadi sama dengan NIS/NIP."
        );
        return;
      }

      if (request.status === "REJECTED") {
        setStatus("rejected");
        setMessage("Permintaan reset password tidak disetujui Admin.");
        return;
      }

      if (request.status === "EXPIRED") {
        setStatus("rejected");
        setMessage(
          "Permintaan reset password sudah kedaluwarsa. Silakan ajukan kembali."
        );
        return;
      }

      // COMPLETED berarti password sementara sudah sama dengan NIS/NIP.
      setStatus("completed");
      setMessage(
        `Password sementara akun sudah direset menjadi NIS/NIP: ${request.identifier}. Login lalu segera ganti password.`
      );
    } catch {
      // Permintaan mungkin sudah lama/tidak ditemukan; biarkan status saat ini.
    }
  }, []);

  useEffect(() => {
    const savedRequestId =
      sessionStorage.getItem("presentechResetRequestId");
    const savedToken = sessionStorage.getItem("presentechResetToken");

    if (!savedRequestId || !savedToken) return;

    const timer = setTimeout(
      () => loadStatus(savedRequestId, savedToken),
      0
    );
    return () => clearTimeout(timer);
  }, [loadStatus]);

  // Polling hanya untuk permintaan yang masih menunggu Admin.
  useEffect(() => {
    if (!requestId || !requestToken || status !== "pending") return;

    const timer = setInterval(() => {
      loadStatus(requestId, requestToken);
    }, 5000);

    return () => clearInterval(timer);
  }, [requestId, requestToken, status, loadStatus]);

  const submitRequest = async (event) => {
    event.preventDefault();

    if (!identifier.trim()) {
      setError(`${identityLabel} wajib diisi.`);
      return;
    }

    try {
      setLoading(true);
      setError("");
      setMessage("");

      const result = await requestPasswordReset(identifier.trim(), resetRole);
      const request = result?.request;
      const identity = request?.identifier || identifier.trim();

      sessionStorage.removeItem("presentechResetIdentifier");
      if (request?.id) {
        sessionStorage.setItem(
          "presentechResetRequestId",
          String(request.id)
        );
      }
      if (result?.token) {
        sessionStorage.setItem("presentechResetToken", result.token);
      }

      setRequestId(request?.id || null);
      setRequestToken(result?.token || "");
      setPassword(identity);

      if (request?.status === "PENDING") {
        setStatus("pending");
        setMessage(
          "Permintaan reset password sudah dikirim dan menunggu persetujuan Admin. Halaman ini akan memperbarui status secara otomatis."
        );
      } else {
        setStatus("completed");
        setMessage(
          result?.message ||
            `Password sementara akun direset menjadi ${identity}. Login lalu segera ganti password.`
        );
      }
    } catch (err) {
      setError(
        err.response?.data?.message ||
          "Permintaan reset password gagal."
      );
    } finally {
      setLoading(false);
    }
  };

  const startOver = () => {
    sessionStorage.removeItem("presentechResetIdentifier");
    sessionStorage.removeItem("presentechResetRequestId");
    sessionStorage.removeItem("presentechResetToken");
    setRequestId(null);
    setRequestToken("");
    setPassword("");
    setStatus("form");
    setMessage("");
    setError("");
  };

  const renderStatus = () => {
    if (status === "pending") {
      return (
        <div className="auth-status-card">
          <div className="auth-status-icon">!</div>
          <h2>Menunggu Persetujuan Admin</h2>
          <p>{message}</p>
          <small>
            Halaman ini memeriksa status permintaan secara otomatis.
          </small>
        </div>
      );
    }

    if (status === "completed") {
      return (
        <div className="auth-status-card">
          <div className="auth-status-icon">✓</div>
          <h2>Password Sementara Direset</h2>
          <p>{message}</p>

          <div className="auth-field">
            <label>Username &amp; Password</label>
            <div className="auth-input-wrap auth-password">
              <input
                type={showPassword ? "text" : "password"}
                value={password}
                readOnly
                aria-label="Password akun"
              />
              <button
                type="button"
                className="auth-password-button"
                onClick={() => setShowPassword((value) => !value)}
                aria-label={showPassword ? "Sembunyikan" : "Tampilkan"}
              >
                {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
              </button>
            </div>
          </div>

          <button
            type="button"
            className="auth-submit"
            onClick={() => onNavigate("login")}
          >
            Login Sekarang
          </button>
        </div>
      );
    }

    if (status === "rejected") {
      return (
        <div className="auth-status-card">
          <div className="auth-status-icon">!</div>
          <h2>Permintaan Ditolak</h2>
          <p>{message}</p>
          <button
            type="button"
            className="auth-submit"
            onClick={startOver}
          >
            Ajukan Kembali
          </button>
        </div>
      );
    }

    return (
      <form className="auth-form" onSubmit={submitRequest}>
        <div className="auth-field">
          <label>Reset untuk</label>
          <div className="auth-role-tabs" role="tablist" aria-label="Pilih peran">
            <button
              type="button"
              className={`auth-role-tab ${resetRole === "siswa" ? "active" : ""}`}
              onClick={() => {
                setResetRole("siswa");
                setError("");
              }}
            >
              Siswa — NIS
            </button>
            <button
              type="button"
              className={`auth-role-tab ${resetRole === "guru" ? "active" : ""}`}
              onClick={() => {
                setResetRole("guru");
                setError("");
              }}
            >
              Guru — NIP
            </button>
          </div>
        </div>

        <div className="auth-field">
          <label htmlFor="forgot-identity">{identityLabel}</label>
          <div className="auth-input-wrap">
            <input
              id="forgot-identity"
              type="text"
              value={identifier}
              placeholder={resetRole === "siswa" ? "Contoh: 10231" : "Contoh: 123456789"}
              disabled={loading}
              onChange={(event) => {
                setIdentifier(event.target.value);
                setError("");
              }}
              required
            />
          </div>
        </div>

        {error && (
          <div className="auth-error">
            <span className="auth-error-icon">!</span>
            <span>{error}</span>
          </div>
        )}

        <button
          type="submit"
          className="auth-submit"
          disabled={loading}
        >
          {loading ? "Memproses..." : "Ajukan Reset Password"}
        </button>
      </form>
    );
  };

  return (
    <div className="auth-page">
      <header className="auth-header">
        <button
          type="button"
          className="auth-brand"
          onClick={() => onNavigate("home")}
        >
          <div className="auth-brand-logo">
            <img
              src={darkMode ? `${import.meta.env.BASE_URL}logo-dark.png` : `${import.meta.env.BASE_URL}logo-light.png`}
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
        <section className="auth-card">
          <div className="auth-title">
            <h1>Lupa Password</h1>
            <p>
              Masukkan NIS (siswa) atau NIP (guru). Permintaan akan ditinjau
              Admin terlebih dahulu; setelah disetujui, password sementara
              menjadi NIS/NIP dan wajib diganti saat login.
            </p>
          </div>

          {renderStatus()}

          <button
            type="button"
            className="auth-back"
            onClick={() => {
              // Bersihkan residual sesi reset saat keluar dari halaman.
              sessionStorage.removeItem("presentechResetIdentifier");
              sessionStorage.removeItem("presentechResetRequestId");
              sessionStorage.removeItem("presentechResetToken");
              setPassword("");
              onNavigate("login");
            }}
            disabled={loading}
          >
            ← Kembali ke Login
          </button>
        </section>
      </main>
    </div>
  );
}

export default ForgotPasswordPage;
