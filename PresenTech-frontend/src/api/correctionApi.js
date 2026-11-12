import api from "./api";

// GET koreksi absensi
export const getCorrections = async (params = {}) => {
  const response = await api.get("/attendance-corrections", {
    params,
  });

  return response.data;
};

// POST pengajuan koreksi
export const createCorrection = async (data) => {
  const response = await api.post(
    "/attendance-corrections",
    data
  );

  return response.data;
};

// PUT koreksi
export const updateCorrection = async (id, data) => {
  const response = await api.put(
    `/attendance-corrections/${id}`,
    data
  );

  return response.data;
};

// DELETE pengajuan koreksi (pemilik, selama masih menunggu)
export const deleteCorrection = async (id) => {
  const response = await api.delete(`/attendance-corrections/${id}`);

  return response.data;
};
