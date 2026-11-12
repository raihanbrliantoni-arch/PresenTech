import { useEffect, useState } from "react";

import AdminDashboard from "./dashboard-admin/AdminDashboard";
import GuruDashboard from "./dashboard-guru/GuruDashboard";
import SiswaDashboard from "./dashboard-siswa/SiswaDashboard";

import LandingPage from "./pages/LandingPage";
import LoginPage from "./pages/LoginPage";
import ForgotPasswordPage from "./pages/ForgotPasswordPage";

import ToastHost from "./components/Toast";
import PasswordChangeGate from "./components/PasswordChangeGate";

const DASHBOARD_PAGES = ["admin", "guru", "siswa"];

// Halaman awal: pulihkan sesi login yang masih tersimpan (token + role),
// atau mulai dari halaman publik.
function initialPage() {
  if (!localStorage.getItem("presentechToken")) return "home";

  const role = localStorage.getItem("presentechRole");
  return DASHBOARD_PAGES.includes(role) ? role : "home";
}

function App() {
  const [page, setPage] = useState(initialPage);
  const [sessionVersion, setSessionVersion] = useState(0);

  const [darkMode, setDarkMode] = useState(
    localStorage.getItem("theme") === "dark"
  );

  useEffect(() => {
    document.body.classList.toggle(
      "dark-mode",
      darkMode
    );

    // Kelas yang sama di <html> dipakai untuk menyesuaikan scrollbar
    // vertikal di tepi kanan layar (scrollbar viewport milik <html>).
    document.documentElement.classList.toggle(
      "dark-mode",
      darkMode
    );

    localStorage.setItem(
      "theme",
      darkMode ? "dark" : "light"
    );
  }, [darkMode]);

  // Token dicabut di server (401) -> kembali ke halaman login.
  useEffect(() => {
    const onSessionExpired = () => setPage("login");
    const onPasswordChangeRequired = () =>
      setSessionVersion((value) => value + 1);

    window.addEventListener("presentech:session-expired", onSessionExpired);
    window.addEventListener(
      "presentech:password-change-required",
      onPasswordChangeRequired
    );

    return () => {
      window.removeEventListener("presentech:session-expired", onSessionExpired);
      window.removeEventListener(
        "presentech:password-change-required",
        onPasswordChangeRequired
      );
    };
  }, []);

  const navigate = (target) => {
    setPage(target);

    window.scrollTo({
      top: 0,
      behavior: "smooth",
    });
  };

  // Guard: dashboard hanya boleh dirender bila ada token DAN
  // role yang sesuai dengan dashboard tersebut.
  const hasToken = Boolean(localStorage.getItem("presentechToken"));
  const activeRole = localStorage.getItem("presentechRole");
  const guardedPage =
    DASHBOARD_PAGES.includes(page) && (!hasToken || activeRole !== page)
      ? "login"
      : page;

  // Akun dengan password sementara dari Admin wajib mengganti password
  // sebelum dashboard dirender. Server juga menolak endpoint lain (428).
  const storedUser = (() => {
    try {
      return JSON.parse(localStorage.getItem("presentechUser") || "{}");
    } catch {
      return {};
    }
  })();

  const mustChangePassword =
    DASHBOARD_PAGES.includes(guardedPage) && storedUser.must_change_password === true;

  let view;

  if (mustChangePassword) {
    view = (
      <PasswordChangeGate
        key={sessionVersion}
        onDone={() => setSessionVersion((value) => value + 1)}
        onLogout={() => setPage("login")}
      />
    );
  } else {
    switch (guardedPage) {
    case "admin":
      view = (
        <AdminDashboard
          onNavigate={navigate}
          darkMode={darkMode}
          setDarkMode={setDarkMode}
        />
      );
      break;

    case "guru":
      view = (
        <GuruDashboard
          onNavigate={navigate}
          darkMode={darkMode}
          setDarkMode={setDarkMode}
        />
      );
      break;

    case "siswa":
      view = (
        <SiswaDashboard
          onNavigate={navigate}
          darkMode={darkMode}
          setDarkMode={setDarkMode}
        />
      );
      break;

    case "login":
      view = (
        <LoginPage
          onNavigate={navigate}
          darkMode={darkMode}
          setDarkMode={setDarkMode}
        />
      );
      break;

    case "forgot-password":
      view = (
        <ForgotPasswordPage
          onNavigate={navigate}
          darkMode={darkMode}
          setDarkMode={setDarkMode}
        />
      );
      break;

    default:
      view = (
        <LandingPage
          onNavigate={navigate}
          darkMode={darkMode}
          setDarkMode={setDarkMode}
        />
      );
    }
  }

  return (
    <>
      <ToastHost />
      {view}
    </>
  );
}

export default App;
