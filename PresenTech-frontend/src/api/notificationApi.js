import api from "./api";

// GET notifikasi
export const getNotifications = async (params = {}) => {
  const response = await api.get("/notifications", {
    params,
  });

  return response.data;
};

// Tandai satu sudah dibaca
export const markNotificationRead = async (id) => {
  const response = await api.post(
    `/notifications/${id}/read`
  );

  return response.data;
};

// Tandai semua sudah dibaca
export const markAllNotificationsRead = async () => {
  const response = await api.post(
    "/notifications/read-all"
  );

  return response.data;
};