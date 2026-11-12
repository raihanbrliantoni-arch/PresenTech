import { useEffect, useMemo, useState } from "react";
import { Download, FileSpreadsheet, FileText, Loader2, X } from "lucide-react";

import { exportTableCsv, exportTableExcel, exportTablePdf, readOrientation, saveOrientation, tableFileBase } from "../lib/tableExport";

/**
 * Modal export generik untuk halaman yang sebelumnya hanya punya tombol
 * "Export" (CSV langsung unduh). Tampilannya sengaja sama dengan Export
 * Absensi: ringkasan data, pilihan format PDF/Excel/CSV, dan orientasi PDF,
 * ditambah filter halaman (kelas, hari, status) lewat prop `filters`.
 *
 * Props:
 * - rows: array data yang sudah difilter halaman induk.
 * - columns: [{ key, label, width?, align? }] — urutan & label kolom file.
 * - title/subtitle: judul modal & judul kepala file (PDF/Excel).
 * - meta: fungsi (jumlah baris) => string[] untuk baris ringkasan di PDF.
 * - filenameBase: nama file tanpa ekstensi.
 * - filters: [{ id, label, value, options:[{ value, label }], onChange }]
 * - emptyText: pesan bila tidak ada data.
 * - appliedNote: catatan kecil di bawah ringkasan (mis. filter tabel aktif).
 */
export default function ExportDataModal({
  open,
  onClose,
  title,
  subtitle,
  rows = [],
  columns = [],
  meta,
  filenameBase = "presentech-data",
  filters = [],
  emptyText = "Tidak ada data untuk di-export.",
  appliedNote = "",
}) {
  const [format, setFormat] = useState("pdf");
  const [orientation, setOrientation] = useState(readOrientation);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (!open) return undefined;

    const onKeyDown = (event) => {
      if (event.key === "Escape") onClose();
    };

    window.addEventListener("keydown", onKeyDown);

    return () => window.removeEventListener("keydown", onKeyDown);
  }, [open, onClose]);

  // Nama file mengikuti judul + tanggal unduh supaya mudah dicari.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const fileBase = useMemo(() => tableFileBase(filenameBase), [filenameBase, open]);

  if (!open) return null;

  const exportable = columns.length > 0 && rows.length > 0;
  const disabled = busy || !exportable;

  const runExport = async () => {
    if (!exportable) return;

    setBusy(true);

    try {
      if (format === "pdf") {
        await exportTablePdf({
          rows,
          columns,
          filename: `${fileBase}.pdf`,
          orientation,
          title,
          subtitle,
          meta: meta?.(rows.length) || [],
        });
      } else if (format === "excel") {
        await exportTableExcel({ rows, columns, filename: `${fileBase}.xls`, title, subtitle });
      } else {
        exportTableCsv({ rows, columns, filename: `${fileBase}.csv` });
      }
    } catch {
      // Biarkan modal tetap terbuka; pengguna bisa mencoba format lain.
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="modal-backdrop" onClick={onClose} role="presentation">
      <div className="modal export-modal" onClick={(event) => event.stopPropagation()} role="dialog" aria-modal="true" aria-label={title}>
        <div className="modal-head">
          <div>
            <h2>{title}</h2>
            <p>{subtitle}</p>
          </div>
          <button type="button" className="modal-close" onClick={onClose} aria-label="Tutup">
            <X size={18} />
          </button>
        </div>

        {filters.length > 0 && (
          <div className={`export-form ${filters.length === 1 ? "single" : ""}`}>
            {filters.map((filter) => (
              <label key={filter.id}>
                <span>{filter.label}</span>
                <select value={filter.value} onChange={(event) => filter.onChange(event.target.value)}>
                  {filter.options.map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </select>
              </label>
            ))}
          </div>
        )}

        <div className="export-summary">
          <span>
            <strong>{rows.length}</strong> baris data siap di-export
            {columns.length > 0 && <span className="export-status-counts"><b className="netral">{columns.length} kolom</b></span>}
          </span>
        </div>

        {appliedNote && <p className="export-note">{appliedNote}</p>}
        {!exportable && <p className="export-note">{rows.length ? "Kolom export belum disiapkan." : emptyText}</p>}

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
            {busy ? <Loader2 size={15} className="spin" /> : <Download size={15} />}
            {busy ? "Menyiapkan..." : `Unduh ${format.toUpperCase()}`}
          </button>
        </div>
      </div>
    </div>
  );
}
