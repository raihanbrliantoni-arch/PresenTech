import { documentHeaderHtml, downloadCsvFile } from "./reportExport";
import { exportTablePdf as buildTablePdf } from "./pdf";

const ORIENTATION_KEY = "presentechPdfOrientation";

const escapeHtml = (value) => String(value ?? "").replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;");

const pad = (value) => String(value).padStart(2, "0");

const todayStamp = () => {
  const date = new Date();

  return `${date.getFullYear()}${pad(date.getMonth() + 1)}${pad(date.getDate())}`;
};

// Nama file: presentech-jadwal-20261105.
export const tableFileBase = (base) => `${base}-${todayStamp()}`;

export const readOrientation = () => {
  try {
    return localStorage.getItem(ORIENTATION_KEY) || "landscape";
  } catch {
    return "landscape";
  }
};

export const saveOrientation = (value) => {
  try {
    localStorage.setItem(ORIENTATION_KEY, value);
  } catch {
    // localStorage diblokir: cukup pakai nilai di state.
  }
};

const cellValue = (row, key) => {
  const value = typeof key === "string" && key.includes(".")
    ? key.split(".").reduce((acc, part) => acc?.[part], row)
    : row?.[key];

  return value ?? "";
};

export const exportTableCsv = ({ rows, columns, filename }) => {
  downloadCsvFile(
    filename,
    columns.map((column) => column.label),
    rows.map((row) => columns.map((column) => cellValue(row, column.key))),
  );
};

// Excel (.xls) dibuat dari tabel HTML — dibuka rapi di Excel/LibreOffice
// tanpa menambah dependensi baru.
export const exportTableExcel = async ({ rows, columns, filename, title, subtitle }) => {
  const head = columns.map((column) => `<th style="background:#0b1424;color:#ffffff;padding:6px 8px;text-align:left;border:1px solid #24374f;">${escapeHtml(column.label)}</th>`).join("");
  const body = rows
    .map((row) => `<tr>${columns.map((column) => `<td style="padding:4px 8px;border:1px solid #dfe6ee;">${escapeHtml(cellValue(row, column.key))}</td>`).join("")}</tr>`)
    .join("");
  const header = await documentHeaderHtml({ title: title || "Data", period: subtitle || "", detail: `${rows.length} baris data` });
  const html = `<html><head><meta charset="utf-8" /></head><body style="margin:0;padding:12px;">${header}<table border="1" style="border-collapse:collapse;font-family:Calibri,Arial,sans-serif;font-size:11px;"><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table></body></html>`;

  const blob = new Blob(["\ufeff", html], { type: "application/vnd.ms-excel;charset=utf-8" });
  const anchor = document.createElement("a");
  anchor.href = URL.createObjectURL(blob);
  anchor.download = filename;
  anchor.click();
  URL.revokeObjectURL(anchor.href);
};

export const exportTablePdf = ({ rows, columns, filename, title, subtitle, meta, orientation }) => buildTablePdf({
  filename,
  title,
  subtitle,
  meta,
  columns,
  rows,
  orientation,
});
