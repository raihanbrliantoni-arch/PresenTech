import api from "./api";

// GET absensi
export const getAttendances = async (params = {}) => {
  const response = await api.get("/attendance", {
    params,
  });

  return response.data;
};

// POST absensi
export const createAttendance = async (data) => {
  const response = await api.post("/attendance", data);

  return response.data;
};