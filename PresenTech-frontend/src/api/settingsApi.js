import api from "./api";

// Pengaturan sekolah tersimpan di server (satu baris).
export const getSettings = async () => {
  const response = await api.get("/settings");

  return response.data;
};

// Hanya Admin yang boleh mengubah pengaturan.
export const updateSettings = async (data) => {
  const response = await api.put("/settings", data);

  return response.data;
};
