import { useState } from "react";
import { KeyRound, LogOut, ShieldCheck } from "lucide-react";
import { changePassword, logout } from "../api/authApi";
import { getCurrentUser } from "../api/authApi";
import { clearPresenTechSession } from "../api/session";

/**
 * Gerbang keamanan: akun yang dibuat/direset Admin memakai password
 * sementara dan wajib menggantinya sebelum dashboard bisa dipakai.
 * Server juga menolak endpoint lain (428) sampai password diganti.
 */
function PasswordChangeGate({ onDone, onLogout }) {
  const stored = (() => {
    try {
      return JSON.parse(localStorage.getItem("presentechUser") || "{}");
    } catch {
      return {};
    }
  })();

  const [form, setForm] = useState({ current: "", next: "", confirm: "" });
  const [show, setShow] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const set = (key, value) => {
    setForm((old) => ({ ...old, [key]: value }));
    setError("");
  };

  const submit = async (event) => {
    event.preventDefault();

    if (form.next.length < 8 || !/[A-Za-z]/.test(form.next) || !/\d/.test(form.next)) {
      setError("Password baru minimal 8 karakter dan harus berisi huruf serta angka.");
      return;
    }

    if (form.next !== form.confirm) {
      setError("Konfirmasi password belum sama.");
      return;
    }

    setBusy(true);
    try {
      await changePassword(form.current, form.next);
      const fresh = await getCurrentUser().catch(() => null);
      const user = fresh?.user || { ...stored, must_change_password: false };
      user.must_change_password = false;
      localStorage.setItem("presentechUser", JSON.stringify(user));
      onDone?.();
    } catch (err) {
      const message =
        err?.response?.data?.errors?.current_password?.[0] ||
        err?.response?.data?.errors?.password?.[0] ||
        err?.response?.data?.message ||
        "Password gagal diperbarui. Periksa password sementara kamu.";
      setError(message);
    } finally {
      setBusy(false);
    }
  };

  const handleLogout = async () => {
    try {
      await logout();
    } catch {
      // Token mungkin sudah dicabut; sesi lokal tetap dibersihkan.
    }
    clearPresenTechSession();
    onLogout?.();
  };

  return (
    <div className="auth-page">
      <header className="auth-header">
        <div className="auth-brand">
          <div className="auth-brand-logo">
            <img src={`${import.meta.env.BASE_URL}logo-light.png`} alt="PresenTech" />
          </div>
          <div className="auth-brand-text">
            <strong>PresenTech</strong>
            <span>SMART ATTENDANCE SYSTEM</span>
          </div>
        </div>

        <button type="button" className="auth-theme" onClick={handleLogout} aria-label="Keluar">
          <LogOut size={18} />
        </button>
      </header>

      <main className="auth-main">
        <section className="auth-card gate-card">
          <div className="gate-stamp" aria-hidden="true">
            <ShieldCheck size={22} />
            <span>LANGKAH KEAMANAN</span>
          </div>

          <div className="auth-title">
            <h1>Ganti password sementara</h1>
            <p>
              Halo {stored.name || "pengguna"}. Akun kamu memakai password dari Admin.
              Buat password pribadi sekarang agar akun tidak bisa dipakai orang lain.
            </p>
          </div>

          {error && (
            <div className="auth-error">
              <span className="auth-error-icon">!</span>
              <span>{error}</span>
            </div>
          )}

          <form className="auth-form" onSubmit={submit}>
            <div className="auth-field">
              <label htmlFor="gate-current">Password sementara</label>
              <div className="auth-input-wrap auth-password">
                <input
                  id="gate-current"
                  type={show ? "text" : "password"}
                  value={form.current}
                  autoComplete="current-password"
                  onChange={(event) => set("current", event.target.value)}
                  placeholder="Masukkan password dari Admin"
                  required
                />
                <button
                  type="button"
                  className="auth-password-button"
                  onClick={() => setShow((value) => !value)}
                >
                  <KeyRound size={17} />
                </button>
              </div>
            </div>

            <div className="auth-field">
              <label htmlFor="gate-next">Password baru</label>
              <input
                id="gate-next"
                type={show ? "text" : "password"}
                value={form.next}
                autoComplete="new-password"
                onChange={(event) => set("next", event.target.value)}
                placeholder="Minimal 8 karakter, huruf dan angka"
                required
              />
            </div>

            <div className="auth-field">
              <label htmlFor="gate-confirm">Ulangi password baru</label>
              <input
                id="gate-confirm"
                type={show ? "text" : "password"}
                value={form.confirm}
                autoComplete="new-password"
                onChange={(event) => set("confirm", event.target.value)}
                placeholder="Ketik ulang password baru"
                required
              />
            </div>

            <button type="submit" className="auth-submit" disabled={busy}>
              {busy ? "Menyimpan..." : "Simpan & Lanjutkan"}
            </button>
          </form>

          <button type="button" className="auth-back" onClick={handleLogout}>
            Keluar dari akun ini
          </button>
        </section>
      </main>
    </div>
  );
}

export default PasswordChangeGate;
