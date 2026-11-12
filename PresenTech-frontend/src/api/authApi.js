import api from "./api";

export const login = async (identifier, password) => {
  const response = await api.post("/login", {
    identifier,
    password,
  });
  return response.data;
};

export const logout = async () => {
  const response = await api.post("/logout");
  return response.data;
};

export const getCurrentUser = async () => {
  const response = await api.get("/me");
  return response.data;
};


export const changePassword = async (currentPassword, newPassword) => {
  const response = await api.post("/change-password", {
    current_password: currentPassword,
    password: newPassword,
    password_confirmation: newPassword,
  });
  return response.data;
};

export const updateProfile = async (data) => {
  const response = await api.put('/me', data);
  return response.data;
};
