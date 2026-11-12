const steps = [
  {
    number: "01",
    title: "RFID",
    text: "Siswa menempelkan kartu RFID di pembaca.",
  },
  {
    number: "02",
    title: "ESP32",
    text: "ESP32 sebagai pengendali utama mengirim data ke server.",
  },
  {
    number: "03",
    title: "Database",
    text: "Data kehadiran disimpan ke database sekolah.",
  },
  {
    number: "04",
    title: "Dashboard",
    text: "Data ditampilkan pada dashboard sesuai peran pengguna.",
  },
];

function HowItWorks() {
  return (
    <section className="section how-section" id="cara-kerja">
      <div className="section-container">
        <div className="section-title center" data-reveal>
          <p className="section-label">CARA KERJA</p>

          <h2>
            Dari kartu RFID
            <br />
            sampai dashboard.
          </h2>
        </div>

        <div className="process">
          {steps.map((step, index) => (
            <div className="process-wrapper" key={step.number} data-reveal>
              <div className="process-item">
                <div className="process-number">{step.number}</div>

                <h3>{step.title}</h3>

                <p>{step.text}</p>
              </div>

              {index < steps.length - 1 && (
                <div className="process-line"></div>
              )}
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

export default HowItWorks;
