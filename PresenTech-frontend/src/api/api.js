import axios from "axios";
import { clearPresenTechSession } from "./session";

// Build produksi disajikan satu origin dengan API Laravel (http://host/app),
// jadi pakai path relatif agar tetap jalan lewat localhost, IP LAN, atau tunnel.
const defaultBaseURL = import.meta.env.PROD
  ? "/api"
  : import.meta.env.VITE_API_URL || "http://127.0.0.1:8000/api";

const api = axios.create({
  baseURL: defaultBaseURL,
  headers: { Accept: "application/json", "Content-Type": "application/json" },
});

api.interceptors.request.use((config) => {
  const token = localStorage.getItem("presentechToken");
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error?.response?.status === 401) {
      // Sesi tidak valid/kedaluwarsa: bersihkan seluruh data sesi,
      // lalu beri tahu App agar kembali ke halaman login.
      clearPresenTechSession();
      window.dispatchEvent(new CustomEvent("presentech:session-expired"));
    }

    if (error?.response?.status === 428) {
      // Server mewajibkan ganti password sementara. Tandai sesi lokal
      // agar gerbang ganti password muncul tanpa menunggu login ulang.
      try {
        const user = JSON.parse(localStorage.getItem("presentechUser") || "{}");
        user.must_change_password = true;
        localStorage.setItem("presentechUser", JSON.stringify(user));
      } catch {
        // Data sesi rusak: biarkan App memulai ulang dari halaman login.
      }
      window.dispatchEvent(new CustomEvent("presentech:password-change-required"));
    }

    return Promise.reject(error);
  }
);

export default api;
