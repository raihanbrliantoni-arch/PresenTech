import api from "./api";

// Kalender hari libur. Index bisa dibaca Admin/Guru, perubahan hanya Admin.
export const getHolidays = async (params = {}) => {
  const response = await api.get("/holidays", { params });

  return response.data;
};

export const getTodayHoliday = async () => {
  const response = await api.get("/holidays/today");

  return response.data;
};

export const createHoliday = async (data) => {
  const response = await api.post("/holidays", data);

  return response.data;
};

export const updateHoliday = async (id, data) => {
  const response = await api.put(`/holidays/${id}`, data);

  return response.data;
};

export const deleteHoliday = async (id) => {
  const response = await api.delete(`/holidays/${id}`);

  return response.data;
};
