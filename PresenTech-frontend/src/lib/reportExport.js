import { exportAttendancePdf, loadLogoDataUrl } from "./pdf";

const CSV_HEADER = ["No Absen", "Kelas", "Wali Kelas", "NIS", "Nama", "Tanggal", "Jam Masuk", "Jam Pulang", "Status", "Keterangan"];

const escapeHtml = (value) => String(value ?? "").replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;");

const SCHOOL_NAME = "SMK TELESANDI";
const SCHOOL_SUBTITLE = "PresenTech - Sistem Absensi RFID & IoT";

/**
 * Kop dokumen resmi versi tabel HTML — dipakai export Excel (.xls) agar
 * tampilannya sejalan dengan kop PDF dan pratinjau di halaman Laporan.
 * Warna memakai inline style karena Excel membuka file .xls sebagai HTML.
 */
export const documentHeaderHtml = async ({ title = "Laporan", period = "", detail = "" }) => {
  const logo = await loadLogoDataUrl();
  const logoTile = logo
    ? `<img src="${logo}" width="44" height="44" alt="Logo sekolah" style="display:block;border:0;" />`
    : "";

  return `<table style="border-collapse:collapse;width:100%;font-family:Calibri,Arial,sans-serif;">
  <tr>
    <td style="padding:14px 16px;width:58%;vertical-align:middle;">
      <table style="border-collapse:collapse;">
        <tr>
          <td style="width:52px;vertical-align:middle;">
            <div style="width:52px;height:52px;text-align:center;vertical-align:middle;padding:4px;">${logoTile}</div>
          </td>
          <td style="vertical-align:middle;padding-left:12px;">
            <div style="font-size:20px;font-weight:800;color:#111c2d;letter-spacing:0.5px;">${SCHOOL_NAME}</div>
            <div style="font-size:11px;color:#58687e;padding-top:3px;">${escapeHtml(SCHOOL_SUBTITLE)}</div>
          </td>
        </tr>
      </table>
    </td>
    <td style="padding:14px 16px;text-align:right;vertical-align:middle;">
      <div style="font-size:9px;font-weight:800;letter-spacing:2px;color:#8f6a26;text-transform:uppercase;">Dokumen Resmi</div>
      <div style="font-size:16px;font-weight:800;color:#111c2d;padding-top:4px;">${escapeHtml(title)}</div>
      ${period ? `<div style="font-size:11px;color:#3f4f63;padding-top:4px;">${escapeHtml(period)}</div>` : ""}
      ${detail ? `<div style="font-size:10px;color:#58687e;padding-top:2px;">${escapeHtml(detail)}</div>` : ""}
    </td>
  </tr>
  <tr>
    <td colspan="2" style="background:#d9ba78;height:3px;font-size:0;line-height:0;">&nbsp;</td>
  </tr>
  <tr>
    <td colspan="2" style="height:10px;font-size:0;line-height:0;">&nbsp;</td>
  </tr>
</table>`;
};

// Cegah CSV/formula injection: Excel & LibreOffice mengeksekusi sel yang
// diawali =, +, -, @, tab, atau CR. Awali dengan apostrof agar dianggap teks.
const neutralizeCsvFormula = (value) => {
  const text = String(value ?? "");
  return /^[=+\-@\t\r]/.test(text) ? `'${text}` : text;
};

export const downloadCsvFile = (filename, header, rows) => {
  const csv = [header, ...rows]
    .map((row) => row.map((value) => `"${neutralizeCsvFormula(value).replaceAll('"', '""')}"`).join(","))
    .join("\n");

  const blob = new Blob([`\ufeff${csv}`], { type: "text/csv;charset=utf-8;" });
  const anchor = document.createElement("a");
  anchor.href = URL.createObjectURL(blob);
  anchor.download = filename;
  anchor.click();
  URL.revokeObjectURL(anchor.href);
};

export const reportFileBase = (report, prefix = "presentech-absensi") => {
  const from = String(report?.period?.from || "awal").replace(/[^0-9]/g, "") || "awal";
  const to = String(report?.period?.to || "akhir").replace(/[^0-9]/g, "") || "akhir";

  return `${prefix}-${from}-${to}`;
};

export const flattenReportRows = (report) => (report?.classes || []).flatMap((group) =>
  group.rows.map((row) => [
    row.no ?? "",
    group.name,
    group.homeroom || "-",
    row.nis,
    row.name,
    row.date,
    row.time,
    row.check_out || "-",
    row.status,
    row.notes || "",
  ]),
);

export const reportStatusTotals = (report) => (report?.classes || []).reduce((totals, group) => {
  const summary = group.summary || {};

  totals.hadir += summary.Hadir || 0;
  totals.terlambat += summary.Terlambat || 0;
  totals.tidakHadir += (summary.Izin || 0) + (summary.Sakit || 0) + (summary.Alpa || 0) + (summary.Dikoreksi || 0);
  totals.checkOutMissing += group.check_out_missing || 0;

  return totals;
}, { hadir: 0, terlambat: 0, tidakHadir: 0, checkOutMissing: 0 });

export const exportReportCsv = ({ report, filename }) => {
  downloadCsvFile(filename, CSV_HEADER, flattenReportRows(report));
};

// Excel (.xls) dibuat dari tabel HTML — dibuka rapi di Excel/LibreOffice
// tanpa menambah dependensi baru.
export const exportReportExcel = async ({ report, filename }) => {
  const rows = flattenReportRows(report);
  const header = await documentHeaderHtml({
    title: "Laporan Kehadiran Siswa",
    period: report?.period?.label || "",
    detail: `Total data: ${report?.total || rows.length}`,
  });
  const table = [
    `<table border="1" style="border-collapse:collapse;font-family:Calibri,Arial,sans-serif;font-size:11px;">`,
    `<thead><tr>${CSV_HEADER.map((cell) => `<th style="background:#0b1424;color:#ffffff;padding:6px 8px;text-align:left;border:1px solid #24374f;">${escapeHtml(cell)}</th>`).join("")}</tr></thead><tbody>`,
    ...rows.map((row) => `<tr>${row.map((cell) => `<td style="padding:4px 8px;border:1px solid #dfe6ee;">${escapeHtml(cell)}</td>`).join("")}</tr>`),
    "</tbody></table>",
  ].join("");

  const html = `<html><head><meta charset="utf-8" /></head><body style="margin:0;padding:12px;">${header}${table}</body></html>`;
  const blob = new Blob(["\ufeff", html], { type: "application/vnd.ms-excel;charset=utf-8" });
  const anchor = document.createElement("a");
  anchor.href = URL.createObjectURL(blob);
  anchor.download = filename;
  anchor.click();
  URL.revokeObjectURL(anchor.href);
};

export const exportReportPdf = ({ report, filename, orientation = "landscape", classLabel = "Semua Kelas" }) => {
  const totals = reportStatusTotals(report);

  return exportAttendancePdf({
    filename,
    title: "Laporan Kehadiran Siswa",
    subtitle: report?.period?.label || "",
    orientation,
    meta: [
      `Kelas: ${classLabel}`,
      `Hadir: ${totals.hadir} • Terlambat: ${totals.terlambat} • Tidak hadir: ${totals.tidakHadir}`,
      `Belum tap pulang: ${totals.checkOutMissing}`,
    ],
    groups: (report?.classes || []).map((group) => ({
      title: group.name,
      subtitle: `Wali Kelas: ${group.homeroom || "-"} • ${group.total} data`,
      rows: group.rows,
    })),
  });
};
