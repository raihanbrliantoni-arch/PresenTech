import { useEffect, useState } from "react";
import { Sun, Moon, Menu, X } from "lucide-react";
import { NAVBAR_HEIGHT } from "../constants";

function Navbar({
  onNavigate,
  darkMode,
  setDarkMode,
}) {
  const [menuOpen, setMenuOpen] = useState(false);
  const [activeSection, setActiveSection] = useState("beranda");

  /* ==================================================
     ACTIVE SECTION SAAT SCROLL
  ================================================== */

  useEffect(() => {
    const sectionIds = [
      "beranda",
      "tentang",
      "fitur",
      "cara-kerja",
      "pengguna",
    ];

    const updateActiveSection = () => {
      const sections = sectionIds
        .map((id) => document.getElementById(id))
        .filter(Boolean);

      if (!sections.length) return;

      // Titik acuan sekitar sepertiga layar. Section yang judulnya sudah
      // melewati garis ini dianggap sedang dibaca. Nilai ini lebih longgar
      // dari tinggi navbar karena tautan memakai scroll-margin 170px.
      const marker = Math.round(window.innerHeight * 0.35);
      let current = sections[0].id;

      for (const section of sections) {
        if (section.getBoundingClientRect().top <= marker) {
          current = section.id;
        } else {
          break;
        }
      }

      // Saat berada sangat dekat bagian paling atas, Beranda harus aktif.
      if (window.scrollY < 80) {
        current = "beranda";
      }

      // Saat sudah mentok bawah, bagian terakhir dianggap aktif.
      const nearBottom =
        window.innerHeight + window.scrollY >=
        document.documentElement.scrollHeight - 8;

      if (nearBottom) {
        current = sections[sections.length - 1].id;
      }

      setActiveSection((previous) =>
        previous === current ? previous : current
      );
    };

    let ticking = false;

    const handleScroll = () => {
      if (ticking) return;

      ticking = true;
      window.requestAnimationFrame(() => {
        updateActiveSection();
        ticking = false;
      });
    };

    updateActiveSection();
    window.addEventListener("scroll", handleScroll, { passive: true });
    window.addEventListener("resize", updateActiveSection);

    return () => {
      window.removeEventListener("scroll", handleScroll);
      window.removeEventListener("resize", updateActiveSection);
    };
  }, []);

  /* ==================================================
     SCROLL SECTION
  ================================================== */

  const handleSectionClick = (id) => {
    const section = document.getElementById(id);

    if (!section) return;

    const navbarHeight = NAVBAR_HEIGHT;

    // Hitung posisi berdasarkan dokumen agar section selalu tepat
    // berada di bawah navbar fixed. Tidak bergantung pada scroll-margin.
    const targetPosition = Math.max(
      0,
      section.getBoundingClientRect().top +
        window.scrollY -
        navbarHeight
    );

    setActiveSection(id);
    setMenuOpen(false);

    window.scrollTo({
      top: targetPosition,
      behavior: "smooth",
    });
  };


  /* ==================================================
     LOGIN
  ================================================== */

  const handleLogin = () => {
    setMenuOpen(false);

    if (onNavigate) {
      onNavigate("login");
    }
  };


  /* ==================================================
     TOGGLE DARK MODE
  ================================================== */

  const handleTheme = () => {
    setDarkMode(!darkMode);
  };


  return (
    <nav className="navbar">

      <div className="navbar-container">


        {/* ==================================================
            LOGO
        ================================================== */}

        <button
          type="button"
          className="navbar-brand"
          onClick={() =>
            handleSectionClick("beranda")
          }
        >

          <div className="brand-logo">

            <img
              src={
                darkMode
                  ? `${import.meta.env.BASE_URL}logo-dark.png`
                  : `${import.meta.env.BASE_URL}logo-light.png`
              }
              alt="PresenTech"
            />

          </div>


          <div className="brand-text">

            <strong>
              PresenTech
            </strong>

            <span>
              SMART ATTENDANCE SYSTEM
            </span>

          </div>

        </button>


        {/* ==================================================
            MENU
        ================================================== */}

        <div
          className={`nav-menu ${
            menuOpen ? "show" : ""
          }`}
        >

          <button
            type="button"
            className={activeSection === "beranda" ? "active" : ""}
            onClick={() =>
              handleSectionClick("beranda")
            }
          >
            Beranda
          </button>


          <button
            type="button"
            className={activeSection === "tentang" ? "active" : ""}
            onClick={() =>
              handleSectionClick("tentang")
            }
          >
            Tentang
          </button>


          <button
            type="button"
            className={activeSection === "fitur" ? "active" : ""}
            onClick={() =>
              handleSectionClick("fitur")
            }
          >
            Fitur
          </button>


          <button
            type="button"
            className={activeSection === "cara-kerja" ? "active" : ""}
            onClick={() =>
              handleSectionClick("cara-kerja")
            }
          >
            Cara Kerja
          </button>


          <button
            type="button"
            className={activeSection === "pengguna" ? "active" : ""}
            onClick={() =>
              handleSectionClick("pengguna")
            }
          >
            Pengguna
          </button>

        </div>


        {/* ==================================================
            RIGHT
        ================================================== */}

        <div className="navbar-right">


          {/* THEME */}

          <button
            type="button"
            className="theme-btn"
            onClick={handleTheme}
            aria-label="Ganti tema"
          >

            {darkMode ? <Sun size={17} /> : <Moon size={17} />}

          </button>


          {/* LOGIN */}

          <button
            type="button"
            className="login-btn"
            onClick={handleLogin}
          >
            Login
          </button>


          {/* MOBILE */}

          <button
            type="button"
            className="mobile-btn"
            onClick={() =>
              setMenuOpen(!menuOpen)
            }
            aria-label="Menu"
          >

            {menuOpen ? <X size={17} /> : <Menu size={17} />}

          </button>

        </div>

      </div>

    </nav>
  );
}

export default Navbar;