import { useEffect, useRef, useState } from "react";
import { CreditCard } from "lucide-react";

// Data contoh untuk simulasi perangkat. Bukan data siswa sebenarnya.
const studentData = {
  nama: "Raihan Briliantoni",
  kelas: "XI RPL 1",
  status: "Hadir",
  waktu: "06:25:00",
};

function Hero() {
  const [scanning, setScanning] = useState(false);
  const [attendance, setAttendance] = useState(null);
  const scanTimer = useRef(null);
  const resetTimer = useRef(null);

  useEffect(
    () => () => {
      clearTimeout(scanTimer.current);
      clearTimeout(resetTimer.current);
    },
    []
  );

  const handleRFID = () => {
    if (scanning) return;

    clearTimeout(resetTimer.current);
    setScanning(true);
    setAttendance(null);

    scanTimer.current = setTimeout(() => {
      setScanning(false);
      setAttendance(studentData);
      resetTimer.current = setTimeout(() => setAttendance(null), 6000);
    }, 1400);
  };

  return (
    <section className="hero" id="beranda">
      <div className="hero-container">
        {/* =========================
            HERO CONTENT
        ========================= */}

        <div className="hero-content">
          <div className="hero-label">
            SISTEM ABSENSI SEKOLAH BERBASIS IoT
          </div>

          <h1>Kelola kehadiran siswa dengan lebih mudah.</h1>

          <p className="hero-description">
            PresenTech menghubungkan kartu RFID, perangkat ESP32, dan dashboard
            sekolah. Kehadiran tercatat saat kartu ditempel, lalu langsung
            terbaca oleh admin, guru, dan siswa.
          </p>

          <div className="hero-actions">
            <a href="#fitur" className="hero-button">
              Pelajari Sistem
            </a>

            <a href="#cara-kerja" className="hero-button secondary">
              Lihat Cara Kerja
            </a>
          </div>

          <div className="hero-proof">
            <div>
              <strong>&lt; 1 detik</strong>
              <span>Waktu baca kartu</span>
            </div>
            <div>
              <strong>ESP32 + RFID</strong>
              <span>Perangkat di gerbang</span>
            </div>
            <div>
              <strong>3 peran</strong>
              <span>Admin, guru, siswa</span>
            </div>
          </div>
        </div>

        {/* =========================
            DEVICE PREVIEW
        ========================= */}

        <div className="hero-preview">
          <div className="preview-header">
            <strong>Simulasi Perangkat</strong>

            <div className="system-status">
              <span></span>
              Sistem Aktif
            </div>
          </div>

          <div className="preview-body">
            <div className="device-display">
              <div className="lcd-display">
                {!attendance && !scanning && (
                  <div className="lcd-ready">
                    <small>SMART ABSENSI</small>
                    <strong>READY</strong>
                  </div>
                )}

                {scanning && (
                  <div className="lcd-scanning">
                    <small>RFID</small>
                    <strong>MEMBACA...</strong>
                  </div>
                )}

                {attendance && (
                  <div className="lcd-attendance">
                    <div className="lcd-name">{attendance.nama}</div>
                    <div className="lcd-class">{attendance.kelas}</div>
                    <div className="lcd-status">{attendance.status}</div>
                    <div className="lcd-time">{attendance.waktu}</div>
                  </div>
                )}
              </div>

              <button
                type="button"
                className={`rfid-reader ${scanning ? "scanning" : ""}`}
                onClick={handleRFID}
                aria-label="Tap kartu RFID"
              >
                <div className="rfid-icon">
                  <CreditCard size={34} strokeWidth={1.8} />
                </div>

                <span>{scanning ? "Membaca..." : "Tap Kartu"}</span>
              </button>

              <div className="esp32-device">ESP32</div>
            </div>

            <div className="device-status-list">
              <div className="stat-row">
                <span>RFID</span>
                <strong className="status-online">Terhubung</strong>
              </div>

              <div className="stat-row">
                <span>ESP32</span>
                <strong className="status-online">Online</strong>
              </div>

              <div className="stat-row">
                <span>Database</span>
                <strong className="status-online">Terhubung</strong>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}

export default Hero;
