// Export PDF laporan absensi dengan header sekolah dan footer halaman.
// jsPDF + jspdf-autotable dimuat dinamis (chunk terpisah) agar bundle utama
// tidak membengkak — hanya diunduh saat tombol Export PDF ditekan.

const NAVY = [11, 20, 36];
const ACCENT_GOLD = [217, 186, 120];
// Kop PDF tanpa latar warna: teks gelap di atas kertas putih.
const TEXT_DARK = [17, 28, 45];
const TEXT_MUTED = [88, 104, 126];
const ACCENT_GOLD_DARK = [148, 110, 44];
const HEADER_HEIGHT = 36;
const HEADER_META_Y = HEADER_HEIGHT + 4;
const LIGHT_ROWS = [238, 243, 250];
const STATUS_COLORS = {
  Hadir: [22, 132, 76],
  Terlambat: [198, 110, 10],
  Izin: [37, 99, 165],
  Sakit: [37, 99, 165],
  Alpa: [190, 32, 45],
  Dikoreksi: [110, 70, 10],
};

const FULL_COLUMNS = ["no", "nis", "name", "className", "date", "time", "check_out", "status"];
const FULL_LABELS = ["No", "NIS", "Nama", "Kelas", "Tanggal", "Jam Masuk", "Jam Pulang", "Status"];
const FULL_WIDTHS = {
  0: { cellWidth: 12, halign: "center" },
  1: { cellWidth: 22 },
  2: { cellWidth: 68 },
  3: { cellWidth: 34 },
  4: { cellWidth: 26, halign: "center" },
  5: { cellWidth: 18, halign: "center" },
  6: { cellWidth: 18, halign: "center" },
  7: { cellWidth: "auto", fontStyle: "bold" },
};
// Lebar kolom untuk A4 potret (ruang tabel hanya +/- 182 mm).
const FULL_WIDTHS_PORTRAIT = {
  0: { cellWidth: 10, halign: "center" },
  1: { cellWidth: 18 },
  2: { cellWidth: 50 },
  3: { cellWidth: 28 },
  4: { cellWidth: 22, halign: "center" },
  5: { cellWidth: 16, halign: "center" },
  6: { cellWidth: 16, halign: "center" },
  7: { cellWidth: "auto", fontStyle: "bold" },
};

const GROUP_COLUMNS = ["no", "nis", "name", "date", "time", "check_out", "status"];
const GROUP_LABELS = ["No", "NIS", "Nama", "Tanggal", "Jam Masuk", "Jam Pulang", "Status"];
const GROUP_WIDTHS = {
  0: { cellWidth: 12, halign: "center" },
  1: { cellWidth: 28 },
  2: { cellWidth: 92 },
  3: { cellWidth: 30, halign: "center" },
  4: { cellWidth: 22, halign: "center" },
  5: { cellWidth: 22, halign: "center" },
  6: { cellWidth: "auto", fontStyle: "bold" },
};
const GROUP_WIDTHS_PORTRAIT = {
  0: { cellWidth: 10, halign: "center" },
  1: { cellWidth: 24 },
  2: { cellWidth: 56 },
  3: { cellWidth: 26, halign: "center" },
  4: { cellWidth: 20, halign: "center" },
  5: { cellWidth: 20, halign: "center" },
  6: { cellWidth: "auto", fontStyle: "bold" },
};

const formatTimestamp = () => new Intl.DateTimeFormat("id-ID", {
  dateStyle: "long",
  timeStyle: "short",
}).format(new Date());

// Logo diambil dari public/ dan dikonversi ke dataURL saat ekspor.
// Kegagalan memuat logo tidak boleh membatalkan ekspor (tanpa logo saja).
export async function loadLogoDataUrl() {
  try {
    const response = await fetch(`${import.meta.env.BASE_URL}logo-light.png`, { cache: "force-cache" });
    if (!response.ok) return null;
    const blob = await response.blob();
    return await new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(String(reader.result));
      reader.onerror = () => reject(new Error("Gagal membaca logo."));
      reader.readAsDataURL(blob);
    });
  } catch {
    return null;
  }
}

function drawHeader(doc, { title, subtitle, logo }) {
  const pageWidth = doc.internal.pageSize.getWidth();
  const isPortrait = pageWidth < 220;
  const rightMargin = 15;
  const rightWidth = isPortrait ? 82 : 118;
  const rightX = pageWidth - rightMargin;

  // Kop tanpa latar warna: teks gelap di atas kertas putih, hanya garis
  // emas tipis di bagian bawah sebagai aksen.
  let textLeft = 16;

  if (logo) {
    try {
      doc.addImage(logo, "PNG", 16, 9, 16, 16);
      textLeft = 38;
    } catch {
      textLeft = 16;
    }
  }

  // Identitas instansi (kiri).
  doc.setTextColor(...TEXT_DARK);
  doc.setFont("helvetica", "bold");
  doc.setFontSize(isPortrait ? 15.5 : 17);
  doc.text("SMK TELESANDI", textLeft, 16.4);

  doc.setFont("helvetica", "normal");
  doc.setFontSize(8.3);
  doc.setTextColor(...TEXT_MUTED);
  doc.text("PresenTech - Sistem Absensi RFID & IoT", textLeft, 22.4);

  // Pemisah halus antara identitas dan judul dokumen.
  const dividerX = pageWidth - rightWidth - 11;

  if (dividerX > textLeft + 40) {
    doc.setDrawColor(210, 219, 230);
    doc.setLineWidth(0.35);
    doc.line(dividerX, 8.5, dividerX, HEADER_HEIGHT - 7);
  }

  // Judul dokumen (kanan): label, judul, garis emas, lalu rentang tanggal.
  doc.setFont("helvetica", "bold");
  doc.setFontSize(6.8);
  doc.setTextColor(...ACCENT_GOLD_DARK);
  doc.text("DOKUMEN RESMI", rightX, 10.6, { align: "right" });

  doc.setFont("helvetica", "bold");
  doc.setFontSize(isPortrait ? 12.5 : 14);
  doc.setTextColor(...TEXT_DARK);
  const titleLines = doc.splitTextToSize(String(title || "Laporan"), rightWidth).slice(0, 2);
  doc.text(titleLines, rightX, 16.8, { align: "right" });

  const ruleY = titleLines.length > 1 ? 24.9 : 20.9;

  doc.setFillColor(...ACCENT_GOLD);
  doc.rect(rightX - 18, ruleY - 1, 18, 0.9, "F");

  if (subtitle) {
    doc.setFont("helvetica", "normal");
    doc.setFontSize(8.2);
    doc.setTextColor(...TEXT_MUTED);
    const subtitleLines = doc
      .splitTextToSize(String(subtitle), rightWidth)
      .slice(0, titleLines.length > 1 ? 1 : 2);
    doc.text(subtitleLines, rightX, ruleY + 4.4, { align: "right" });
  }

  // Aksen emas tipis di dasar kop.
  doc.setFillColor(...ACCENT_GOLD);
  doc.rect(0, HEADER_HEIGHT - 1.2, pageWidth, 1.2, "F");
}

function drawMetaBar(doc, metaLines, startY) {
  if (!metaLines.length) return startY;

  const pageWidth = doc.internal.pageSize.getWidth();
  const height = metaLines.length * 4.6 + 3.2;

  doc.setFillColor(...LIGHT_ROWS);
  doc.rect(14, startY, pageWidth - 28, height, "F");

  doc.setFont("helvetica", "normal");
  doc.setFontSize(8.5);
  doc.setTextColor(70, 82, 100);

  let y = startY + 4.6;
  metaLines.forEach((line) => {
    doc.text(line, 17, y);
    y += 4.6;
  });

  return startY + height + 4;
}

function drawFooter(doc, generatedAt) {
  const pageWidth = doc.internal.pageSize.getWidth();
  const pageHeight = doc.internal.pageSize.getHeight();
  const total = doc.getNumberOfPages();

  for (let page = 1; page <= total; page += 1) {
    doc.setPage(page);

    const y = pageHeight - 8;

    doc.setDrawColor(200, 208, 220);
    doc.line(14, y - 4, pageWidth - 14, y - 4);

    doc.setFont("helvetica", "normal");
    doc.setFontSize(7.5);
    doc.setTextColor(110, 120, 136);
    doc.text(`Dihasilkan otomatis oleh PresenTech - ${generatedAt}`, 14, y);
    doc.text(`Halaman ${page} dari ${total}`, pageWidth - 14, y, { align: "right" });
  }
}

const STATUS_TEXT_COLORS = (status) => STATUS_COLORS[String(status) || ""] || null;

/**
 * PDF tabel umum (jadwal, kartu RFID, siswa, guru, kelas).
 * Columns: [{ key, label, width?, align? }] — urutan sama dengan isi baris.
 */
export async function exportTablePdf({
  filename = "presentech-data.pdf",
  title = "Data",
  subtitle,
  meta = [],
  columns = [],
  rows = [],
  orientation = "landscape",
}) {
  const [{ jsPDF }, { default: autoTable }] = await Promise.all([
    import("jspdf"),
    import("jspdf-autotable"),
  ]);

  const isPortrait = orientation === "portrait";
  const doc = new jsPDF({ orientation: isPortrait ? "portrait" : "landscape", unit: "mm", format: "a4" });
  const logo = await loadLogoDataUrl();
  const generatedAt = formatTimestamp();

  drawHeader(doc, { title, subtitle: subtitle || `Dibuat: ${generatedAt}`, logo });

  const metaLines = rows.length
    ? [`Total data: ${rows.length}`, ...meta]
    : ["Tidak ada data pada filter ini.", ...meta];
  const startY = drawMetaBar(doc, metaLines, HEADER_META_Y);

  const cell = (row, key) => {
    const value = typeof key === "string" && key.includes(".") ? key.split(".").reduce((acc, part) => acc?.[part], row) : row?.[key];

    return value ?? "";
  };

  const statusKeys = columns.filter((column) => /status/i.test(column.key)).map((column) => column.key);
  const boldKeys = columns.filter((column) => column.bold || column.key === "name").map((column) => column.key);

  autoTable(doc, {
    head: [columns.map((column) => column.label)],
    body: rows.map((row) => columns.map((column) => cell(row, column.key))),
    startY,
    margin: { left: 14, right: 14 },
    styles: { font: "helvetica", fontSize: 8.5, cellPadding: 1.8, textColor: [42, 52, 68], lineColor: [222, 228, 238], lineWidth: 0.1 },
    headStyles: { fillColor: NAVY, textColor: [255, 255, 255], fontStyle: "bold", halign: "left" },
    alternateRowStyles: { fillColor: LIGHT_ROWS },
    columnStyles: Object.fromEntries(columns.map((column, index) => [
      index,
      { ...(column.width ? { cellWidth: column.width } : {}), ...(column.align ? { halign: column.align } : {}) },
    ])),
    didParseCell: (data) => {
      if (data.section !== "body") return;

      const key = columns[data.column.index]?.key;

      if (statusKeys.includes(key)) {
        const color = STATUS_TEXT_COLORS(data.cell.raw);
        if (color) data.cell.styles.textColor = color;
      }
      if (boldKeys.includes(key)) {
        data.cell.styles.fontStyle = "bold";
      }
    },
  });

  drawFooter(doc, generatedAt);
  doc.save(filename);
}

/**
 * Rows: [{nis, name, className, date, time, status}].
 * Groups (opsional): [{title, subtitle, rows}] — dipakai laporan per kelas,
 * mis. saat opsi "Semua Kelas" dipilih; tiap kelas punya judul + wali kelas.
 * Meta: pasangan string "Label: Nilai" yang ditampilkan di bawah header.
 */
export async function exportAttendancePdf({
  filename = "presentech-laporan.pdf",
  title,
  subtitle,
  meta = [],
  rows = [],
  groups = null,
  orientation = "landscape",
}) {
  const [{ jsPDF }, { default: autoTable }] = await Promise.all([
    import("jspdf"),
    import("jspdf-autotable"),
  ]);

  const isPortrait = orientation === "portrait";
  const doc = new jsPDF({ orientation: isPortrait ? "portrait" : "landscape", unit: "mm", format: "a4" });
  const fullWidths = isPortrait ? FULL_WIDTHS_PORTRAIT : FULL_WIDTHS;
  const groupWidths = isPortrait ? GROUP_WIDTHS_PORTRAIT : GROUP_WIDTHS;
  const logo = await loadLogoDataUrl();
  const generatedAt = formatTimestamp();
  const pageHeight = doc.internal.pageSize.getHeight();

  drawHeader(doc, { title, subtitle: subtitle || `Dibuat: ${generatedAt}`, logo });

  const grouped = Array.isArray(groups) && groups.length > 0;
  const totalRows = grouped ? groups.reduce((sum, group) => sum + group.rows.length, 0) : rows.length;

  const metaLines = totalRows || grouped
    ? [`Total data: ${totalRows}`, ...meta]
    : ["Tidak ada data pada periode/filter ini.", ...meta];
  const metaEndY = drawMetaBar(doc, metaLines, HEADER_META_Y);

  const drawTable = ({ tableRows, columns, labels, widths, startY }) => {
    autoTable(doc, {
      head: [labels],
      body: tableRows.map((row) => columns.map((key) => {
        const value = key === "check_out" ? (row.check_out ?? row.checkOut ?? "-") : row[key];

        return value ?? "";
      })),
      startY,
      margin: { left: 14, right: 14 },
      styles: { font: "helvetica", fontSize: 8.5, cellPadding: 1.8, textColor: [42, 52, 68], lineColor: [222, 228, 238], lineWidth: 0.1 },
      headStyles: { fillColor: NAVY, textColor: [255, 255, 255], fontStyle: "bold", halign: "left" },
      alternateRowStyles: { fillColor: LIGHT_ROWS },
      columnStyles: widths,
      didParseCell: (data) => {
        if (data.section !== "body") return;
        if (data.column.index === columns.indexOf("status")) {
          const color = STATUS_COLORS[String(data.cell.raw) || ""];
          if (color) {
            data.cell.styles.textColor = color;
          }
        }
        if (data.column.index === columns.indexOf("name")) {
          data.cell.styles.fontStyle = "bold";
        }
      },
    });

    return doc.lastAutoTable ? doc.lastAutoTable.finalY : startY;
  };

  if (!grouped) {
    if (rows.length) {
      drawTable({ tableRows: rows, columns: FULL_COLUMNS, labels: FULL_LABELS, widths: fullWidths, startY: metaEndY });
    }
    drawFooter(doc, generatedAt);
    doc.save(filename);

    return;
  }

  let y = metaEndY;

  groups.forEach((group, index) => {
    if (index > 0 && y > pageHeight - 42) {
      doc.addPage();
      y = 20;
    }

    doc.setFont("helvetica", "bold");
    doc.setFontSize(10.5);
    doc.setTextColor(...NAVY);
    doc.text(String(group.title || "-"), 14, y);
    y += 5;

    if (group.subtitle) {
      doc.setFont("helvetica", "normal");
      doc.setFontSize(8.5);
      doc.setTextColor(90, 100, 116);
      doc.text(String(group.subtitle), 14, y);
      y += 5;
    }

    if (group.rows.length) {
      y = drawTable({ tableRows: group.rows, columns: GROUP_COLUMNS, labels: GROUP_LABELS, widths: groupWidths, startY: y }) + 8;
    } else {
      doc.setFont("helvetica", "italic");
      doc.setFontSize(8.5);
      doc.setTextColor(130, 140, 155);
      doc.text("Tidak ada data pada kelas ini.", 16, y);
      y += 8;
    }
  });

  drawFooter(doc, generatedAt);
  doc.save(filename);
}
