import api from "./api";

// Ajukan reset password. Server hanya membuat permintaan berstatus PENDING;
// Admin harus menyetujui sebelum password benar-benar direset.
export const requestPasswordReset = async (identifier, role) => {
  const response = await api.post("/password-reset/requests", {
    identifier,
    role,
  });
  return response.data;
};

// Cek status memakai id + token klaim rahasia (bukan NIS/NIP) agar
// identitas pengguna tidak bisa dienumerasi pihak lain.
export const getPasswordResetRequest = async (requestId, token) => {
  const response = await api.get("/password-reset/requests", {
    params: { request_id: requestId, token },
  });
  return response.data;
};

export const getAdminPasswordResetRequests = async (params = {}) => {
  const response = await api.get('/admin/password-reset/requests', { params });
  return response.data;
};

export const approvePasswordReset = async (requestId) => {
  const response = await api.post(`/admin/password-reset/requests/${requestId}/approve`);
  return response.data;
};

export const rejectPasswordReset = async (requestId, reviewNote = '') => {
  const response = await api.post(`/admin/password-reset/requests/${requestId}/reject`, {
    review_note: reviewNote,
  });
  return response.data;
};

// Reset password langsung oleh Admin. Tanpa parameter password, password
// sementara disamakan dengan NIS/NIP dan wajib diganti saat login.
export const adminSetPassword = async (userId, password = null) => {
  const response = await api.post(`/admin/users/${userId}/set-password`, password ? { password } : {});
  return response.data;
};