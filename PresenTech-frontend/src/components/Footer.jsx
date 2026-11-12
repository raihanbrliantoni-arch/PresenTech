import { NAVBAR_HEIGHT } from "../constants";

function Footer({
  onNavigate,
  darkMode,
}) {


  /* ==================================================
     SCROLL SECTION
  ================================================== */

  const handleSectionClick = (id) => {
    const section = document.getElementById(id);

    if (!section) return;

    const navbarHeight = NAVBAR_HEIGHT;

    const targetPosition =
      section.getBoundingClientRect().top +
      window.scrollY -
      navbarHeight;

    window.scrollTo({
      top: targetPosition,
      behavior: "smooth",
    });
  };


  /* ==================================================
     LOGIN
  ================================================== */

  const handleLogin = () => {
    if (onNavigate) {
      onNavigate("login");
    }
  };


  return (
    <footer className="footer">

      <div className="footer-container">


        {/* ==================================================
            BRAND
        ================================================== */}

        <div className="footer-brand-section">

          <div className="footer-brand">

            <div className="footer-logo">

              <img
                src={
                  darkMode
                    ? `${import.meta.env.BASE_URL}logo-dark.png`
                    : `${import.meta.env.BASE_URL}logo-light.png`
                }
                alt="PresenTech"
              />

            </div>


            <div className="footer-brand-text">

              <div className="brand-name">
                PresenTech
              </div>

              <div className="brand-subtitle">
                Smart Attendance System
              </div>

            </div>

          </div>


          <p className="footer-description">
            Sistem absensi sekolah berbasis IoT
            untuk membantu mencatat, mengelola,
            dan memantau kehadiran siswa secara
            lebih cepat dan terorganisir.
          </p>

        </div>


        {/* ==================================================
            NAVIGASI
        ================================================== */}

        <div className="footer-column">

          <strong>
            Navigasi
          </strong>


          <button
            type="button"
            className="footer-link-button"
            onClick={() =>
              handleSectionClick("beranda")
            }
          >
            Beranda
          </button>


          <button
            type="button"
            className="footer-link-button"
            onClick={() =>
              handleSectionClick("tentang")
            }
          >
            Tentang
          </button>


          <button
            type="button"
            className="footer-link-button"
            onClick={() =>
              handleSectionClick("fitur")
            }
          >
            Fitur
          </button>


          <button
            type="button"
            className="footer-link-button"
            onClick={() =>
              handleSectionClick("cara-kerja")
            }
          >
            Cara Kerja
          </button>


          <button
            type="button"
            className="footer-link-button"
            onClick={() =>
              handleSectionClick("pengguna")
            }
          >
            Pengguna
          </button>

        </div>


        {/* ==================================================
            AKUN
        ================================================== */}

        <div className="footer-column">

          <strong>
            Akun
          </strong>


          <button
            type="button"
            className="footer-link-button"
            onClick={handleLogin}
          >
            Login
          </button>

        </div>


        {/* ==================================================
            TEKNOLOGI
        ================================================== */}

        <div className="footer-column footer-technology">

          <strong>
            Teknologi
          </strong>

          <span>
            RFID
          </span>

          <span>
            ESP32
          </span>

          <span>
            Database
          </span>

          <span>
            Dashboard
          </span>

        </div>

      </div>


      {/* ==================================================
          COPYRIGHT
      ================================================== */}

      <div className="copyright">

        <div>
          © 2026 PresenTech.
          Smart Attendance System.
        </div>

        <div className="footer-credit">
          By <strong>RaiTon</strong>
        </div>

      </div>

    </footer>
  );
}

export default Footer;