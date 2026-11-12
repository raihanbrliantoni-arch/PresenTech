import { useEffect, useMemo, useState } from "react";
import { Download, FileSpreadsheet, FileText, Loader2, X } from "lucide-react";

import { getAttendanceReport } from "../api/reportApi";
import {
  exportReportCsv,
  exportReportExcel,
  exportReportPdf,
  reportFileBase,
  reportStatusTotals,
} from "../lib/reportExport";
import { toast } from "../lib/toast";

const ORIENTATION_KEY = "presentechPdfOrientation";

const readOrientation = () => {
  try {
    return localStorage.getItem(ORIENTATION_KEY) || "landscape";
  } catch {
    return "landscape";
  }
};

const saveOrientation = (value) => {
  try {
    localStorage.setItem(ORIENTATION_KEY, value);
  } catch {
    // localStorage diblokir: cukup pakai nilai di state.
  }
};

const pad = (value) => String(value).padStart(2, "0");

// Format yang dipakai <input type="date">: YYYY-MM-DD.
const toDateValue = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;

const startOfMonthValue = () => {
  const date = new Date();
  date.setDate(1);

  return toDateValue(date);
};

const endOfTodayValue = () => toDateValue(new Date());

/**
 * Modal export absensi.
 *
 * Admin memilih kelas lalu rentang tanggal. Backend memperlakukan tanggal
 * sebagai satu hari penuh, jadi tidak perlu mengisi jam manual.
 * Hasilnya diunduh sebagai PDF/Excel/CSV lengkap dengan jam masuk,
 * jam pulang, dan keterangan.
 */
export default function AttendanceExportModal({ open, onClose, classes = [] }) {
  const [classId, setClassId] = useState("all");
  const [fromDate, setFromDate] = useState(startOfMonthValue);
  const [toDate, setToDate] = useState(endOfTodayValue);
  const [report, setReport] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [format, setFormat] = useState("pdf");
  const [orientation, setOrientation] = useState(readOrientation);

  const params = useMemo(() => {
    // Bila Admin membalik urutan, tukar otomatis agar tidak error validasi.
    const swap = fromDate && toDate && toDate < fromDate;

    return {
      from: swap ? toDate : fromDate || undefined,
      to: swap ? fromDate : toDate || undefined,
      class_id: classId === "all" ? undefined : classId,
    };
  }, [fromDate, toDate, classId]);

  useEffect(() => {
    if (!open) return undefined;

    let cancelled = false;

    const load = async () => {
      setLoading(true);
      setError("");

      try {
        const result = await getAttendanceReport(params);

        if (!cancelled) setReport(result);
      } catch (err) {
        if (!cancelled) {
          setReport(null);
          setError(err?.response?.data?.message || "Data export gagal dimuat.");
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    };

    load();

    return () => {
      cancelled = true;
    };
  }, [open, params]);

  useEffect(() => {
    if (!open) return undefined;

    const onKeyDown = (event) => {
      if (event.key === "Escape") onClose();
    };

    window.addEventListener("keydown", onKeyDown);

    return () => window.removeEventListener("keydown", onKeyDown);
  }, [open, onClose]);

  if (!open) return null;

  const classLabel = classId === "all"
    ? "Semua Kelas"
    : classes.find((item) => String(item.id) === String(classId))?.name || "Kelas";
  const fileBase = reportFileBase(report);
  const disabled = loading || !report || report.total === 0;
  // Rekap status seluruh kelas pada periode yang dipilih.
  const statusTotals = reportStatusTotals(report);

  const exportPdf = () => {
    if (!report) return;

    exportReportPdf({ report, filename: `${fileBase}.pdf`, orientation, classLabel })
      .catch(() => toast.error("Export PDF gagal dibuat. Coba lagi."));
  };

  const exportCsv = () => {
    if (!report) return;

    exportReportCsv({ report, filename: `${fileBase}.csv` });
  };

  const exportExcel = () => {
    if (!report) return;

    exportReportExcel({ report, filename: `${fileBase}.xls` })
      .catch(() => toast.error("Export Excel gagal dibuat. Coba lagi."));
  };

  const runExport = () => {
    if (format === "pdf") exportPdf();
    else if (format === "excel") exportExcel();
    else exportCsv();
  };

  return (
    <div className="modal-backdrop" onClick={onClose} role="presentation">
      <div className="modal export-modal" onClick={(event) => event.stopPropagation()} role="dialog" aria-modal="true" aria-label="Export absensi">
        <div className="modal-head">
          <div>
            <h2>Export Absensi</h2>
          </div>
          <button type="button" className="modal-close" onClick={onClose} aria-label="Tutup">
            <X size={18} />
          </button>
        </div>

        <div className="export-form">
          <label>
            <span>Kelas</span>
            <select value={classId} onChange={(event) => setClassId(event.target.value)}>
              <option value="all">Semua Kelas</option>
              {classes.map((item) => (
                <option key={item.id} value={item.id}>
                  {item.name}
                </option>
              ))}
            </select>
          </label>

          <label>
            <span>Dari tanggal</span>
            <input
              type="date"
              value={fromDate}
              max={toDate || undefined}
              onChange={(event) => event.target.value && setFromDate(event.target.value)}
            />
          </label>

          <label>
            <span>Sampai tanggal</span>
            <input
              type="date"
              value={toDate}
              min={fromDate || undefined}
              onChange={(event) => event.target.value && setToDate(event.target.value)}
            />
          </label>
        </div>

        <div className="export-summary">
          {loading ? (
            <span className="export-loading"><Loader2 size={15} className="spin" /> Menghitung data...</span>
          ) : (
            <span>
              <strong>{report?.total ?? 0}</strong> data • {report?.period?.label || "-"}
              <span className="export-status-counts">
                <b className="hadir">Hadir {statusTotals.hadir}</b>
                <b className="terlambat">Terlambat {statusTotals.terlambat}</b>
                <b className="tidak-hadir">Tidak hadir {statusTotals.tidakHadir}</b>
              </span>
            </span>
          )}
        </div>

        {(report?.warnings?.missing_no_absen > 0 || report?.warnings?.duplicate_no_absen > 0) && (
          <p className="export-note">
            {report.warnings.missing_no_absen > 0 && `${report.warnings.missing_no_absen} siswa belum punya No Absen. `}
            {report.warnings.duplicate_no_absen > 0 && `${report.warnings.duplicate_no_absen} nomor absen ganda. `}
            Buka Data Siswa lalu isi No Absen tiap siswa lewat tombol Edit agar urutan export konsisten.
          </p>
        )}

        {report?.is_holiday && report?.holiday && (
          <p className="export-note">Tanggal {report.holiday.date} adalah hari libur ({report.holiday.name}); siswa tanpa tap tidak diisi Alpa.</p>
        )}

        {error && <p className="export-note">{error}</p>}
        {report?.truncated && (
          <p className="export-note">Data dipotong pada {report.total} baris. Persempit periode atau pilih satu kelas.</p>
        )}
        {!loading && report && report.total === 0 && (
          <p className="export-note">Tidak ada absensi pada periode ini.</p>
        )}

        <div className="format-grid" role="radiogroup" aria-label="Format file">
          {[
            { id: "pdf", label: "PDF", icon: <FileText size={22} /> },
            { id: "excel", label: "Excel", icon: <FileSpreadsheet size={22} /> },
            { id: "csv", label: "CSV", icon: <Download size={22} /> },
          ].map((option) => (
            <button
              key={option.id}
              type="button"
              role="radio"
              aria-checked={format === option.id}
              className={`format-tile ${format === option.id ? "selected" : ""}`}
              onClick={() => setFormat(option.id)}
            >
              {option.icon}
              <span>{option.label}</span>
            </button>
          ))}
        </div>

        {format === "pdf" && (
          <div className="export-orientation">
            <span className="export-orientation-label">Orientasi PDF</span>
            <div className="orientation-grid" role="radiogroup" aria-label="Orientasi PDF">
              {[
                { id: "portrait", label: "Potret" },
                { id: "landscape", label: "Lanskap" },
              ].map((option) => (
                <button
                  key={option.id}
                  type="button"
                  role="radio"
                  aria-checked={orientation === option.id}
                  className={`format-tile compact ${orientation === option.id ? "selected" : ""}`}
                  onClick={() => {
                    setOrientation(option.id);
                    saveOrientation(option.id);
                  }}
                >
                  <span>{option.label}</span>
                </button>
              ))}
            </div>
          </div>
        )}

        <div className="modal-actions">
          <button type="button" className="secondary-button" onClick={onClose}>
            Batal
          </button>
          <button type="button" className="primary-button" onClick={runExport} disabled={disabled}>
            <Download size={15} />
            Unduh {format.toUpperCase()}
          </button>
        </div>
      </div>
    </div>
  );
}
