import api from "./api";

// Registry firmware OTA (khusus Admin).
export const getFirmwares = async () => {
  const response = await api.get("/firmwares");

  return response.data;
};

// Unggah file .bin dengan versi semver, mis. "1.2.0".
export const uploadFirmware = async ({ version, notes, file }) => {
  const form = new FormData();
  form.append("version", version);
  if (notes) form.append("notes", notes);
  form.append("file", file);

  const response = await api.post("/firmwares", form);

  return response.data;
};

export const updateFirmware = async (id, data) => {
  const response = await api.put(`/firmwares/${id}`, data);

  return response.data;
};

export const deleteFirmware = async (id) => {
  const response = await api.delete(`/firmwares/${id}`);

  return response.data;
};

// Kirim perintah OTA ke satu perangkat. Tanpa firmware_id dipakai versi aktif terbaru.
export const pushDeviceOta = async (deviceId, firmwareId) => {
  const response = await api.post(`/devices/${deviceId}/ota`, {
    firmware_id: firmwareId ?? undefined,
  });

  return response.data;
};

export const cancelDeviceOta = async (deviceId) => {
  const response = await api.delete(`/devices/${deviceId}/ota`);

  return response.data;
};
