import api from "./api";

// GET kartu RFID
export const getRfidCards = async (params = {}) => {
  const response = await api.get("/rfid-cards", {
    params,
  });

  return response.data;
};

// POST RFID
export const createRfidCard = async (data) => {
  const response = await api.post("/rfid-cards", data);

  return response.data;
};

// PUT RFID
export const updateRfidCard = async (id, data) => {
  const response = await api.put(`/rfid-cards/${id}`, data);

  return response.data;
};

// DELETE RFID
export const deleteRfidCard = async (id) => {
  const response = await api.delete(`/rfid-cards/${id}`);

  return response.data;
};