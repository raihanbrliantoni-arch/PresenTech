# Pantau koneksi alat ESP32-RPL-01 secara langsung.
# Pakai setelah alat di-restart: akan mencetak "ALAT TERHUBUNG" begitu
# heartbeat pertama diterima server.
#
# Jalankan:  powershell -ExecutionPolicy Bypass -File scripts\cek-alat.ps1

$ErrorActionPreference = 'Stop'
Set-Location (Join-Path $PSScriptRoot '..\PresenTech-Backend')

Write-Host ""
Write-Host "=== Memantau koneksi alat (Ctrl+C untuk berhenti) ===" -ForegroundColor Cyan
Write-Host "Alat: ESP32-RPL-01 | server: http://192.168.1.6:8000/api" -ForegroundColor DarkGray
Write-Host ""
Write-Host "Kalau alat masih belum tersambung setelah dinyalakan ulang:" -ForegroundColor Yellow
Write-Host "  1. Cabut lalu pasang kembali kabel power alat (tunggu 10 detik)."
Write-Host "  2. Kalau masih diam: tahan tombol BOOT saat menyalakan alat,"
Write-Host "     lalu isi alamat server http://192.168.1.6:8000/api di portal setup."
Write-Host ""

php artisan tinker ..\scripts\cek-alat-probe.php
