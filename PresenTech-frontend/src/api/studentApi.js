import api from "./api";

// GET semua siswa
export const getStudents = async (params = {}) => {
  const response = await api.get("/students", {
    params,
  });

  return response.data;
};

// POST tambah siswa
export const createStudent = async (data) => {
  const response = await api.post("/students", data);

  return response.data;
};

// PUT update siswa
export const updateStudent = async (id, data) => {
  const response = await api.put(`/students/${id}`, data);

  return response.data;
};

// DELETE siswa
export const deleteStudent = async (id) => {
  const response = await api.delete(`/students/${id}`);

  return response.data;
};
// POST buat akun login untuk siswa (khusus Admin)
export const createStudentAccount = async (id) => {
  const response = await api.post(`/students/${id}/account`);

  return response.data;
};

// POST atur nomor absen otomatis per kelas (urut nama A-Z)
export const autoNumberStudents = async (params = {}) => {
  const response = await api.post("/students/auto-number", params);

  return response.data;
};
