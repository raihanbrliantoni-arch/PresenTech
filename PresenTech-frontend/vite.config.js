import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig(({ command }) => ({
  // Build produksi disajikan Laravel di /app, jadi aset harus memakai prefix
  // /app/ agar tidak bentrok dengan root Laravel.
  base: command === 'build' ? '/app/' : '/',
  plugins: [react()],
  // Host ke semua interface: web PresenTech bisa dibuka dari HP/laptop lain
  // lewat IP LAN saat UAT (mis. http://192.168.1.8:5173).
  server: { host: true },
  preview: { host: true },
}))
