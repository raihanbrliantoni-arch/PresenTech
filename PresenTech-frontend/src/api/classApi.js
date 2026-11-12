import api from "./api";

// GET semua kelas
export const getClasses = async (params = {}) => {
  const response = await api.get("/classes", {
    params,
  });

  return response.data;
};

// POST tambah kelas
export const createClass = async (data) => {
  const response = await api.post("/classes", data);

  return response.data;
};

// PUT update kelas
export const updateClass = async (id, data) => {
  const response = await api.put(`/classes/${id}`, data);

  return response.data;
};

// DELETE kelas
export const deleteClass = async (id) => {
  const response = await api.delete(`/classes/${id}`);

  return response.data;
};