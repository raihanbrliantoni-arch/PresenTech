import api from "./api";

// GET laporan absensi terkelompok per kelas
// (period: day|week|month|3months|6months|year|semester|range).
export const getAttendanceReport = async (params = {}) => {
  const response = await api.get("/reports/attendance", { params });

  return response.data;
};