import api from "./api";

// GET semua device
export const getDevices = async (params = {}) => {
  const response = await api.get("/devices", {
    params,
  });

  return response.data;
};

// POST device
export const createDevice = async (data) => {
  const response = await api.post("/devices", data);

  return response.data;
};

// PUT device
export const updateDevice = async (id, data) => {
  const response = await api.put(`/devices/${id}`, data);

  return response.data;
};

// DELETE device
export const deleteDevice = async (id) => {
  const response = await api.delete(`/devices/${id}`);

  return response.data;
};

// Restart device
export const restartDevice = async (id) => {
  const response = await api.post(
    `/devices/${id}/restart`
  );

  return response.data;
};

// Rotate device key — key baru dikembalikan sekali (device_key_plain).
export const rotateDeviceKey = async (id) => {
  const response = await api.post(
    `/devices/${id}/rotate-key`
  );

  return response.data;
};

// Mulai sesi baca UID di perangkat (Admin).
export const requestDeviceScan = async (id) => {
  const response = await api.post(`/devices/${id}/scan`);

  return response.data;
};

// Status sesi baca UID untuk polling (Admin).
export const getDeviceScanStatus = async (id) => {
  const response = await api.get(`/devices/${id}/scan`);

  return response.data;
};

// Batalkan sesi baca UID dan bersihkan hasilnya (Admin).
export const cancelDeviceScan = async (id) => {
  const response = await api.delete(`/devices/${id}/scan`);

  return response.data;
};

// Pratinjau teks yang akan tampil di layar perangkat (nama dipendekkan,
// label sekolah, tema, dan status jam saat ini).
export const getDevicePreview = async (id) => {
  const response = await api.get(`/devices/${id}/preview`);

  return response.data;
};

// Ganti WiFi perangkat: server menyimpan SSID/password, alat mengambilnya
// pada heartbeat berikutnya lalu restart sendiri.
export const setDeviceWifi = async (id, data) => {
  const response = await api.post(`/devices/${id}/wifi`, data);

  return response.data;
};