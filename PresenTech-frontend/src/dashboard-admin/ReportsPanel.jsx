import { useEffect, useMemo, useState } from "react";
import { CheckCircle2, Clock3, Download, FileWarning, Loader2, RefreshCw, UserX } from "lucide-react";

import { getAttendanceReport } from "../api/reportApi";
import { statusClass } from "../lib/helpers";
import ReportDocumentHeader from "./ui/ReportDocumentHeader";
import {
  exportReportCsv,
  exportReportExcel,
  exportReportPdf,
  reportFileBase,
  reportStatusTotals,
} from "../lib/reportExport";
import { toast } from "../lib/toast";

const PREVIEW_LIMIT = 200;

const STATUS_OPTIONS = [
  { value: "all", label: "Semua Status" },
  { value: "Hadir", label: "Hadir" },
  { value: "Terlambat", label: "Terlambat" },
  { value: "Izin", label: "Izin" },
  { value: "Sakit", label: "Sakit" },
  { value: "Alpa", label: "Alpa" },
  { value: "Dikoreksi", label: "Dikoreksi" },
];

const pad = (value) => String(value).padStart(2, "0");
const toDateValue = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
const todayValue = () => toDateValue(new Date());
const firstOfMonthValue = () => {
  const date = new Date();
  date.setDate(1);

  return toDateValue(date);
};

const readOrientation = () => {
  try {
    return localStorage.getItem("presentechPdfOrientation") || "landscape";
  } catch {
    return "landscape";
  }
};

export default function ReportsPanel({ classes = [] }) {
  const [from, setFrom] = useState(firstOfMonthValue);
  const [to, setTo] = useState(todayValue);
  const [classId, setClassId] = useState("all");
  const [status, setStatus] = useState("all");
  const [report, setReport] = useState(null);
  const [loading, setLoading] = useState(false);
  const [refreshKey, setRefreshKey] = useState(0);
  const [error, setError] = useState("");
  const [orientation, setOrientation] = useState(readOrientation);

  const params = useMemo(() => {
    // Bila Admin membalik urutan tanggal, tukar otomatis agar tidak error validasi.
    const swap = from && to && to < from;

    return {
      period: "range",
      from: swap ? to : from,
      to: swap ? from : to,
      class_id: classId === "all" ? undefined : classId,
      status: status === "all" ? undefined : status,
    };
  }, [from, to, classId, status]);

  useEffect(() => {
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
          setError(err?.response?.data?.message || "Laporan gagal dimuat.");
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    };

    load();

    return () => {
      cancelled = true;
    };
  }, [params, refreshKey]);

  const totals = reportStatusTotals(report);
  const classLabel = classId === "all"
    ? "Semua Kelas"
    : classes.find((item) => String(item.id) === String(classId))?.name || "Kelas";
  const statusLabel = STATUS_OPTIONS.find((item) => item.value === status)?.label || "Semua Status";
  const documentDetail = status === "all" ? classLabel : `${classLabel} • ${statusLabel}`;
  const fileBase = reportFileBase(report, "presentech-laporan");
  const canExport = Boolean(report) && !loading && report.total > 0;

  const detailRows = useMemo(() => (report?.classes || []).flatMap((group) =>
    group.rows.map((row) => ({ ...row, className: group.name })),
  ), [report]);
  const visibleRows = detailRows.slice(0, PREVIEW_LIMIT);

  const changeOrientation = (value) => {
    setOrientation(value);
    try {
      localStorage.setItem("presentechPdfOrientation", value);
    } catch {
      // localStorage diblokir: cukup pakai nilai di state.
    }
  };

  const exportPdf = () => {
    if (!canExport) return;

    exportReportPdf({ report, filename: `${fileBase}.pdf`, orientation, classLabel })
      .catch(() => toast.error("Export PDF gagal dibuat. Coba lagi."));
  };

  const exportExcel = () => {
    if (!canExport) return;

    exportReportExcel({ report, filename: `${fileBase}.xls` })
      .catch(() => toast.error("Export Excel gagal dibuat. Coba lagi."));
  };

  const exportCsv = () => {
    if (!canExport) return;

    exportReportCsv({ report, filename: `${fileBase}.csv` });
  };

  return (
    <div className="page-stack">
      <ReportDocumentHeader
        title="Laporan Kehadiran Siswa"
        period={report?.period?.label || "Pilih rentang tanggal laporan"}
        detail={documentDetail}
      />

      <div className="report-toolbar">
        <span className="report-toolbar-label">Unduh laporan</span>
        <div className="head-actions">
          <select
            className="report-orientation"
            value={orientation}
            onChange={(event) => changeOrientation(event.target.value)}
            aria-label="Orientasi PDF"
          >
            <option value="landscape">PDF Lanskap</option>
            <option value="portrait">PDF Potret</option>
          </select>
          <button className="secondary-button" onClick={exportPdf} disabled={!canExport}>
            <Download size={15} /> PDF
          </button>
          <button className="secondary-button" onClick={exportExcel} disabled={!canExport}>
            <Download size={15} /> Excel
          </button>
          <button className="primary-button" onClick={exportCsv} disabled={!canExport}>
            <Download size={15} /> CSV
          </button>
        </div>
      </div>

      <section className="panel report-filter-panel">
        <div className="report-filter-row">
          <label className="report-filter-field">
            <span>Dari tanggal</span>
            <input type="date" value={from} max={to || undefined} onChange={(event) => event.target.value && setFrom(event.target.value)} />
          </label>
          <label className="report-filter-field">
            <span>Sampai tanggal</span>
            <input type="date" value={to} min={from || undefined} onChange={(event) => event.target.value && setTo(event.target.value)} />
          </label>

          <label className="report-filter-field">
            <span>Kelas</span>
            <select value={classId} onChange={(event) => setClassId(event.target.value)}>
              <option value="all">Semua Kelas</option>
              {classes.map((item) => (
                <option key={item.id} value={item.id}>{item.name}</option>
              ))}
            </select>
          </label>

          <label className="report-filter-field">
            <span>Status</span>
            <select value={status} onChange={(event) => setStatus(event.target.value)}>
              {STATUS_OPTIONS.map((item) => (
                <option key={item.value} value={item.value}>{item.label}</option>
              ))}
            </select>
          </label>

          <div className="report-summary">
            {loading ? <Loader2 size={20} className="spin" /> : <strong>{report?.total ?? 0}</strong>}
            <span>{loading ? "memuat laporan..." : "data ditemukan"}</span>
          </div>
        </div>

        <div className="report-period-line">
          <span>{report?.period?.label || "Pilih periode laporan."}</span>
          {report?.is_holiday && report?.holiday && <b>Hari libur: {report.holiday.name}</b>}
          {report?.truncated && <b>Data dipotong pada {report.total} baris, persempit periode.</b>}
        </div>

        {error && <div className="setting-help setting-help-error">{error}</div>}
      </section>

      <div className="attendance-overview report-summary-cards">
        <div className="attendance-overview-card hadir">
          <div className="attendance-overview-icon"><CheckCircle2 size={20} /></div>
          <div className="attendance-overview-text"><strong>{totals.hadir}</strong><span>Hadir</span></div>
        </div>
        <div className="attendance-overview-card terlambat">
          <div className="attendance-overview-icon"><Clock3 size={20} /></div>
          <div className="attendance-overview-text"><strong>{totals.terlambat}</strong><span>Terlambat</span></div>
        </div>
        <div className="attendance-overview-card tidak-hadir">
          <div className="attendance-overview-icon"><UserX size={20} /></div>
          <div className="attendance-overview-text"><strong>{totals.tidakHadir}</strong><span>Tidak Hadir</span></div>
        </div>
        <div className="attendance-overview-card tidak-hadir">
          <div className="attendance-overview-icon"><FileWarning size={20} /></div>
          <div className="attendance-overview-text"><strong>{totals.checkOutMissing}</strong><span>Belum Tap Pulang</span></div>
        </div>
      </div>

      <section className="panel">
        <div className="panel-header">
          <div><h3>Rekap per Kelas</h3></div>
          <span className="report-table-note">Kehadiran = (Hadir + Terlambat) / catatan</span>
        </div>
        {loading && !report ? (
          <div className="empty">Memuat rekap...</div>
        ) : (
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Kelas</th>
                  <th>Wali Kelas</th>
                  <th>Siswa</th>
                  <th>Catatan</th>
                  <th>Hadir</th>
                  <th>Terlambat</th>
                  <th>Izin</th>
                  <th>Sakit</th>
                  <th>Alpa</th>
                  <th>Kehadiran</th>
                </tr>
              </thead>
              <tbody>
                {(report?.class_summaries || []).length ? (report.class_summaries).map((group) => (
                  <tr key={group.id ?? "tanpa-kelas"}>
                    <td><strong>{group.name}</strong></td>
                    <td>{group.homeroom || "-"}</td>
                    <td>{group.students ?? 0}</td>
                    <td>{group.total ?? 0}</td>
                    <td>{group.summary?.Hadir || 0}</td>
                    <td>{group.summary?.Terlambat || 0}</td>
                    <td>{group.summary?.Izin || 0}</td>
                    <td>{group.summary?.Sakit || 0}</td>
                    <td>{group.summary?.Alpa || 0}</td>
                    <td><span className={`report-rate ${Number(group.attendance_rate) >= 80 ? "good" : Number(group.attendance_rate) >= 50 ? "warn" : "low"}`}>{group.attendance_rate ?? 0}%</span></td>
                  </tr>
                )) : (
                  <tr><td colSpan="10" className="empty">Tidak ada data pada periode ini.</td></tr>
                )}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <section className="panel">
        <div className="panel-header">
          <div><h3>Detail Absensi</h3></div>
          <span className="report-table-note">
            {detailRows.length > PREVIEW_LIMIT
              ? `Pratinjau ${PREVIEW_LIMIT} dari ${detailRows.length} baris. Export untuk data lengkap.`
              : `${detailRows.length} baris`}
          </span>
        </div>
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>No</th>
                <th>NIS</th>
                <th>Nama</th>
                <th>Kelas</th>
                <th>Tanggal</th>
                <th>Jam Masuk</th>
                <th>Jam Pulang</th>
                <th>Status</th>
                <th>Keterangan</th>
              </tr>
            </thead>
            <tbody>
              {visibleRows.length ? visibleRows.map((row, index) => (
                <tr key={`${row.nis}-${row.date}-${row.time}-${index}`}>
                  <td>{row.no ?? "-"}</td>
                  <td>{row.nis}</td>
                  <td><strong>{row.name}</strong></td>
                  <td>{row.className}</td>
                  <td>{row.date}</td>
                  <td>{row.time}</td>
                  <td>{row.check_out || "-"}{row.early_leave && <small className="log-type"> izin</small>}</td>
                  <td><span className={`status ${statusClass(row.status)}`}>{row.status}</span></td>
                  <td><small>{row.notes || "-"}</small></td>
                </tr>
              )) : (
                <tr><td colSpan="9" className="empty">{loading ? "Memuat data..." : "Tidak ada absensi pada periode ini."}</td></tr>
              )}
            </tbody>
          </table>
        </div>
        <button type="button" className="text-button report-refresh" onClick={() => setRefreshKey((value) => value + 1)} disabled={loading}>
          <RefreshCw size={14} /> Muat ulang
        </button>
      </section>
    </div>
  );
}
