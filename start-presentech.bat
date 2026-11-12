@echo off
rem Jalankan backend + discovery + scheduler + tunnel publik PresenTech.
rem Pastikan MySQL Laragon sudah menyala lebih dulu.

rem Pindah ke folder backend relatif terhadap lokasi file .bat ini.
cd /d "%~dp0PresenTech-Backend"

rem Pastikan konfigurasi tidak memakai cache lama (mis. setelah .env diubah
rem atau setelah menjalankan config:cache). Cache lama bisa membuat .env
rem diabaikan dan test memakai database aplikasi.
php artisan config:clear >nul 2>&1

start "PresenTech API 8000" php artisan serve --host=0.0.0.0 --port=8000
rem Discovery otomatis memakai IP yang satu jalur dengan alat. Bila komputer
rem punya beberapa IP, bisa dipaksa: php artisan devices:discovery --ip=192.168.1.5
start "PresenTech Discovery UDP 8888" php artisan devices:discovery
start "PresenTech Scheduler" php artisan schedule:work

rem URL https://xxxx.trycloudflare.com muncul di jendela ini.
rem Masukkan URL + /api ke alat lewat portal setup (tahan tombol BOOT saat nyala).
start "PresenTech Tunnel" C:\laragon\bin\cloudflared\cloudflared.exe tunnel --url http://127.0.0.1:8000

echo.
echo Web lokal : http://127.0.0.1:8000/app
echo Web publik: lihat URL di jendela "PresenTech Tunnel" lalu buka ^<url^>/app
echo.
pause