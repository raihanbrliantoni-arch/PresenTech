import api from "./api";
export const getSystemLogs = async (params = {}) => (await api.get("/system-logs", { params })).data;
