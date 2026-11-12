import api from "./api";

// GET semua guru
export const getTeachers = async (params = {}) => {
  const response = await api.get("/teachers", {
    params,
  });

  return response.data;
};

// POST tambah guru
export const createTeacher = async (data) => {
  const response = await api.post("/teachers", data);

  return response.data;
};

// PUT update guru
export const updateTeacher = async (id, data) => {
  const response = await api.put(`/teachers/${id}`, data);

  return response.data;
};

// DELETE guru
export const deleteTeacher = async (id) => {
  const response = await api.delete(`/teachers/${id}`);

  return response.data;
};

// POST angkat guru menjadi admin
export const promoteTeacher = async (id) => {
  const response = await api.post(`/teachers/${id}/promote`);

  return response.data;
};

// POST cabut akses admin dari guru
export const demoteTeacher = async (id) => {
  const response = await api.post(`/teachers/${id}/demote`);

  return response.data;
};
// POST buat akun login untuk guru (khusus Admin)
export const createTeacherAccount = async (id) => {
  const response = await api.post(`/teachers/${id}/account`);

  return response.data;
};
