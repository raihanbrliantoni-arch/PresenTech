import {
  CreditCard,
  Users,
  Monitor,
  BarChart3,
  FileDown,
  Cpu,
} from "lucide-react";

const features = [
  {
    number: "01",
    icon: CreditCard,
    title: "Absensi RFID",
    description: "Siswa melakukan absensi menggunakan kartu RFID.",
  },
  {
    number: "02",
    icon: Users,
    title: "Data Siswa",
    description: "Kelola data siswa, NIS, kelas, dan informasi lainnya.",
  },
  {
    number: "03",
    icon: Monitor,
    title: "Monitoring",
    description: "Pantau kehadiran siswa secara langsung melalui dashboard.",
  },
  {
    number: "04",
    icon: BarChart3,
    title: "Statistik",
    description: "Lihat statistik kehadiran berdasarkan siswa dan kelas.",
  },
  {
    number: "05",
    icon: FileDown,
    title: "Export Data",
    description:
      "Guru dan admin dapat mengubah data kehadiran menjadi PDF atau Excel.",
  },
  {
    number: "06",
    icon: Cpu,
    title: "Monitoring Device",
    description:
      "Admin dapat melihat kondisi ESP32, RFID, LCD, dan perangkat lainnya.",
  },
];

function Features() {
  return (
    <section className="features-section" id="fitur">
      <div className="features-container">
        <div className="section-heading" data-reveal>
          <span>FITUR SISTEM</span>
          <h2>Fitur utama PresenTech</h2>
          <p>
            Sistem absensi sekolah yang menghubungkan perangkat IoT dengan
            pengelolaan data kehadiran.
          </p>
        </div>

        <div className="feature-grid">
          {features.map((feature) => {
            const Icon = feature.icon;

            return (
              <div className="feature-card" key={feature.number} data-reveal>
                <div className="feature-top">
                  <span className="feature-number">{feature.number}</span>

                  <div className="feature-icon">
                    <Icon size={24} strokeWidth={1.8} />
                  </div>
                </div>

                <div className="feature-content">
                  <h3>{feature.title}</h3>

                  <p>{feature.description}</p>
                </div>
              </div>
            );
          })}
        </div>
      </div>
    </section>
  );
}

export default Features;
