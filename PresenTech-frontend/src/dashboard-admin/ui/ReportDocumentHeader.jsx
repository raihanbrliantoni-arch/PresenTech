import { CalendarDays, ShieldCheck } from "lucide-react";

const LOGO_URL = `${import.meta.env.BASE_URL}logo-light.png`;

/**
 * Kop dokumen laporan resmi tanpa latar warna: logo + nama sekolah di kiri,
 * judul dokumen + rentang tanggal di kanan, dengan garis emas sebagai aksen.
 *
 * Dipakai halaman Laporan sebagai pratinjau kop yang sama dengan hasil
 * export PDF/Excel, sehingga Admin melihat tampilan dokumen sebelum unduh.
 */
export default function ReportDocumentHeader({
  title = "Laporan Kehadiran Siswa",
  period = "",
  detail = "",
}) {
  return (
    <header className="doc-header" aria-label="Kop dokumen laporan resmi">
      <div className="doc-header-inner">
        <div className="doc-header-brand">
          <span className="doc-header-logo">
            <img src={LOGO_URL} alt="Logo sekolah" />
          </span>
          <span className="doc-header-copy">
            <strong>SMK TELESANDI</strong>
            <span>PresenTech - Sistem Absensi RFID &amp; IoT</span>
          </span>
        </div>

        <div className="doc-header-doc">
          <span className="doc-header-kicker">Dokumen Resmi</span>
          <h3>{title}</h3>
          <span className="doc-header-rule" aria-hidden="true" />
          <p>{period || "Pilih rentang tanggal laporan"}</p>
          {detail && <small>{detail}</small>}
        </div>
      </div>

      <div className="doc-header-foot">
        <span><ShieldCheck size={13} /> Dihasilkan otomatis oleh PresenTech</span>
        <span>
          <CalendarDays size={13} />
          {new Intl.DateTimeFormat("id-ID", { dateStyle: "long" }).format(new Date())}
        </span>
      </div>
    </header>
  );
}
