import api from "./api";

// GET jadwal
export const getSchedules = async (params = {}) => {
  const response = await api.get("/schedules", {
    params,
  });

  return response.data;
};

// POST jadwal
export const createSchedule = async (data) => {
  const response = await api.post("/schedules", data);

  return response.data;
};

// PUT jadwal
export const updateSchedule = async (id, data) => {
  const response = await api.put(`/schedules/${id}`, data);

  return response.data;
};

// DELETE jadwal
export const deleteSchedule = async (id) => {
  const response = await api.delete(`/schedules/${id}`);

  return response.data;
};