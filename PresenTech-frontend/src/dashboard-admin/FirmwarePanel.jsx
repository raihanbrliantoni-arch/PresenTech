import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { AlertTriangle, CheckCircle2, Cpu, Loader2, RefreshCw, RotateCcw, Trash2, Upload, X } from "lucide-react";

import {
  cancelDeviceOta,
  deleteFirmware,
  getFirmwares,
  pushDeviceOta,
  uploadFirmware,
} from "../api/firmwareApi";
import { toast } from "../lib/toast";

const formatSize = (bytes) => {
  const value = Number(bytes) || 0;
  if (value >= 1024 * 1024) return `${(value / (1024 * 1024)).toFixed(2)} MB`;
  if (value >= 1024) return `${(value / 1024).toFixed(0)} KB`;

  return `${value} B`;
};

const formatStamp = (value) => {
  if (!value) return "-";
  const date = new Date(value);

  return Number.isNaN(date.getTime())
    ? "-"
    : date.toLocaleString("id-ID", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });
};

const OTA_TONE = {
  pending: "tone-warn",
  downloading: "tone-warn",
  success: "tone-ok",
  failed: "tone-danger",
  canceled: "tone-muted",
};

/**
 * Panel firmware OTA di Monitoring: unggah file .bin, pilih versi, lalu
 * kirim pembaruan ke perangkat. Status perangkat mengikuti laporan alat
 * (pending → downloading → success/failed) lewat heartbeat HTTP atau MQTT.
 */
export default function FirmwarePanel({ devices = [] }) {
  const [firmwares, setFirmwares] = useState([]);
  const [latest, setLatest] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [version, setVersion] = useState("");
  const [notes, setNotes] = useState("");
  const [file, setFile] = useState(null);
  const [targetId, setTargetId] = useState("latest");
  const [busy, setBusy] = useState("");
  const fileInput = useRef(null);

  const load = useCallback(async () => {
    try {
      const result = await getFirmwares();
      setFirmwares(result?.firmwares || []);
      setLatest(result?.latest || null);
      setError("");
    } catch (err) {
      setError(err?.response?.data?.message || "Daftar firmware gagal dimuat.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const activeFirmwares = useMemo(() => firmwares.filter((item) => item.is_active), [firmwares]);
  const targetFirmware = targetId === "latest"
    ? activeFirmwares.find((item) => item.version === latest) || activeFirmwares[0] || null
    : activeFirmwares.find((item) => String(item.id) === String(targetId)) || null;

  const submitUpload = async (event) => {
    event.preventDefault();

    if (!file) {
      toast.error("Pilih file .bin terlebih dahulu.");
      return;
    }

    setBusy("upload");
    try {
      const result = await uploadFirmware({ version: version.trim(), notes: notes.trim(), file });
      toast.success(result?.message || "Firmware berhasil diunggah.");
      setVersion("");
      setNotes("");
      setFile(null);
      if (fileInput.current) fileInput.current.value = "";
      await load();
    } catch (err) {
      const errors = err?.response?.data?.errors;
      const message = errors ? Object.values(errors).flat()[0] : err?.response?.data?.message;
      toast.error(message || "Firmware gagal diunggah.");
    } finally {
      setBusy("");
    }
  };

  const removeFirmware = async (item) => {
    if (!window.confirm(`Hapus firmware ${item.version}? File di server ikut dihapus.`)) return;

    setBusy(`delete-${item.id}`);
    try {
      await deleteFirmware(item.id);
      toast.success(`Firmware ${item.version} dihapus.`);
      await load();
    } catch (err) {
      toast.error(err?.response?.data?.message || "Firmware gagal dihapus.");
    } finally {
      setBusy("");
    }
  };

  const sendOta = async (device) => {
    if (!targetFirmware) {
      toast.error("Unggah firmware terlebih dahulu.");
      return;
    }

    setBusy(`ota-${device.id}`);
    try {
      const result = await pushDeviceOta(device.id, targetFirmware.id);
      toast.success(result?.message || "Perintah OTA dikirim.");
    } catch (err) {
      toast.error(err?.response?.data?.message || "Perintah OTA gagal dikirim.");
    } finally {
      setBusy("");
    }
  };

  const cancelOta = async (device) => {
    setBusy(`cancel-${device.id}`);
    try {
      await cancelDeviceOta(device.id);
      toast.success("Permintaan OTA dibatalkan.");
    } catch (err) {
      toast.error(err?.response?.data?.message || "Permintaan OTA gagal dibatalkan.");
    } finally {
      setBusy("");
    }
  };

  return (
    <section className="panel firmware-panel">
      <div className="panel-header">
        <div>
          <h3>Firmware &amp; OTA</h3>
          <p>Unggah file .bin sekali, lalu kirim ke alat tanpa mencabut perangkat.</p>
        </div>
        <button type="button" className="text-button" onClick={() => { setLoading(true); load(); }} disabled={loading}>
          <RefreshCw size={14} className={loading ? "spin" : ""} /> Muat ulang
        </button>
      </div>

      {error && <div className="setting-help setting-help-error">{error}</div>}

      <form className="firmware-upload" onSubmit={submitUpload}>
        <label className="report-filter-field">
          <span>Versi firmware</span>
          <input
            type="text"
            placeholder="1.2.0"
            value={version}
            onChange={(event) => setVersion(event.target.value)}
            required
            pattern="[0-9]+\.[0-9]+\.[0-9]+([-+][0-9A-Za-z.-]+)?"
            title="Format versi: 1.2.0"
          />
        </label>
        <label className="report-filter-field">
          <span>File .bin</span>
          <input
            ref={fileInput}
            type="file"
            accept=".bin"
            onChange={(event) => setFile(event.target.files?.[0] || null)}
          />
        </label>
        <label className="report-filter-field firmware-notes">
          <span>Catatan rilis (opsional)</span>
          <input
            type="text"
            placeholder="Perbaikan jam pulang & LCD"
            value={notes}
            onChange={(event) => setNotes(event.target.value)}
          />
        </label>
        <button type="submit" className="primary-button" disabled={busy === "upload"}>
          {busy === "upload" ? <Loader2 size={15} className="spin" /> : <Upload size={15} />} Unggah
        </button>
      </form>

      <div className="firmware-grid">
        <div className="firmware-card">
          <div className="firmware-card-head">
            <strong>Versi tersedia</strong>
            <span className="report-table-note">{latest ? `Terbaru ${latest}` : "Belum ada firmware"}</span>
          </div>

          {loading && !firmwares.length ? (
            <div className="empty">Memuat firmware...</div>
          ) : firmwares.length ? (
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Versi</th>
                    <th>Ukuran</th>
                    <th>Diunggah</th>
                    <th>Status</th>
                    <th style={{ width: 52 }} aria-label="Aksi" />
                  </tr>
                </thead>
                <tbody>
                  {firmwares.map((item) => (
                    <tr key={item.id}>
                      <td>
                        <strong>{item.version}</strong>
                        {item.notes && <small className="firmware-note">{item.notes}</small>}
                      </td>
                      <td>{formatSize(item.size)}</td>
                      <td>{formatStamp(item.created_at)}</td>
                      <td>
                        {!item.file_available ? (
                          <span className="report-rate low">File hilang</span>
                        ) : item.is_active ? (
                          <span className="report-rate good">Aktif</span>
                        ) : (
                          <span className="report-rate warn">Nonaktif</span>
                        )}
                      </td>
                      <td>
                        <div className="row-actions">
                          <button
                            type="button"
                            title="Hapus firmware"
                            onClick={() => removeFirmware(item)}
                            disabled={busy === `delete-${item.id}`}
                          >
                            {busy === `delete-${item.id}` ? <Loader2 size={14} className="spin" /> : <Trash2 size={14} />}
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <div className="empty">Belum ada firmware. Unggah file .bin hasil build Arduino/PlatformIO.</div>
          )}
        </div>

        <div className="firmware-card">
          <div className="firmware-card-head">
            <strong>Perangkat</strong>
            <label className="firmware-target">
              <span>Kirim versi</span>
              <select value={targetId} onChange={(event) => setTargetId(event.target.value)}>
                <option value="latest">Terbaru{latest ? ` (${latest})` : ""}</option>
                {activeFirmwares.map((item) => (
                  <option key={item.id} value={item.id}>{item.version}</option>
                ))}
              </select>
            </label>
          </div>

          {devices.length ? devices.map((device) => {
            const isUpToDate = device.firmware_version && device.firmware_latest
              && device.firmware_version === device.firmware_latest;
            const pending = Boolean(device.ota_requested_at);

            return (
              <div className="firmware-device" key={device.id}>
                <div className="firmware-device-info">
                  <strong>{device.name}</strong>
                  <small>
                    <Cpu size={12} /> FW {device.firmware_version || "-"}
                    {device.firmware_latest ? ` • tersedia ${device.firmware_latest}` : ""}
                  </small>
                  {device.ota_status && (
                    <small className={`firmware-ota-status ${OTA_TONE[device.ota_status] || "tone-muted"}`}>
                      {device.ota_status === "success" && <CheckCircle2 size={12} />}
                      {device.ota_status === "failed" && <AlertTriangle size={12} />}
                      {pending && <Loader2 size={12} className="spin" />}
                      {device.ota_status}
                      {device.ota_reported_at ? ` • ${formatStamp(device.ota_reported_at)}` : ""}
                      {device.ota_message ? ` — ${device.ota_message}` : ""}
                    </small>
                  )}
                </div>
                <div className="firmware-device-actions">
                  {pending ? (
                    <button
                      type="button"
                      className="secondary-button"
                      onClick={() => cancelOta(device)}
                      disabled={busy === `cancel-${device.id}`}
                    >
                      {busy === `cancel-${device.id}` ? <Loader2 size={14} className="spin" /> : <X size={14} />} Batalkan
                    </button>
                  ) : (
                    <button
                      type="button"
                      className="secondary-button"
                      onClick={() => sendOta(device)}
                      disabled={!targetFirmware || isUpToDate || busy === `ota-${device.id}`}
                      title={isUpToDate ? "Perangkat sudah memakai versi ini" : undefined}
                    >
                      {busy === `ota-${device.id}` ? <Loader2 size={14} className="spin" /> : <RotateCcw size={14} />}
                      {isUpToDate ? "Sudah terbaru" : "Kirim OTA"}
                    </button>
                  )}
                </div>
              </div>
            );
          }) : <div className="empty">Belum ada perangkat terdaftar.</div>}
        </div>
      </div>

      <div className="setting-help">
        <strong>Tips OTA:</strong> build firmware menjadi file <code>.bin</code> (Arduino IDE: <em>Sketch → Export Compiled Binary</em>;
        PlatformIO: hasil di <code>.pio/build/&lt;env&gt;/firmware.bin</code>). Alat mengunduh lewat URL bertanda tangan yang berlaku 12 jam,
        memverifikasi <strong>SHA-256</strong>, lalu restart. Bila server diakses lewat MQTT dari jaringan lain, isi
        <code>PRESENTECH_FIRMWARE_URL</code> dengan alamat LAN server agar tautan unduhan bisa dijangkau alat.
      </div>
    </section>
  );
}
