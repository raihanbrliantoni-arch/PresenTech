function About() {
  return (
    <section id="tentang" className="section about-section">
      <div className="section-container" data-reveal>
        <div className="about-content">
          <div>
            <p className="section-label">TENTANG PRESENTECH</p>

            <h2 className="about-title">
              Satu sistem untuk
              <br />
              mengelola kehadiran.
            </h2>

            <div className="about-text">
              <p>
                PresenTech menghubungkan perangkat absensi dengan sistem web
                sehingga data kehadiran dapat dicatat dan dikelola secara
                terpusat.
              </p>

              <p>
                Admin mengelola data sekolah dan perangkat, guru memantau
                kehadiran kelas yang diampu, sedangkan siswa melihat riwayat
                kehadirannya sendiri. Semuanya membaca catatan yang sama.
              </p>
            </div>
          </div>

          <div className="about-highlight">
            <h3>Terintegrasi dengan IoT</h3>

            <p>
              RFID, ESP32, database, dan dashboard bekerja dalam satu alur
              absensi. Tidak ada rekap ulang di akhir bulan.
            </p>
          </div>
        </div>
      </div>
    </section>
  );
}

export default About;
