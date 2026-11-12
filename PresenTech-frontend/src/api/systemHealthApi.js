import api from "./api";

// Ringkasan kesehatan sistem (scheduler, perangkat, absensi hari ini,
// dan status backup otomatis). Khusus Admin.
export const getSystemHealth = async () => {
  const response = await api.get("/system/health");

  return response.data;
};
