import api from "./api";

export const checkApiHealth = async () => {
  const response = await api.get("/health");

  return response.data;
};